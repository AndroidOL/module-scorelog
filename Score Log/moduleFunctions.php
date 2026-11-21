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

function scoreLogScaleGradeNumericValue($value): ?string
{
    $value = is_scalar($value) ? trim((string) $value) : '';
    if (str_ends_with($value, '%')) {
        $value = rtrim(substr($value, 0, -1));
    }

    return scoreLogNormalizeNumericValue($value);
}

function scoreLogResolveScaleGrade($value, array $scale, array $gradeDescriptors): ?array
{
    $value = is_scalar($value) ? trim((string) $value) : '';

    if (($scale['numeric'] ?? 'N') === 'Y') {
        $numericValue = scoreLogScaleGradeNumericValue($value);
        if ($numericValue === null) {
            return null;
        }

        foreach ($gradeDescriptors as $gradeValue => $descriptor) {
            $gradeNumericValue = scoreLogScaleGradeNumericValue($gradeValue);
            if ($gradeNumericValue !== null && $gradeNumericValue === $numericValue) {
                return [
                    'value' => (string) $gradeValue,
                    'descriptor' => (string) $descriptor,
                ];
            }
        }

        return null;
    }

    if (!array_key_exists($value, $gradeDescriptors)) {
        return null;
    }

    return [
        'value' => $value,
        'descriptor' => (string) $gradeDescriptors[$value],
    ];
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
    global $guid, $connection2;

    if (
        empty($guid)
        || empty($connection2)
        || !isActionAccessible(
            $guid,
            $connection2,
            '/modules/Formal Assessment/internalAssessment_write_data.php'
        )
    ) {
        return false;
    }

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
           AND role='Teacher'
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
        "SELECT gibbonPerson.gibbonPersonID, gibbonPerson.studentID, gibbonPerson.username,
                gibbonPerson.preferredName, gibbonPerson.surname
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
                scale.name AS scaleName, scale.`usage` AS scaleUsage, scale.`numeric` AS scaleNumeric,
                course.gibbonSchoolYearID
         FROM score_log_draft d
         JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=d.gibbonCourseClassID
         JOIN gibbonCourse course ON course.gibbonCourseID=cc.gibbonCourseID
         LEFT JOIN gibbonScale scale ON scale.gibbonScaleID=d.gibbonScaleIDAttainment
         WHERE d.scoreLogDraftID=:scoreLogDraftID
           AND d.gibbonPersonIDCreator=:gibbonPersonID
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
        if (!is_array($payload) || ($payload['version'] ?? null) !== 2) {
            return null;
        }
    }

    $draft['payloadData'] = scoreLogUpgradeDraftPayload($payload, (string) $draft['gibbonScaleIDAttainment']);

    return $draft;
}

function scoreLogInputString(array $input, string $key): string
{
    return is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
}

function scoreLogValidateComment(array $input): array
{
    $label = scoreLogInputString($input, 'label');
    $comment = is_scalar($input['comment'] ?? null) ? trim((string) $input['comment']) : '';

    return [
        'valid' => $label !== ''
            && mb_strlen($label) <= 80
            && $comment !== ''
            && mb_strlen($comment) <= 1000,
        'label' => $label,
        'comment' => $comment,
    ];
}

function scoreLogSamePersonID($left, $right): bool
{
    if (!is_scalar($left) || !is_scalar($right)
        || !ctype_digit((string) $left) || !ctype_digit((string) $right)) {
        return false;
    }

    return (int) $left === (int) $right;
}

function scoreLogUpgradeDraftPayload(array $payload, $legacyScaleID): array
{
    if (($payload['version'] ?? 1) === 2 && !empty($payload['columns']) && !empty($payload['columnOrder'])) {
        return $payload;
    }

    $columnID = 'legacy';
    $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
    $students = [];
    foreach ((array) ($payload['students'] ?? []) as $studentID => $student) {
        $entries = is_array($student) ? $student : [];
        $students[(string) $studentID] = [
            'scores' => [
                $columnID => [
                    'attainmentValue' => (string) ($entries['attainmentValue'] ?? ''),
                    'comment'         => (string) ($entries['comment'] ?? ''),
                ],
            ],
        ];
    }

    return [
        'version'     => 2,
        'columns'     => [
            $columnID => [
                'name'             => (string) ($meta['name'] ?? ''),
                'description'      => (string) ($meta['description'] ?? ''),
                'type'             => (string) ($meta['type'] ?? ''),
                'scaleID'          => (string) $legacyScaleID,
                'completeDate'     => (string) ($meta['completeDate'] ?? ''),
                'viewableStudents' => (string) ($meta['viewableStudents'] ?? ''),
                'viewableParents'  => (string) ($meta['viewableParents'] ?? ''),
            ],
        ],
        'columnOrder' => [$columnID],
        'students'    => $students,
        'studentNames'=> (array) ($payload['studentNames'] ?? []),
        'rosterIDs'   => (array) ($payload['rosterIDs'] ?? []),
    ];
}

