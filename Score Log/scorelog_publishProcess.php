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
use Gibbon\Session\TokenHandler;

require_once __DIR__.'/../../gibbon.php';
require_once __DIR__.'/moduleFunctions.php';

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$scoreLogDraftID = is_scalar($_POST['scoreLogDraftID'] ?? null) ? trim((string) $_POST['scoreLogDraftID']) : '';
$reviewURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_review.php&draftID='.urlencode($scoreLogDraftID);

$redirect = function (string $return, string $anchor = '') use ($reviewURL) {
    header('Location: '.$reviewURL.'&return='.$return.($anchor !== '' ? '#'.$anchor : ''));
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $redirect('error3');
}
if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php')
) {
    $redirect('error0');
}
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    $redirect('error9');
}

$input = $container->get(Validator::class)->sanitize($_POST);
$revision = is_scalar($input['revision'] ?? null) ? max(0, (int) $input['revision']) : 0;
$reviewedRosterHash = scoreLogInputString($input, 'rosterHash');
if ($scoreLogDraftID === '' || $revision < 1 || $reviewedRosterHash === '') {
    $redirect('error1');
}

$lockName = 'gibbon_score_log_grouping';
$lockStatement = $connection2->prepare('SELECT GET_LOCK(?, 5)');
$lockStatement->execute([$lockName]);
$lockAcquired = (int) $lockStatement->fetchColumn() === 1;
if (!$lockAcquired) {
    $redirect('error2');
}

