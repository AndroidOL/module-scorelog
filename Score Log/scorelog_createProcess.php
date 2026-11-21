<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

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

use Gibbon\Data\Validator;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Services\Format;
use Gibbon\Session\TokenHandler;

require_once __DIR__ . '/../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$input = $container->get(Validator::class)->sanitize($_POST);
$gibbonPersonID = $session->get('gibbonPersonID');
$gibbonSchoolYearID = $session->get('gibbonSchoolYearID');
$URL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_view.php';

$redirect = function (string $return) use ($URL) {
    header('Location: '.$URL.'&return='.$return);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $redirect('error3');
}

if (!isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')) {
    $redirect('error0');
}

if (!isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')) {
    $redirect('error0');
}

if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    $redirect('error9');
}

if (empty($input)) {
    $redirect('error3');
}

$inputString = function (string $key) use ($input): string {
    return is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
};

$gibbonCourseClassID = $inputString('gibbonCourseClassID');
$gibbonScaleIDAttainment = $inputString('gibbonScaleIDAttainment');
$name = $inputString('name');
$description = $inputString('description');
$type = $inputString('type');
$viewableStudents = $inputString('viewableStudents');
$viewableParents = $inputString('viewableParents');
$completeDateInput = $inputString('completeDate');
$completeDate = $completeDateInput !== '' ? Format::dateConvert($completeDateInput) : '';
$count = is_scalar($input['count'] ?? null) ? (int) $input['count'] : 0;

if ($description === '') {
    $description = scoreLogT('Created by Score Log');
}
$settingGateway = $container->get(SettingGateway::class);
$allowedTypes = scoreLogAllowedTypes($settingGateway->getSettingByScope('Formal Assessment', 'internalAssessmentTypes'));
$defaultType = $allowedTypes[0] ?? scoreLogT('Assessment');
if ($type === '') {
    $type = $defaultType;
}
$today = date('Y-m-d');
if (empty($completeDate)) {
    $completeDate = $today;
}

if (
    $gibbonCourseClassID === ''
    || $gibbonScaleIDAttainment === ''
    || $name === ''
    || mb_strlen($name) > 30
    || mb_strlen($description) > 1000
    || !in_array($type, $allowedTypes ?: [$defaultType], true)
    || mb_strlen($type) > 50
    || !in_array($viewableStudents, ['Y', 'N'], true)
    || !in_array($viewableParents, ['Y', 'N'], true)
    || $count < 1
    || !scoreLogIsValidDate($completeDate)
) {
    $redirect('error1');
}

$class = $pdo->selectOne(
    "SELECT cc.gibbonCourseClassID
     FROM gibbonCourseClass cc
     JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
     WHERE cc.gibbonCourseClassID=:gibbonCourseClassID
     AND c.gibbonSchoolYearID=:gibbonSchoolYearID",
    ['gibbonCourseClassID' => $gibbonCourseClassID, 'gibbonSchoolYearID' => $gibbonSchoolYearID]
);
if (empty($class)) {
    $redirect('error1');
}

$isTeacherOfClass = $pdo->selectOne(
    "SELECT 1 FROM gibbonCourseClassPerson
     WHERE gibbonCourseClassID=:gibbonCourseClassID
     AND gibbonPersonID=:gibbonPersonID
     AND role IN ('Teacher', 'Assistant', 'Technician')
     LIMIT 1",
    ['gibbonCourseClassID' => $gibbonCourseClassID, 'gibbonPersonID' => $gibbonPersonID]
);
if (!scoreLogCanViewAllClasses($gibbonPersonID) && empty($isTeacherOfClass)) {
    $redirect('error0');
}

$scale = $pdo->selectOne(
    "SELECT gibbonScaleID, name, `usage`, `numeric`
     FROM gibbonScale
     WHERE gibbonScaleID=:gibbonScaleID AND active='Y'",
    ['gibbonScaleID' => $gibbonScaleIDAttainment]
);
if (empty($scale)) {
    $redirect('error1');
}

$students = $pdo->select(
    "SELECT gibbonPerson.gibbonPersonID
     FROM gibbonCourseClassPerson
     JOIN gibbonPerson ON (gibbonCourseClassPerson.gibbonPersonID=gibbonPerson.gibbonPersonID)
     WHERE gibbonCourseClassPerson.gibbonCourseClassID=:gibbonCourseClassID
     AND gibbonCourseClassPerson.reportable='Y'
     AND gibbonCourseClassPerson.role='Student'
     AND gibbonPerson.status='Full'",
    ['gibbonCourseClassID' => $gibbonCourseClassID]
)->fetchAll();

$studentIDs = array_flip(array_map('strval', array_column($students, 'gibbonPersonID')));
if ($count > count($studentIDs)) {
    $redirect('error1');
}

$grades = $pdo->select(
    "SELECT value, descriptor FROM gibbonScaleGrade WHERE gibbonScaleID=:gibbonScaleID",
    ['gibbonScaleID' => $gibbonScaleIDAttainment]
)->fetchAll();

$gradeDescriptors = [];
foreach ($grades as $grade) {
    $gradeDescriptors[(string) $grade['value']] = $grade['descriptor'];
}

$maxScore = scoreLogMaxScoreFromUsage($scale['usage']);
$entries = [];
$seenStudents = [];

