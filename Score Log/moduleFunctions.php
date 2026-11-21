<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

function scoreLogT($text, array $params = []): string
{
    global $session;

    if (function_exists('__m') && is_object($session) && method_exists($session, 'has') && $session->has('module')) {
        $translated = __m($text, $params);

        return $translated !== $text ? $translated : __($text, $params);
    }

    return __($text, $params);
}

/**
 * Users who may target any class, in addition to their own teaching classes.
 */
function scoreLogCanViewAllClasses($gibbonPersonID): bool
{
    global $guid, $connection2;

    if (function_exists('canViewAllAssessmentClasses') && canViewAllAssessmentClasses($gibbonPersonID)) {
        return true;
    }

    if (empty($guid) || empty($connection2)) {
        return false;
    }

    $highest = getHighestGroupedAction(
        $guid,
        '/modules/Formal Assessment/internalAssessment_write_data.php',
        $connection2
    );

    return is_string($highest) && substr($highest, -4) === '_all';
}

/**
 * Default assessment scale, with the school's historical fallback.
 */
function scoreLogDefaultScaleID($session): string
{
    $scaleID = $session->get('defaultAssessmentScale');

    return !empty($scaleID) ? (string) $scaleID : '00026';
}

/**
 * Reads the maximum score from a scale usage such as "满分100分".
 */
function scoreLogMaxScoreFromUsage($usage): ?float
{
    $usage = trim((string) $usage);
    $patterns = [
        '/满分\s*[:：]?\s*(\d+(?:\.\d+)?)\s*分?/u',
        '/(\d+(?:\.\d+)?)\s*分制/u',
        '/\b(?:out\s+of|maximum(?:\s+score)?|max(?:imum)?)\s*[:=]?\s*(\d+(?:\.\d+)?)\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $usage, $matches)) {
            $max = (float) $matches[1];

            return is_finite($max) && $max > 0 ? $max : null;
        }
    }

    return null;
}

/**
 * Normalise a configured comma-separated assessment type list.
 */
function scoreLogAllowedTypes($types): array
{
    $types = is_scalar($types) ? (string) $types : '';
    $values = array_map('trim', explode(',', $types));

    return array_values(array_unique(array_filter($values, 'strlen')));
}

/**
 * Normalise a finite decimal score without accepting exponent notation.
 */
function scoreLogNormalizeNumericValue($value): ?string
{
    $value = is_scalar($value) ? trim((string) $value) : '';
    if (!preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $value)) {
        return null;
    }

    $number = (float) $value;
    if (!is_finite($number)) {
        return null;
    }

    $normalized = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    if ($normalized === '-0' || $normalized === '+0') {
        $normalized = '0';
    }

    return strlen($normalized) <= 10 ? $normalized : null;
}