function scoreLogDraftScaleLabels(array $payload, array $scaleNames): string
{
    $labels = [];
    foreach ((array) ($payload['columnOrder'] ?? []) as $columnID) {
        $scaleID = (string) ($payload['columns'][$columnID]['scaleID'] ?? '');
        if ($scaleID !== '') {
            $labels[$scaleID] = (string) ($scaleNames[$scaleID] ?? $scaleID);
        }
    }

    return implode(', ', array_values($labels));
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

function scoreLogBuildMultiAssessmentPayload(array $input, array $roster): array
{
    $columnOrder = is_scalar($input['columnOrder'] ?? null)
        ? preg_split('/[,;]+/', (string) $input['columnOrder'], -1, PREG_SPLIT_NO_EMPTY)
        : [];
    $columnOrder = array_values(array_unique(array_filter(array_map('trim', $columnOrder), function ($columnID) {
        return preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $columnID) === 1;
    })));
    $columnOrder = array_slice($columnOrder, 0, 10);

    $columns = [];
    foreach ($columnOrder as $columnID) {
        $column = is_array($input['columns'][$columnID] ?? null) ? $input['columns'][$columnID] : [];
        $columns[$columnID] = [
            'name'             => trim((string) ($column['name'] ?? '')),
            'description'      => trim((string) ($column['description'] ?? '')),
            'type'             => trim((string) ($column['type'] ?? '')),
            'scaleID'          => trim((string) ($column['scaleID'] ?? '')),
            'completeDate'     => trim((string) ($column['completeDate'] ?? '')),
            'viewableStudents' => trim((string) ($column['viewableStudents'] ?? '')),
            'viewableParents'  => trim((string) ($column['viewableParents'] ?? '')),
        ];
    }

    $students = [];
    $studentNames = [];
    foreach ($roster as $student) {
        $studentID = (string) ($student['gibbonPersonID'] ?? '');
        if ($studentID === '') {
            continue;
        }
        $studentNames[$studentID] = trim(
            (string) ($student['preferredName'] ?? '').' '.(string) ($student['surname'] ?? '')
        );
        $students[$studentID] = ['scores' => []];
        foreach ($columnOrder as $columnID) {
            $value = is_array($input['scores'][$studentID][$columnID] ?? null)
                ? $input['scores'][$studentID][$columnID]
                : [];
            $students[$studentID]['scores'][$columnID] = [
                'attainmentValue' => trim((string) ($value['attainmentValue'] ?? '')),
                'comment'         => scoreLogNormalizeImportComment($value['comment'] ?? ''),
            ];
        }
    }

    $payload = [
        'version'     => 2,
        'columns'     => $columns,
        'columnOrder' => $columnOrder,
        'students'    => $students,
        'studentNames'=> $studentNames,
        'rosterIDs'   => array_keys($students),
    ];
    $autosaveKey = scoreLogInputString($input, 'autosaveKey');
    $autosaveSequence = is_scalar($input['autosaveSequence'] ?? null)
        ? max(0, (int) $input['autosaveSequence'])
        : 0;
    if (preg_match('/^[a-f0-9]{32}$/D', $autosaveKey)) {
        $payload['autosaveKey'] = $autosaveKey;
        if ($autosaveSequence > 0) {
            $payload['autosaveSequence'] = $autosaveSequence;
        }
    }

    return $payload;
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
    $typeOptions = $allowedTypes;

    if ($name === '') {
        $metaErrors['name'] = scoreLogT('Enter an assessment name.');
    } elseif (mb_strlen($name) > 30) {
        $metaErrors['name'] = scoreLogT('The assessment name must be 30 characters or fewer.');
    }
    if ($description === '') {
        $metaErrors['description'] = scoreLogT('Enter an assessment description.');
    } elseif (mb_strlen($description) > 1000) {
        $metaErrors['description'] = scoreLogT('The description must be 1000 characters or fewer.');
    }
    if (!in_array($type, $typeOptions, true)) {
        $metaErrors['type'] = scoreLogT('Choose a valid assessment type.');
    } elseif (mb_strlen($type) > 50) {
        $metaErrors['type'] = scoreLogT('The assessment type must be 50 characters or fewer.');
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
        } else {
            $score = scoreLogValidateImportScore($value, $scale, $gradeDescriptors);
            if (!$score['valid']) {
                $errors['score'] = $score['error'];
            } else {
                $normalizedValue = $score['normalized'];
                $descriptor = $score['descriptor'];
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

function scoreLogEvaluateMultiAssessmentPayload(array $payload, array $roster, array $scales, array $gradesByScale, array $allowedTypes): array
{
    $columns = is_array($payload['columns'] ?? null) ? $payload['columns'] : [];
    $columnOrder = array_values(array_intersect((array) ($payload['columnOrder'] ?? []), array_keys($columns)));
    $savedStudents = is_array($payload['students'] ?? null) ? $payload['students'] : [];
    $typeOptions = $allowedTypes;
    $errors = [];
    $nameSeen = [];
    $columnResults = [];
    $totals = ['entered' => 0, 'missing' => 0, 'invalid' => 0];

    if (count($columnOrder) !== 1) {
        $errors[] = scoreLogT('A draft must contain one assessment column.');
    }

    foreach ($columnOrder as $columnID) {
        $column = is_array($columns[$columnID] ?? null) ? $columns[$columnID] : [];
        $columnErrors = [];
        $name = trim((string) ($column['name'] ?? ''));
        $description = trim((string) ($column['description'] ?? ''));
        $type = trim((string) ($column['type'] ?? ''));
        $scaleID = trim((string) ($column['scaleID'] ?? ''));
        $completeDate = trim((string) ($column['completeDate'] ?? ''));
        $viewableStudents = (string) ($column['viewableStudents'] ?? '');
        $viewableParents = (string) ($column['viewableParents'] ?? '');
        $nameKey = scoreLogNormalizeImportKey($name);
        $scale = is_array($scales[$scaleID] ?? null) ? $scales[$scaleID] : null;

        if ($name === '') {
            $columnErrors[] = scoreLogT('Enter an assessment name.');
        } elseif (mb_strlen($name) > 30) {
            $columnErrors[] = scoreLogT('The assessment name must be 30 characters or fewer.');
        } elseif ($nameKey !== '' && isset($nameSeen[$nameKey])) {
            $columnErrors[] = scoreLogT('Assessment column names must be unique within the draft.');
        }
        if ($nameKey !== '') {
            $nameSeen[$nameKey] = true;
        }
        if ($description === '') {
            $columnErrors[] = scoreLogT('Enter an assessment description.');
        } elseif (mb_strlen($description) > 1000) {
            $columnErrors[] = scoreLogT('The description must be 1000 characters or fewer.');
        }
        if (!in_array($type, $typeOptions, true)) {
            $columnErrors[] = scoreLogT('Choose a valid assessment type.');
        } elseif (mb_strlen($type) > 50) {
            $columnErrors[] = scoreLogT('The assessment type must be 50 characters or fewer.');
        }
        if (empty($scale)) {
            $columnErrors[] = scoreLogT('Choose an active assessment scale.');
        }
        if (!scoreLogIsValidDate($completeDate)) {
            $columnErrors[] = scoreLogT('Choose a valid assessment date.');
        }
        if (!in_array($viewableStudents, ['Y', 'N'], true)) {
            $columnErrors[] = scoreLogT('Choose a valid student visibility setting.');
        }
        if (!in_array($viewableParents, ['Y', 'N'], true)) {
            $columnErrors[] = scoreLogT('Choose a valid parent visibility setting.');
        }

        $gradeDescriptors = is_array($gradesByScale[$scaleID] ?? null) ? $gradesByScale[$scaleID] : [];
        $rows = [];
        $entered = 0;
        $missing = 0;
        $invalid = 0;
        foreach ($roster as $student) {
            $studentID = (string) ($student['gibbonPersonID'] ?? '');
            $entry = is_array($savedStudents[$studentID]['scores'][$columnID] ?? null)
                ? $savedStudents[$studentID]['scores'][$columnID]
                : [];
            $value = trim((string) ($entry['attainmentValue'] ?? ''));
            $comment = (string) ($entry['comment'] ?? '');
            $rowErrors = [];
            $descriptor = '';
            if (mb_strlen($comment) > 1000) {
                $rowErrors[] = scoreLogT('The comment must be 1000 characters or fewer.');
            }
            if ($value === '') {
                $status = 'missing';
                $missing++;
            } else {
                $score = scoreLogValidateImportScore($value, [
                    'usage' => $scale['usage'] ?? '',
                    'numeric' => $scale['numeric'] ?? 'N',
                ], $gradeDescriptors);
                if (!$score['valid']) {
                    $rowErrors[] = $score['error'];
                    $status = 'invalid';
                    $invalid++;
                } else {
                    $value = $score['normalized'];
                    $descriptor = $score['descriptor'];
                    $status = 'entered';
                    $entered++;
                }
            }
            $rows[] = [
                'gibbonPersonID' => $studentID,
                'name' => trim((string) ($student['preferredName'] ?? '').' '.(string) ($student['surname'] ?? '')),
                'attainmentValue' => $value,
                'descriptor' => $descriptor,
                'comment' => $comment,
                'status' => $status,
                'errors' => $rowErrors,
            ];
        }
        if ($entered === 0) {
            $columnErrors[] = scoreLogT('This assessment column has no entered scores.');
        }

        $columnResults[$columnID] = [
            'id' => $columnID,
            'config' => [
                'name' => $name,
                'description' => $description,
                'type' => $type,
                'scaleID' => $scaleID,
                'scaleName' => $scale['name'] ?? '',
                'usage' => $scale['usage'] ?? '',
                'numeric' => $scale['numeric'] ?? 'N',
                'completeDate' => $completeDate,
                'viewableStudents' => $viewableStudents,
                'viewableParents' => $viewableParents,
            ],
            'rows' => $rows,
            'errors' => array_values(array_unique($columnErrors)),
            'entered' => $entered,
            'missing' => $missing,
            'invalid' => $invalid,
        ];
        $totals['entered'] += $entered;
        $totals['missing'] += $missing;
        $totals['invalid'] += $invalid;
    }

    return [
        'valid' => empty($errors) && $totals['invalid'] === 0 && !empty($columnResults)
            && empty(array_filter($columnResults, function ($column) { return !empty($column['errors']); })),
        'errors' => $errors,
        'columns' => $columnResults,
        'columnOrder' => $columnOrder,
        'totals' => $totals,
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

function scoreLogDraftProgress(array $payload): array
{
    $students = is_array($payload['students'] ?? null) ? $payload['students'] : [];
    if (($payload['version'] ?? 1) === 2) {
        $columns = array_values(array_intersect((array) ($payload['columnOrder'] ?? []), array_keys((array) ($payload['columns'] ?? []))));
        $entered = 0;
        foreach ($students as $student) {
            foreach ($columns as $columnID) {
                if (trim((string) ($student['scores'][$columnID]['attainmentValue'] ?? '')) !== '') {
                    $entered++;
                }
            }
        }

        return ['entered' => $entered, 'total' => count($students) * count($columns)];
    }

    $entered = 0;
    foreach ($students as $student) {
        if (is_array($student) && trim((string) ($student['attainmentValue'] ?? '')) !== '') {
            $entered++;
        }
    }

    return ['entered' => $entered, 'total' => count($students)];
}

function scoreLogCoreAssessmentValues(array $config, $gibbonCourseClassID, $gibbonPersonID, $groupingID): array
{
    $completeDate = trim((string) ($config['completeDate'] ?? ''));
    $complete = scoreLogIsValidDate($completeDate) ? 'Y' : 'N';

    return [
        'groupingID'                  => (int) $groupingID,
        'gibbonCourseClassID'         => (string) $gibbonCourseClassID,
        'name'                        => (string) ($config['name'] ?? ''),
        'description'                 => (string) ($config['description'] ?? ''),
        'type'                        => (string) ($config['type'] ?? ''),
        'attainment'                  => 'Y',
        'gibbonScaleIDAttainment'     => (string) ($config['scaleID'] ?? ''),
        'effort'                      => 'N',
        'gibbonScaleIDEffort'         => null,
        'comment'                     => 'Y',
        'uploadedResponse'            => 'N',
        'complete'                    => $complete,
        'completeDate'                => $complete === 'Y' ? $completeDate : null,
        'viewableStudents'            => (string) ($config['viewableStudents'] ?? ''),
        'viewableParents'             => (string) ($config['viewableParents'] ?? ''),
        'attachment'                  => '',
        'gibbonPersonIDCreator'       => (string) $gibbonPersonID,
        'gibbonPersonIDLastEdit'      => (string) $gibbonPersonID,
    ];
}

function scoreLogCoreEntryValues($gibbonInternalAssessmentColumnID, array $entry, $gibbonPersonID): array
{
    return [
        'gibbonInternalAssessmentColumnID' => (int) $gibbonInternalAssessmentColumnID,
        'gibbonPersonIDStudent'            => (string) ($entry['gibbonPersonID'] ?? ''),
        'attainmentValue'                  => (string) ($entry['attainmentValue'] ?? ''),
        'attainmentDescriptor'             => (string) ($entry['descriptor'] ?? ''),
        'effortValue'                      => null,
        'effortDescriptor'                 => null,
        'comment'                          => (string) ($entry['comment'] ?? ''),
        'response'                         => null,
        'gibbonPersonIDLastEdit'           => (string) $gibbonPersonID,
    ];
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

function scoreLogNormalizeImportText($value): string
{
    $value = is_scalar($value) ? (string) $value : '';
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    $value = str_replace("\xC2\xA0", ' ', $value);

    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function scoreLogNormalizeImportKey($value): string
{
    return mb_strtolower(scoreLogNormalizeImportText($value));
}

function scoreLogNormalizeImportComment($value): string
{
    $value = is_scalar($value) ? (string) $value : '';
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    $value = str_replace("\xC2\xA0", ' ', $value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u', '', $value) ?? $value;

    return trim($value);
}

function scoreLogNormalizeImportIdentifier($value): string
{
    $value = scoreLogNormalizeImportText($value);
    if (preg_match('/^\d+$/D', $value)) {
        $value = ltrim($value, '0');

        return $value === '' ? '0' : $value;
    }

    return mb_strtolower($value);
}

function scoreLogImportNameVariants(array $student): array
{
    $preferred = scoreLogNormalizeImportKey($student['preferredName'] ?? '');
    $surname = scoreLogNormalizeImportKey($student['surname'] ?? '');
    if ($preferred === '' || $surname === '') {
        return [];
    }

    return array_values(array_unique([
        scoreLogNormalizeImportKey($preferred.' '.$surname),
        scoreLogNormalizeImportKey($surname.' '.$preferred),
        scoreLogNormalizeImportKey($preferred.$surname),
        scoreLogNormalizeImportKey($surname.$preferred),
        scoreLogNormalizeImportKey($surname.', '.$preferred),
        scoreLogNormalizeImportKey($surname.','.$preferred),
    ]));
}

function scoreLogImportRosterIndexes(array $roster): array
{
    $indexes = [
        'id'       => [],
        'username' => [],
        'name'     => [],
    ];

    foreach ($roster as $student) {
        $gibbonPersonID = (string) ($student['gibbonPersonID'] ?? '');
        if ($gibbonPersonID === '') {
            continue;
        }

        foreach ([$student['studentID'] ?? '', $gibbonPersonID] as $id) {
            $key = scoreLogNormalizeImportIdentifier($id);
            if ($key !== '') {
                $indexes['id'][$key] = array_key_exists($key, $indexes['id']) && $indexes['id'][$key] !== $gibbonPersonID
                    ? null
                    : $gibbonPersonID;
            }
        }

        $username = scoreLogNormalizeImportKey($student['username'] ?? '');
        if ($username !== '') {
            $indexes['username'][$username] = array_key_exists($username, $indexes['username']) && $indexes['username'][$username] !== $gibbonPersonID
                ? null
                : $gibbonPersonID;
        }

        foreach (scoreLogImportNameVariants($student) as $name) {
            $indexes['name'][$name] = array_key_exists($name, $indexes['name']) && $indexes['name'][$name] !== $gibbonPersonID
                ? null
                : $gibbonPersonID;
        }
    }

    return $indexes;
}

function scoreLogImportHeaderType($value): ?string
{
    $key = scoreLogNormalizeImportKey($value);
    $headers = [
        'id' => ['id', 'student id', 'studentid', 'student number', '学号', '学生编号', '编号'],
        'username' => ['username', 'user name', 'login', 'login name', '用户名', '账号', '登录名'],
        'name' => ['name', 'student', 'student name', 'full name', '姓名', '学生姓名'],
        'score' => ['score', 'mark', 'marks', 'grade', 'result', '成绩', '分数', '得分'],
        'comment' => ['comment', 'comments', 'note', 'notes', 'remark', 'remarks', '评语', '备注'],
    ];

    foreach ($headers as $type => $labels) {
        if (in_array($key, $labels, true)) {
            return $type;
        }
    }

    return null;
}

function scoreLogDetectImportHeader(array $row): ?array
{
    $identifier = null;
    $scoreColumn = null;
    $commentColumn = null;

    foreach (array_values($row) as $column => $value) {
        $type = scoreLogImportHeaderType($value);
        if (in_array($type, ['id', 'username', 'name'], true)) {
            if ($identifier === null || ($type === 'id' && $identifier['type'] !== 'id')) {
                $identifier = ['column' => $column, 'type' => $type];
            }
        } elseif ($type === 'score' && $scoreColumn === null) {
            $scoreColumn = $column;
        } elseif ($type === 'comment' && $commentColumn === null) {
            $commentColumn = $column;
        }
    }

    if ($identifier === null || $scoreColumn === null || $identifier['column'] === $scoreColumn) {
        return null;
    }

    return [
        'headerRow'     => 0,
        'identifierColumn' => $identifier['column'],
        'identifierType'   => $identifier['type'],
        'scoreColumn'      => $scoreColumn,
        'commentColumn'    => $commentColumn,
    ];
}

function scoreLogValidateImportScore($value, array $scale, array $gradeDescriptors): array
{
    $value = scoreLogNormalizeImportText($value);
    if ($value === '') {
        return ['valid' => true, 'normalized' => '', 'descriptor' => '', 'error' => ''];
    }

    if (($scale['numeric'] ?? 'N') === 'Y') {
        $normalized = scoreLogScaleGradeNumericValue($value);
        if ($normalized === null) {
            return ['valid' => false, 'normalized' => '', 'descriptor' => '', 'error' => scoreLogT('Enter a valid finite number.')];
        }
        $maxScore = scoreLogMaxScoreFromUsage($scale['usage'] ?? '');
        if ((float) $normalized < 0) {
            return ['valid' => false, 'normalized' => '', 'descriptor' => '', 'error' => scoreLogT('Enter a score of 0 or greater.')];
        }
        if ($maxScore !== null && (float) $normalized > $maxScore) {
            return [
                'valid' => false,
                'normalized' => '',
                'descriptor' => '',
                'error' => sprintf(scoreLogT('Enter a score from 0 to %s.'), $maxScore),
            ];
        }

        $grade = scoreLogResolveScaleGrade($normalized, $scale, $gradeDescriptors);
        if ($grade === null) {
            return [
                'valid' => false,
                'normalized' => '',
                'descriptor' => '',
                'error' => scoreLogT('Choose a score from the selected scale.'),
            ];
        }

        return [
            'valid'      => true,
            'normalized' => $grade['value'],
            'descriptor' => $grade['descriptor'],
            'error'      => '',
        ];
    }

    $grade = scoreLogResolveScaleGrade($value, $scale, $gradeDescriptors);
    if ($grade === null) {
        return [
            'valid' => false,
            'normalized' => '',
            'descriptor' => '',
            'error' => scoreLogT('Choose a grade from the selected scale.'),
        ];
    }

    return [
        'valid'      => true,
        'normalized' => $grade['value'],
        'descriptor' => $grade['descriptor'],
        'error'      => '',
    ];
}

function scoreLogDetectImportMapping(array $matrix, array $roster, array $scale, array $gradeDescriptors): array
{
    $matrix = array_values($matrix);
    $header = null;
    $headerRow = null;
    foreach ($matrix as $index => $row) {
        $candidate = scoreLogDetectImportHeader($row);
        if ($candidate !== null) {
            $header = $candidate;
            $headerRow = $index;
            break;
        }
    }
    $rows = $headerRow === null ? $matrix : array_slice($matrix, $headerRow + 1);
    $columnCount = 0;
    foreach ($matrix as $row) {
        $columnCount = max($columnCount, count($row));
    }
    $indexes = scoreLogImportRosterIndexes($roster);
    $identifierStats = [];
    $scoreStats = [];

    foreach ($rows as $row) {
        for ($column = 0; $column < $columnCount; $column++) {
            $value = scoreLogNormalizeImportText($row[$column] ?? '');
            if ($value === '') {
                continue;
            }
            foreach (['id', 'username', 'name'] as $type) {
                $identifierStats[$column][$type]['total'] = ($identifierStats[$column][$type]['total'] ?? 0) + 1;
                $key = $type === 'id'
                    ? scoreLogNormalizeImportIdentifier($value)
                    : scoreLogNormalizeImportKey($value);
                if (isset($indexes[$type][$key]) && $indexes[$type][$key] !== null) {
                    $identifierStats[$column][$type]['matched'] = ($identifierStats[$column][$type]['matched'] ?? 0) + 1;
                }
            }

            $scoreStats[$column]['total'] = ($scoreStats[$column]['total'] ?? 0) + 1;
            $score = scoreLogValidateImportScore($value, $scale, $gradeDescriptors);
            if ($score['valid']) {
                $scoreStats[$column]['valid'] = ($scoreStats[$column]['valid'] ?? 0) + 1;
            }
        }
    }

    $mapping = [
        'identifierColumn' => null,
        'identifierType'   => '',
        'scoreColumn'      => null,
        'commentColumn'    => null,
        'headerRow'        => $headerRow,
        'headerDetected'   => $header !== null,
        'confidence'       => [],
    ];

    if ($header !== null) {
        $mapping = array_replace($mapping, [
            'identifierColumn' => $header['identifierColumn'],
            'identifierType'   => $header['identifierType'],
            'scoreColumn'      => $header['scoreColumn'],
            'commentColumn'    => $header['commentColumn'],
        ]);
    } else {
        $priority = ['id' => 3, 'username' => 2, 'name' => 1];
        $best = null;
        $tie = false;

        foreach ($identifierStats as $identifierColumn => $types) {
            foreach ($types as $type => $stats) {
                foreach ($scoreStats as $scoreColumn => $scores) {
                    if ($identifierColumn === $scoreColumn || empty($stats['total']) || empty($scores['total'])) {
                        continue;
                    }

                    $identifierRatio = ($stats['matched'] ?? 0) / $stats['total'];
                    $scoreRatio = ($scores['valid'] ?? 0) / $scores['total'];
                    if ($identifierRatio < 0.5 || $scoreRatio < 0.5) {
                        continue;
                    }

                    $rank = [
                        round($identifierRatio * $scoreRatio, 9),
                        round($identifierRatio, 9),
                        round($scoreRatio, 9),
                        $priority[$type],
                        -$identifierColumn,
                        -$scoreColumn,
                    ];
                    if ($best === null || $rank > $best['rank']) {
                        $best = [
                            'rank' => $rank,
                            'identifierColumn' => $identifierColumn,
                            'identifierType' => $type,
                            'scoreColumn' => $scoreColumn,
                        ];
                        $tie = false;
                    } elseif ($rank === $best['rank']) {
                        $tie = true;
                    }
                }
            }
        }

        if ($best !== null && !$tie) {
            $mapping['identifierColumn'] = $best['identifierColumn'];
            $mapping['identifierType'] = $best['identifierType'];
            $mapping['scoreColumn'] = $best['scoreColumn'];

            $remaining = array_values(array_diff(range(0, max(0, $columnCount - 1)), [
                $mapping['identifierColumn'],
                $mapping['scoreColumn'],
            ]));
            if ($columnCount === 3 && count($remaining) === 1 && ($scoreStats[$remaining[0]]['total'] ?? 0) > 0) {
                $mapping['commentColumn'] = $remaining[0];
            }
        }
    }

    if ($mapping['identifierColumn'] !== null && $mapping['scoreColumn'] !== null) {
        $idColumn = (int) $mapping['identifierColumn'];
        $idType = (string) $mapping['identifierType'];
        $scoreColumn = (int) $mapping['scoreColumn'];
        $mapping['confidence'] = [
            [
                'column'  => $idColumn,
                'role'    => $idType,
                'matched' => (int) ($identifierStats[$idColumn][$idType]['matched'] ?? 0),
                'total'   => (int) ($identifierStats[$idColumn][$idType]['total'] ?? 0),
            ],
            [
                'column'  => $scoreColumn,
                'role'    => 'score',
                'matched' => (int) ($scoreStats[$scoreColumn]['valid'] ?? 0),
                'total'   => (int) ($scoreStats[$scoreColumn]['total'] ?? 0),
            ],
        ];
    }

    $mapping['columnCount'] = $columnCount;

    return $mapping;
}

function scoreLogBuildImportPreview(array $matrix, array $mapping, array $payload, array $roster, array $scale, array $gradeDescriptors, array $formulaCells = []): array
{
    $indexes = scoreLogImportRosterIndexes($roster);
    $headerRow = $mapping['headerRow'] ?? null;
    $identifierColumn = isset($mapping['identifierColumn']) ? (int) $mapping['identifierColumn'] : null;
    $identifierType = in_array($mapping['identifierType'] ?? '', ['id', 'username', 'name'], true)
        ? $mapping['identifierType']
        : '';
    $scoreColumn = isset($mapping['scoreColumn']) ? (int) $mapping['scoreColumn'] : null;
    $commentColumn = isset($mapping['commentColumn']) && $mapping['commentColumn'] !== ''
        ? (int) $mapping['commentColumn']
        : null;
    $savedStudents = is_array($payload['students'] ?? null) ? $payload['students'] : [];
    $rows = [];
    $resolvedCounts = [];

    foreach ($matrix as $rowIndex => $row) {
        if ($headerRow !== null && $rowIndex <= $headerRow) {
            continue;
        }

        $identifier = $identifierColumn === null ? '' : scoreLogNormalizeImportText($row[$identifierColumn] ?? '');
        $scoreValue = $scoreColumn === null ? '' : scoreLogNormalizeImportText($row[$scoreColumn] ?? '');
        $comment = $commentColumn === null ? '' : scoreLogNormalizeImportComment($row[$commentColumn] ?? '');
        if ($identifier === '' && $scoreValue === '' && $comment === '') {
            continue;
        }

        $errors = [];
        $errorTypes = [];
        $studentID = null;
        if ($identifierColumn === null || $identifierType === '' || $scoreColumn === null) {
            $errors[] = scoreLogT('Choose how the identifier and score columns should be mapped.');
            $errorTypes[] = 'mapping';
        } else {
            $key = $identifierType === 'id'
                ? scoreLogNormalizeImportIdentifier($identifier)
                : scoreLogNormalizeImportKey($identifier);
            if (!array_key_exists($key, $indexes[$identifierType])) {
                $errors[] = scoreLogT('The student identifier is unknown.');
                $errorTypes[] = 'unknown';
            } elseif ($indexes[$identifierType][$key] === null) {
                $errors[] = scoreLogT('The student identifier is ambiguous.');
                $errorTypes[] = 'ambiguous';
            } else {
                $studentID = (string) $indexes[$identifierType][$key];
                $resolvedCounts[$studentID] = ($resolvedCounts[$studentID] ?? 0) + 1;
            }
        }

        foreach (array_filter([$identifierColumn, $scoreColumn, $commentColumn], function ($column) {
            return $column !== null;
        }) as $column) {
            if (!empty($formulaCells[$rowIndex.':'.$column])) {
                $errors[] = scoreLogT('Formula cells cannot be imported.');
                $errorTypes[] = 'formula';
                break;
            }
        }

        $score = scoreLogValidateImportScore($scoreValue, $scale, $gradeDescriptors);
        if (!$score['valid']) {
            $errors[] = $score['error'];
            $errorTypes[] = 'score';
        }
        if (mb_strlen($comment) > 1000) {
            $errors[] = scoreLogT('The comment must be 1000 characters or fewer.');
            $errorTypes[] = 'comment';
        }

        $existing = $studentID !== null && is_array($savedStudents[$studentID] ?? null)
            ? $savedStudents[$studentID]
            : [];
        $existingScore = trim((string) ($existing['attainmentValue'] ?? ''));
        $existingComment = trim((string) ($existing['comment'] ?? ''));

        $rows[] = [
            'rowNumber'      => $rowIndex + 1,
            'identifier'     => $identifier,
            'studentID'      => $studentID,
            'studentName'    => $studentID !== null ? scoreLogImportStudentName($roster, $studentID) : '',
            'importScore'    => $scoreValue,
            'normalizedScore'=> $score['normalized'],
            'importComment'  => $comment,
            'existingScore'  => $existingScore,
            'existingComment'=> $existingComment,
            'errors'         => array_values(array_unique($errors)),
            'errorTypes'     => array_values(array_unique($errorTypes)),
            'status'         => '',
        ];
    }

    $counts = [
        'total' => count($rows),
        'matched' => 0,
        'wouldFill' => 0,
        'preserved' => 0,
        'wouldOverwrite' => 0,
        'unknown' => 0,
        'ambiguous' => 0,
        'duplicate' => 0,
        'invalid' => 0,
    ];
    $updates = [];

    foreach ($rows as &$row) {
        if (!empty($row['errors'])) {
            $row['status'] = in_array('unknown', $row['errorTypes'], true)
                ? 'unknown'
                : (in_array('ambiguous', $row['errorTypes'], true) ? 'ambiguous' : 'invalid');
            $counts[$row['status']]++;
            continue;
        }

        $counts['matched']++;
        if (($resolvedCounts[$row['studentID']] ?? 0) > 1) {
            $row['status'] = 'duplicate';
            $counts['duplicate']++;
            continue;
        }

        $changes = [];
        $overwrites = [];
        $preserved = false;
        if ($row['normalizedScore'] !== '') {
            if ($row['existingScore'] === '') {
                $changes['attainmentValue'] = $row['normalizedScore'];
            } elseif ($row['existingScore'] !== $row['normalizedScore']) {
                $changes['attainmentValue'] = $row['normalizedScore'];
                $overwrites[] = scoreLogImportRoleLabel('score');
            } else {
                $preserved = true;
            }
        }

        if ($commentColumn !== null && $row['importComment'] !== '') {
            if ($row['existingComment'] === '') {
                $changes['comment'] = $row['importComment'];
            } elseif ($row['existingComment'] !== $row['importComment']) {
                $changes['comment'] = $row['importComment'];
                $overwrites[] = scoreLogImportRoleLabel('comment');
            } else {
                $preserved = true;
            }
        }

        if (!empty($overwrites)) {
            $row['status'] = 'wouldOverwrite';
            $counts['wouldOverwrite']++;
        } elseif (!empty($changes)) {
            $row['status'] = 'wouldFill';
            $counts['wouldFill']++;
        } elseif ($preserved) {
            $row['status'] = 'preserved';
            $counts['preserved']++;
        } else {
            $row['status'] = 'preserved';
            $counts['preserved']++;
        }

        $row['clears'] = [];
        $row['overwrites'] = $overwrites;

        if (!empty($changes)) {
            $updates[$row['studentID']] = $changes;
        }
    }
    unset($row);

    return [
        'rows'    => $rows,
        'counts'  => $counts,
        'updates' => $updates,
        'mapping' => $mapping,
    ];
}

function scoreLogImportStudentName(array $roster, $studentID): string
{
    foreach ($roster as $student) {
        $left = (string) ($student['gibbonPersonID'] ?? '');
        $right = (string) $studentID;
        if (ctype_digit($left) && ctype_digit($right) && (int) $left === (int) $right) {
            return trim((string) ($student['preferredName'] ?? '').' '.$student['surname']);
        }
    }

    return '';
}

function scoreLogImportPreviewHash(array $mapping, array $preview, $revision): string
{
    return hash('sha256', json_encode([
        'mapping'  => $mapping,
        'counts'   => $preview['counts'],
        'rows'     => array_map(function ($row) {
            return [
                'rowNumber' => $row['rowNumber'],
                'identifier'=> $row['identifier'],
                'studentID' => $row['studentID'],
                'importScore' => $row['importScore'],
                'normalizedScore' => $row['normalizedScore'],
                'importComment'   => $row['importComment'],
                'status'    => $row['status'],
                'clears'    => $row['clears'] ?? [],
                'overwrites'=> $row['overwrites'] ?? [],
                'errors'    => $row['errors'],
            ];
        }, $preview['rows']),
        'updates'  => $preview['updates'],
        'revision' => (string) $revision,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function scoreLogImportColumnLabel($column): string
{
    return sprintf(scoreLogT('Column %s'), ((int) $column + 1));
}

function scoreLogImportRoleLabel($role): string
{
    return [
        'id'       => scoreLogT('Student ID'),
        'username' => scoreLogT('Username'),
        'name'     => scoreLogT('Student Name'),
        'score'    => scoreLogT('Score'),
        'comment'  => scoreLogT('Comment'),
    ][$role] ?? scoreLogT('Unmapped');
}

function scoreLogImportSkippedCount(array $counts): int
{
    return (int) ($counts['unknown'] ?? 0)
        + (int) ($counts['ambiguous'] ?? 0)
        + (int) ($counts['duplicate'] ?? 0)
        + (int) ($counts['invalid'] ?? 0);
}

function scoreLogImportCsvEncoding(string $contents): string
{
    $sample = substr($contents, 0, 8192);
    if (str_starts_with($sample, "\xEF\xBB\xBF")) {
        return 'UTF-8';
    }

    $encoding = mb_detect_encoding($sample, ['UTF-8', 'GB18030', 'GBK', 'BIG-5', 'CP1252'], true);

    return $encoding ?: 'UTF-8';
}

function scoreLogImportWorksheetToMatrix($sheet): array
{
    $maxRows = 500;
    $maxColumns = 20;
    $highestRow = $sheet->getHighestDataRow();
    $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    if ($highestRow > $maxRows) {
        throw new RuntimeException('Too many rows');
    }

    $readColumns = min($maxColumns, $highestColumn);
    $matrix = [];
    $formulaCells = [];
    for ($row = 1; $row <= $highestRow; $row++) {
        $values = [];
        for ($column = 1; $column <= $readColumns; $column++) {
            $cell = $sheet->getCell([$column, $row]);
            $value = $cell->getValue();
            if ($cell->isFormula()) {
                $formulaCells[($row - 1).':'.($column - 1)] = true;
            }
            if (is_object($value) && method_exists($value, 'getPlainText')) {
                $value = $value->getPlainText();
            }
            $value = is_scalar($value) ? (string) $value : '';
            if (preg_match('/^\s*[=+@]/u', $value)) {
                $formulaCells[($row - 1).':'.($column - 1)] = true;
            }
            $values[] = $value;
        }
        $matrix[] = $values;
    }

    return [
        'matrix'         => $matrix,
        'formulaCells'   => $formulaCells,
        'rowCount'       => $highestRow,
        'columnCount'    => $highestColumn,
        'ignoredColumns' => max(0, $highestColumn - $readColumns),
        'sheetName'      => $sheet->getTitle(),
    ];
}

function scoreLogParseImportFile(string $path, string $extension): array
{
    $extension = strtolower($extension);
    if (!in_array($extension, ['csv', 'xlsx'], true)) {
        throw new RuntimeException('Unsupported file type');
    }

    if ($extension === 'csv') {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read file');
        }
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
        $reader->setInputEncoding(scoreLogImportCsvEncoding($contents));
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
    } else {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $sheetInfo = $reader->listWorksheetInfo($path);
        if (empty($sheetInfo[0]['worksheetName'])) {
            throw new RuntimeException('Unable to read workbook');
        }
        if ((int) ($sheetInfo[0]['totalRows'] ?? 0) > 500) {
            throw new RuntimeException('Too many rows');
        }
        $reader->setLoadSheetsOnly([$sheetInfo[0]['worksheetName']]);
        $spreadsheet = $reader->load($path);
    }

    return scoreLogImportWorksheetToMatrix($spreadsheet->getSheet(0));
}

function scoreLogBuildAssessmentSpreadsheet(
    array $payload,
    array $roster,
    string $columnID,
    string $exportMode = 'template'
): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $includeScores = $exportMode === 'scores';
    $savedStudents = is_array($payload['students'] ?? null) ? $payload['students'] : [];
    $column = is_array($payload['columns'][$columnID] ?? null) ? $payload['columns'][$columnID] : [];
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()
        ->setTitle($includeScores ? scoreLogT('Scores') : scoreLogT('Import Template'))
        ->setDescription((string) ($column['description'] ?? ''));
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(scoreLogT('Scores'));
    $sheet->setCellValueExplicit('A1', scoreLogT('Assessment Name'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B1', (string) ($column['name'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('A2', scoreLogT('Description'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B2', (string) ($column['description'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('A3', scoreLogT('Assessment Date'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B3', (string) ($column['completeDate'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->mergeCells('B1:E1');
    $sheet->mergeCells('B2:E2');
    $sheet->mergeCells('B3:E3');
    $sheet->fromArray([
        scoreLogT('Student ID'),
        scoreLogT('Username'),
        scoreLogT('Student Name'),
        scoreLogT('Score'),
        scoreLogT('Comment'),
    ], null, 'A5');

    $rowNumber = 6;
    foreach ($roster as $student) {
        $studentID = (string) ($student['gibbonPersonID'] ?? '');
        $saved = is_array($savedStudents[$studentID]['scores'][$columnID] ?? null)
            ? $savedStudents[$studentID]['scores'][$columnID]
            : [];
        $templateStudentID = trim((string) ($student['studentID'] ?? ''));
        if ($templateStudentID === '') {
            $templateStudentID = $studentID;
        }
        $sheet->setCellValueExplicit(
            'A'.$rowNumber,
            $templateStudentID,
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            'B'.$rowNumber,
            (string) ($student['username'] ?? ''),
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            'C'.$rowNumber,
            trim((string) ($student['preferredName'] ?? '').' '.(string) ($student['surname'] ?? '')),
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            'D'.$rowNumber,
            $includeScores ? (string) ($saved['attainmentValue'] ?? '') : '',
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            'E'.$rowNumber,
            $includeScores ? (string) ($saved['comment'] ?? '') : '',
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $rowNumber++;
    }

    foreach (['A', 'B', 'C', 'D', 'E'] as $columnLetter) {
        $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
    }
    $sheet->getStyle('A1:A3')->getFont()->setBold(true);
    $sheet->getStyle('A5:E5')->getFont()->setBold(true);
    $sheet->getStyle('B2')->getAlignment()->setWrapText(true);
    $sheet->getColumnDimension('A')->setWidth(24);
    $sheet->freezePane('A6');

    return $spreadsheet;
}

function scoreLogParseImportPaste(string $contents): array
{
    if (strlen($contents) > 524288) {
        throw new RuntimeException('Paste too large');
    }

    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
    $reader->setDelimiter("\t");
    $reader->setInputEncoding('UTF-8');
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->loadSpreadsheetFromString($contents);

    return scoreLogImportWorksheetToMatrix($spreadsheet->getSheet(0));
}