for ($i = 1; $i <= $count; ++$i) {
    $studentID = is_scalar($input[$i.'-gibbonPersonID'] ?? null) ? (string) $input[$i.'-gibbonPersonID'] : '';
    if (!isset($studentIDs[$studentID]) || isset($seenStudents[$studentID])) {
        $redirect('error1');
    }
    $seenStudents[$studentID] = true;

    $value = is_scalar($input[$i.'-attainmentValue'] ?? null) ? trim((string) $input[$i.'-attainmentValue']) : '';
    $comment = is_scalar($input['comment'.$i] ?? null) ? trim((string) $input['comment'.$i]) : '';
    $descriptor = '';

    if (mb_strlen($comment) > 1000) {
        $redirect('error1');
    }

    if ($value !== '') {
        if ($scale['numeric'] === 'Y') {
            $value = scoreLogNormalizeNumericValue($value);
            if ($value === null) {
                $redirect('error1');
            }

            if ($maxScore !== null && ((float) $value < 0 || (float) $value > $maxScore)) {
                $redirect('error1');
            }

            $descriptor = $gradeDescriptors[$value] ?? $value;
        } else {
            if (!isset($gradeDescriptors[$value])) {
                $redirect('error1');
            }
            $descriptor = $gradeDescriptors[$value];
        }
    }

    $entries[] = [
        'gibbonPersonIDStudent' => $studentID,
        'attainmentValue' => $value,
        'attainmentDescriptor' => $descriptor,
        'comment' => $comment,
    ];
}

// The grouping ID is a global MAX+1, so serialise all creations rather than just
// this class/teacher pair; otherwise two teachers can insert the same ID.
$lockName = 'gibbon_score_log_grouping';
$lockStatement = $connection2->prepare('SELECT GET_LOCK(?, 5)');
$lockStatement->execute([$lockName]);
$lockAcquired = (int) $lockStatement->fetchColumn() === 1;
if (!$lockAcquired) {
    $redirect('error2');
}

$failed = false;

try {
    $connection2->beginTransaction();

    $groupingID = (int) $connection2
        ->query("SELECT COALESCE(MAX(groupingID), 0) + 1 FROM gibbonInternalAssessmentColumn")
        ->fetchColumn();

    $columnStatement = $connection2->prepare(
        "INSERT INTO gibbonInternalAssessmentColumn
         SET groupingID=:groupingID, gibbonCourseClassID=:gibbonCourseClassID, name=:name,
             description=:description, type=:type, attainment='Y',
             gibbonScaleIDAttainment=:gibbonScaleIDAttainment, effort='N',
             gibbonScaleIDEffort=NULL, comment='Y', uploadedResponse='N',
             complete='Y', completeDate=:completeDate,
             viewableStudents=:viewableStudents, viewableParents=:viewableParents,
             attachment='', gibbonPersonIDCreator=:gibbonPersonID,
             gibbonPersonIDLastEdit=:gibbonPersonID"
    );
    $columnStatement->execute([
        'groupingID' => $groupingID,
        'gibbonCourseClassID' => $gibbonCourseClassID,
        'name' => $name,
        'description' => $description,
        'type' => $type,
        'gibbonScaleIDAttainment' => $gibbonScaleIDAttainment,
        'completeDate' => $completeDate,
        'viewableStudents' => $viewableStudents,
        'viewableParents' => $viewableParents,
        'gibbonPersonID' => $gibbonPersonID,
    ]);

    $gibbonInternalAssessmentColumnID = (int) $connection2->lastInsertId();

    $entryStatement = $connection2->prepare(
        "INSERT INTO gibbonInternalAssessmentEntry
         SET gibbonInternalAssessmentColumnID=:gibbonInternalAssessmentColumnID,
             gibbonPersonIDStudent=:gibbonPersonIDStudent,
             attainmentValue=:attainmentValue,
             attainmentDescriptor=:attainmentDescriptor,
             effortValue=NULL, effortDescriptor=NULL,
             comment=:comment, response=NULL,
             gibbonPersonIDLastEdit=:gibbonPersonID"
    );

    foreach ($entries as $entry) {
        $entryStatement->execute([
            'gibbonInternalAssessmentColumnID' => $gibbonInternalAssessmentColumnID,
            'gibbonPersonIDStudent' => $entry['gibbonPersonIDStudent'],
            'attainmentValue' => $entry['attainmentValue'],
            'attainmentDescriptor' => $entry['attainmentDescriptor'],
            'comment' => $entry['comment'],
            'gibbonPersonID' => $gibbonPersonID,
        ]);
    }

    $connection2->commit();
} catch (\Throwable $e) {
    if ($connection2->inTransaction()) {
        $connection2->rollBack();
    }
    $failed = true;
} finally {
    try {
        $releaseStatement = $connection2->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStatement->execute([$lockName]);
    } catch (\Throwable $e) {
        // The connection dropping already releases session-level MySQL locks.
    }
}

if ($failed) {
    $redirect('error2');
}

header('Location: '.$URL.'&gibbonCourseClassID='.urlencode($gibbonCourseClassID).'&gibbonScaleIDAttainment='.urlencode($gibbonScaleIDAttainment).'&return=success0');
exit;