function scoreLogIsValidDate($date): bool
{
    $date = is_scalar($date) ? trim((string) $date) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

/**
 * Default comment shown for each student row.
 */
function scoreLogDefaultComment(string $scaleName): string
{
    return scoreLogT('Grading scale:') . ' ' . $scaleName;
}

function scoreLogCanEditClass($pdo, $gibbonPersonID, $gibbonCourseClassID, $gibbonSchoolYearID): bool
{
    $class = $pdo->selectOne(
        "SELECT cc.gibbonCourseClassID
         FROM gibbonCourseClass cc
         JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
         WHERE cc.gibbonCourseClassID=:gibbonCourseClassID
           AND c.gibbonSchoolYearID=:gibbonSchoolYearID",
        [
            'gibbonCourseClassID' => $gibbonCourseClassID,
            'gibbonSchoolYearID'  => $gibbonSchoolYearID,
        ]
    );
    if (empty($class)) {
        return false;
    }

    if (scoreLogCanViewAllClasses($gibbonPersonID)) {
        return true;
    }

    return !empty($pdo->selectOne(
        "SELECT 1 FROM gibbonCourseClassPerson
         WHERE gibbonCourseClassID=:gibbonCourseClassID
           AND gibbonPersonID=:gibbonPersonID
           AND role IN ('Teacher', 'Assistant', 'Technician')
         LIMIT 1",
        [
            'gibbonCourseClassID' => $gibbonCourseClassID,
            'gibbonPersonID'      => $gibbonPersonID,
        ]
    ));
}

function scoreLogCurrentRoster($pdo, $gibbonCourseClassID): array
{
    return $pdo->select(
        "SELECT gibbonPerson.gibbonPersonID, gibbonPerson.preferredName, gibbonPerson.surname
         FROM gibbonCourseClassPerson
         JOIN gibbonPerson ON gibbonCourseClassPerson.gibbonPersonID=gibbonPerson.gibbonPersonID
         WHERE gibbonCourseClassPerson.gibbonCourseClassID=:gibbonCourseClassID
           AND gibbonCourseClassPerson.reportable='Y'
           AND gibbonCourseClassPerson.role='Student'
           AND gibbonPerson.status='Full'
           AND (gibbonPerson.dateStart IS NULL OR gibbonPerson.dateStart<=:today)
           AND (gibbonPerson.dateEnd IS NULL OR gibbonPerson.dateEnd>=:today)
         ORDER BY gibbonPerson.surname, gibbonPerson.preferredName",
        [
            'gibbonCourseClassID' => $gibbonCourseClassID,
            'today'               => date('Y-m-d'),
        ]
    )->fetchAll();
}

function scoreLogLoadDraft($pdo, $scoreLogDraftID, $gibbonPersonID): ?array
{
    $draft = $pdo->selectOne(
        "SELECT d.*, CONCAT(course.nameShort, '.', cc.nameShort) AS className,
                scale.name AS scaleName, scale.`usage` AS scaleUsage, scale.numeric AS scaleNumeric,
                course.gibbonSchoolYearID
         FROM score_log_draft d
         JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=d.gibbonCourseClassID
         JOIN gibbonCourse course ON course.gibbonCourseID=cc.gibbonCourseID
         JOIN gibbonScale scale ON scale.gibbonScaleID=d.gibbonScaleIDAttainment
         WHERE d.scoreLogDraftID=:scoreLogDraftID
           AND d.gibbonPersonIDCreator=:gibbonPersonID
           AND scale.active='Y'
         LIMIT 1",
        [
            'scoreLogDraftID' => $scoreLogDraftID,
            'gibbonPersonID'  => $gibbonPersonID,
        ]
    );

    if (empty($draft)) {
        return null;
    }

    $payload = json_decode((string) $draft['payload'], true);
    if (!is_array($payload) || ($payload['version'] ?? null) !== 1) {
        return null;
    }

    $draft['payloadData'] = $payload;

    return $draft;
}

function scoreLogInputString(array $input, string $key): string
{
    return is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
}

/**
 * Capture every submitted draft value without making the draft depend on the
 * current row order. Student IDs are the stable form-field keys.
 */
function scoreLogBuildDraftPayload(array $input, array $roster): array
{
    $students = [];
    $studentNames = [];

    foreach ($roster as $student) {
        $studentID = (string) ($student['gibbonPersonID'] ?? '');
        if ($studentID === '') {
            continue;
        }

        $students[$studentID] = [
            'attainmentValue' => scoreLogInputString($input, 'score-'.$studentID),
            'comment'         => scoreLogInputString($input, 'comment-'.$studentID),
        ];
        $studentNames[$studentID] = trim(
            (string) ($student['preferredName'] ?? '').' '.(string) ($student['surname'] ?? '')
        );
    }

    return [
        'version' => 1,
        'meta' => [
            'name'             => scoreLogInputString($input, 'name'),
            'description'      => scoreLogInputString($input, 'description'),
            'type'             => scoreLogInputString($input, 'type'),
            'viewableStudents' => scoreLogInputString($input, 'viewableStudents'),
            'viewableParents'  => scoreLogInputString($input, 'viewableParents'),
            'completeDate'     => scoreLogInputString($input, 'completeDate'),
        ],
        'students'     => $students,
        'studentNames' => $studentNames,
        'rosterIDs'    => array_keys($students),
    ];
}

/**
 * Validate a draft against the current roster and scale.
 */
function scoreLogEvaluateDraftPayload(array $payload, array $roster, array $scale, array $gradeDescriptors, array $allowedTypes): array
{
    $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
    $savedStudents = is_array($payload['students'] ?? null) ? $payload['students'] : [];
    $metaErrors = [];

    $name = trim((string) ($meta['name'] ?? ''));
    $description = trim((string) ($meta['description'] ?? ''));
    $type = trim((string) ($meta['type'] ?? ''));
    $viewableStudents = (string) ($meta['viewableStudents'] ?? '');
    $viewableParents = (string) ($meta['viewableParents'] ?? '');
    $completeDate = trim((string) ($meta['completeDate'] ?? ''));
    $typeOptions = $allowedTypes ?: ['Assessment'];

    if ($name === '') {
        $metaErrors['name'] = scoreLogT('Enter an assessment name.');
    } elseif (mb_strlen($name) > 30) {
        $metaErrors['name'] = scoreLogT('The assessment name must be 30 characters or fewer.');
    }
    if (mb_strlen($description) > 1000) {
        $metaErrors['description'] = scoreLogT('The description must be 1000 characters or fewer.');
    }
    if (!in_array($type, $typeOptions, true)) {
        $metaErrors['type'] = scoreLogT('Choose a valid assessment type.');
    }
    if (!in_array($viewableStudents, ['Y', 'N'], true)) {
        $metaErrors['viewableStudents'] = scoreLogT('Choose a valid student visibility setting.');
    }
    if (!in_array($viewableParents, ['Y', 'N'], true)) {
        $metaErrors['viewableParents'] = scoreLogT('Choose a valid parent visibility setting.');
    }
    if (!scoreLogIsValidDate($completeDate)) {
        $metaErrors['completeDate'] = scoreLogT('Choose a valid assessment date.');
    }

    $rows = [];
    $entered = 0;
    $missing = 0;
    $invalid = 0;
    $maxScore = scoreLogMaxScoreFromUsage($scale['usage'] ?? '');

    foreach ($roster as $student) {
        $studentID = (string) ($student['gibbonPersonID'] ?? '');
        if ($studentID === '') {
            continue;
        }

        $saved = is_array($savedStudents[$studentID] ?? null) ? $savedStudents[$studentID] : [];
        $value = trim((string) ($saved['attainmentValue'] ?? ''));
        $rawValue = $value;
        $normalizedValue = '';
        $comment = trim((string) ($saved['comment'] ?? ''));
        $errors = [];
        $descriptor = '';

        if (mb_strlen($comment) > 1000) {
            $errors['comment'] = scoreLogT('The comment must be 1000 characters or fewer.');
        }

        if ($value === '') {
            $status = 'missing';
        } elseif (($scale['numeric'] ?? 'N') === 'Y') {
            $value = scoreLogNormalizeNumericValue($value);
            if ($value === null) {
                $errors['score'] = scoreLogT('Enter a valid finite number.');
            } elseif ((float) $value < 0) {
                $errors['score'] = scoreLogT('Enter a score of 0 or greater.');
            } elseif ($maxScore !== null && (float) $value > $maxScore) {
                $errors['score'] = sprintf(scoreLogT('Enter a score from 0 to %s.'), $maxScore);
            }
            if (empty($errors)) {
                $normalizedValue = $value;
                $descriptor = $gradeDescriptors[$value] ?? $value;
            }
        } else {
            if (!isset($gradeDescriptors[$value])) {
                $errors['score'] = scoreLogT('Choose a grade from the selected scale.');
            } else {
                $normalizedValue = $value;
                $descriptor = $gradeDescriptors[$value];
            }
        }

        if (!empty($errors)) {
            $status = 'invalid';
            $invalid++;
        } elseif ($value === '') {
            $status = 'missing';
            $missing++;
        } else {
            $status = 'entered';
            $entered++;
        }

        $rows[] = [
            'gibbonPersonID' => $studentID,
            'name'           => trim((string) ($student['preferredName'] ?? '').' '.(string) ($student['surname'] ?? '')),
            'attainmentValue'=> $rawValue,
            'normalizedValue'=> $normalizedValue,
            'descriptor'     => $descriptor,
            'comment'        => $comment,
            'status'         => $status,
            'errors'         => $errors,
        ];
    }

    return [
        'valid'      => empty($metaErrors) && $invalid === 0 && !empty($rows),
        'meta'       => [
            'name'             => $name,
            'description'      => $description,
            'type'             => $type,
            'viewableStudents' => $viewableStudents,
            'viewableParents'  => $viewableParents,
            'completeDate'     => $completeDate,
        ],
        'metaErrors' => $metaErrors,
        'rows'       => $rows,
        'entered'    => $entered,
        'missing'    => $missing,
        'invalid'    => $invalid,
    ];
}

function scoreLogRosterIDs(array $roster): array
{
    $ids = [];
    foreach ($roster as $student) {
        $studentID = (string) ($student['gibbonPersonID'] ?? '');
        if ($studentID !== '') {
            $ids[] = $studentID;
        }
    }
    sort($ids, SORT_STRING);

    return array_values(array_unique($ids));
}

function scoreLogRosterHash(array $roster): string
{
    return hash('sha256', implode(',', scoreLogRosterIDs($roster)));
}

/**
 * Keep the original roster snapshot while editing a draft. Saving scores after
 * a roster change must not hide the change from the review step.
 */
function scoreLogPreserveRosterBaseline(array $payload, array $previousPayload): array
{
    if (isset($previousPayload['rosterIDs']) && is_array($previousPayload['rosterIDs'])) {
        $payload['rosterIDs'] = array_values(array_map('strval', $previousPayload['rosterIDs']));
    }
    if (isset($previousPayload['studentNames']) && is_array($previousPayload['studentNames'])) {
        $payload['studentNames'] = $previousPayload['studentNames'];
    }

    return $payload;
}

function scoreLogRosterDiff(array $payload, array $roster): array
{
    $savedIDs = is_array($payload['rosterIDs'] ?? null) ? array_map('strval', $payload['rosterIDs']) : [];
    $currentIDs = scoreLogRosterIDs($roster);
    $savedNames = is_array($payload['studentNames'] ?? null) ? $payload['studentNames'] : [];

    $currentNames = [];
    foreach ($roster as $student) {
        $currentNames[(string) $student['gibbonPersonID']] = trim(
            (string) ($student['preferredName'] ?? '').' '.(string) ($student['surname'] ?? '')
        );
    }

    $added = [];
    foreach (array_diff($currentIDs, $savedIDs) as $studentID) {
        $added[$studentID] = $currentNames[$studentID] ?? $studentID;
    }

    $removed = [];
    foreach (array_diff($savedIDs, $currentIDs) as $studentID) {
        $removed[$studentID] = (string) ($savedNames[$studentID] ?? $studentID);
    }

    return [
        'added'   => $added,
        'removed' => $removed,
        'changed' => !empty($added) || !empty($removed),
    ];
}