try {
    $connection2->beginTransaction();
    $marker = $pdo->selectOne(
        "SELECT * FROM score_log_draft
         WHERE scoreLogDraftID=:scoreLogDraftID
           AND gibbonPersonIDCreator=:gibbonPersonID
         FOR UPDATE",
        [
            'scoreLogDraftID' => $scoreLogDraftID,
            'gibbonPersonID'  => $gibbonPersonID,
        ]
    );
    if (empty($marker) || (int) $marker['revision'] !== $revision) {
        throw new RuntimeException('Stale draft');
    }

    $draft = scoreLogLoadDraft($pdo, $scoreLogDraftID, $gibbonPersonID);
    if (
        empty($draft)
        || (string) $draft['gibbonSchoolYearID'] !== $gibbonSchoolYearID
        || !scoreLogCanEditClass($pdo, $gibbonPersonID, $draft['gibbonCourseClassID'], $gibbonSchoolYearID)
    ) {
        throw new RuntimeException('Draft unavailable');
    }

    $roster = scoreLogCurrentRoster($pdo, $draft['gibbonCourseClassID']);
    if (empty($roster) || !hash_equals($reviewedRosterHash, scoreLogRosterHash($roster))) {
        throw new RuntimeException('Roster changed');
    }

    $scaleRows = $pdo->select(
        "SELECT gibbonScaleID, name, `usage`, `numeric`, active
         FROM gibbonScale ORDER BY name"
    )->fetchAll();
    $scales = [];
    foreach ($scaleRows as $scale) {
        if ($scale['active'] === 'Y') {
            $scales[(string) $scale['gibbonScaleID']] = $scale;
        }
    }
    $grades = $pdo->select(
        "SELECT gibbonScaleID, value, descriptor
         FROM gibbonScaleGrade ORDER BY gibbonScaleID, sequenceNumber"
    )->fetchAll();
    $gradesByScale = [];
    foreach ($grades as $grade) {
        $gradesByScale[(string) $grade['gibbonScaleID']][(string) $grade['value']] = $grade['descriptor'];
    }

    $settingGateway = $container->get(SettingGateway::class);
    $allowedTypes = scoreLogAllowedTypes($settingGateway->getSettingByScope('Formal Assessment', 'internalAssessmentTypes'));
    $evaluation = scoreLogEvaluateMultiAssessmentPayload(
        $draft['payloadData'],
        $roster,
        $scales,
        $gradesByScale,
        $allowedTypes
    );
    if (!$evaluation['valid']) {
        throw new RuntimeException('Invalid draft');
    }

    $diff = scoreLogRosterDiff($draft['payloadData'], $roster);
    if ($diff['changed'] && scoreLogInputString($input, 'confirmRoster') !== 'Y') {
        throw new RuntimeException('Roster confirmation missing');
    }
    if ($evaluation['totals']['missing'] > 0 && scoreLogInputString($input, 'confirmBlank') !== 'Y') {
        throw new RuntimeException('Blank confirmation missing');
    }

    $columnStatement = $connection2->prepare(
        "INSERT INTO gibbonInternalAssessmentColumn
         SET groupingID=:groupingID, gibbonCourseClassID=:gibbonCourseClassID, name=:name,
             description=:description, type=:type, attainment=:attainment,
             gibbonScaleIDAttainment=:gibbonScaleIDAttainment, effort=:effort,
             gibbonScaleIDEffort=:gibbonScaleIDEffort, comment=:comment,
             uploadedResponse=:uploadedResponse, complete=:complete,
             completeDate=:completeDate, viewableStudents=:viewableStudents,
             viewableParents=:viewableParents, attachment=:attachment,
             gibbonPersonIDCreator=:gibbonPersonIDCreator,
             gibbonPersonIDLastEdit=:gibbonPersonIDLastEdit"
    );
    $entryStatement = $connection2->prepare(
        "INSERT INTO gibbonInternalAssessmentEntry
         SET gibbonInternalAssessmentColumnID=:gibbonInternalAssessmentColumnID,
             gibbonPersonIDStudent=:gibbonPersonIDStudent,
             attainmentValue=:attainmentValue,
             attainmentDescriptor=:attainmentDescriptor,
             effortValue=:effortValue, effortDescriptor=:effortDescriptor,
             comment=:comment, response=:response,
             gibbonPersonIDLastEdit=:gibbonPersonIDLastEdit"
    );

    $insertColumn = function (array $column, int $groupingID) use (
        $columnStatement,
        $connection2,
        $draft,
        $gibbonPersonID
    ) {
        $columnStatement->execute(scoreLogCoreAssessmentValues(
            $column['config'],
            $draft['gibbonCourseClassID'],
            $gibbonPersonID,
            $groupingID
        ));

        return (int) $connection2->lastInsertId();
    };
    $insertEntries = function (int $columnID, array $rows) use ($entryStatement, $gibbonPersonID) {
        foreach ($rows as $entry) {
            $entryStatement->execute(scoreLogCoreEntryValues($columnID, $entry, $gibbonPersonID));
        }
    };

    $columnsToPublish = array_values($evaluation['columns']);
    $firstColumn = array_shift($columnsToPublish);
    if (empty($firstColumn)) {
        throw new RuntimeException('No columns to publish');
    }

    // Reserve and modify the core table before reading MAX. This coordinates
    // groupingID allocation with the official LOCK TABLES-based add process.
    $firstColumnID = $insertColumn($firstColumn, 0);
    $nextGroupingID = (int) $connection2
        ->query("SELECT COALESCE(MAX(groupingID), 0) + 1 FROM gibbonInternalAssessmentColumn")
        ->fetchColumn();
    $groupingStatement = $connection2->prepare(
        "UPDATE gibbonInternalAssessmentColumn
         SET groupingID=:groupingID
         WHERE gibbonInternalAssessmentColumnID=:gibbonInternalAssessmentColumnID"
    );
    $groupingStatement->execute([
        'groupingID'                       => $nextGroupingID++,
        'gibbonInternalAssessmentColumnID' => $firstColumnID,
    ]);
    $insertEntries($firstColumnID, $firstColumn['rows']);

    foreach ($columnsToPublish as $column) {
        $columnID = $insertColumn($column, $nextGroupingID++);
        $insertEntries($columnID, $column['rows']);
    }

    $deleted = $pdo->delete(
        "DELETE FROM score_log_draft
         WHERE scoreLogDraftID=:scoreLogDraftID
           AND gibbonPersonIDCreator=:gibbonPersonID
           AND revision=:revision",
        [
            'scoreLogDraftID' => $scoreLogDraftID,
            'gibbonPersonID'  => $gibbonPersonID,
            'revision'        => $revision,
        ]
    );
    if ((int) $deleted !== 1) {
        throw new RuntimeException('Draft changed');
    }

    $connection2->commit();
    try {
        $releaseStatement = $connection2->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStatement->execute([$lockName]);
    } catch (\Throwable $releaseError) {
        // The assessment is committed; connection cleanup will release the lock.
    }
    $lockAcquired = false;
} catch (\Throwable $e) {
    if ($connection2->inTransaction()) {
        $connection2->rollBack();
    }
    if ($lockAcquired) {
        try {
            $releaseStatement = $connection2->prepare('SELECT RELEASE_LOCK(?)');
            $releaseStatement->execute([$lockName]);
        } catch (\Throwable $releaseError) {
            // Dropping the connection also releases a session-level MySQL lock.
        }
        $lockAcquired = false;
    }

    $return = 'error1';
    if ($e->getMessage() === 'Stale draft' || $e->getMessage() === 'Draft changed') {
        $return = 'error4';
    } elseif ($e->getMessage() === 'Roster changed' || $e->getMessage() === 'Roster confirmation missing') {
        $return = 'error5';
    } elseif ($e->getMessage() === 'Blank confirmation missing') {
        $return = 'error6';
    }
    $redirect($return, $return === 'error6' ? 'scoreLogPublish' : '');
}

header(
    'Location: '.$session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_view.php'
    .'&gibbonCourseClassID='.urlencode((string) $draft['gibbonCourseClassID'])
    .'&gibbonScaleIDAttainment='.urlencode((string) ($evaluation['columns'][array_key_first($evaluation['columns'])]['config']['scaleID'] ?? ''))
    .'&return=success0'
);
exit;
