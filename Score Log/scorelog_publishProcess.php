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
) {
    $redirect('error0');
}
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    $redirect('error9');
}

$input = $container->get(Validator::class)->sanitize($_POST);
$revision = is_scalar($input['revision'] ?? null) ? max(1, (int) $input['revision']) : 0;
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

    $grades = $pdo->select(
        "SELECT value, descriptor FROM gibbonScaleGrade WHERE gibbonScaleID=:gibbonScaleID",
        ['gibbonScaleID' => $draft['gibbonScaleIDAttainment']]
    )->fetchAll();
    $gradeDescriptors = [];
    foreach ($grades as $grade) {
        $gradeDescriptors[(string) $grade['value']] = $grade['descriptor'];
    }

    $settingGateway = $container->get(SettingGateway::class);
    $allowedTypes = scoreLogAllowedTypes($settingGateway->getSettingByScope('Formal Assessment', 'internalAssessmentTypes'));
    $evaluation = scoreLogEvaluateDraftPayload(
        $draft['payloadData'],
        $roster,
        ['usage' => $draft['scaleUsage'], 'numeric' => $draft['scaleNumeric']],
        $gradeDescriptors,
        $allowedTypes
    );
    if (!$evaluation['valid']) {
        throw new RuntimeException('Invalid draft');
    }

    $diff = scoreLogRosterDiff($draft['payloadData'], $roster);
    if ($diff['changed'] && scoreLogInputString($input, 'confirmRoster') !== 'Y') {
        throw new RuntimeException('Roster confirmation missing');
    }
    if ($evaluation['missing'] > 0 && scoreLogInputString($input, 'confirmBlank') !== 'Y') {
        throw new RuntimeException('Blank confirmation missing');
    }

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
        'groupingID'                      => $groupingID,
        'gibbonCourseClassID'             => $draft['gibbonCourseClassID'],
        'name'                            => $evaluation['meta']['name'],
        'description'                     => $evaluation['meta']['description'],
        'type'                            => $evaluation['meta']['type'],
        'gibbonScaleIDAttainment'         => $draft['gibbonScaleIDAttainment'],
        'completeDate'                    => $evaluation['meta']['completeDate'],
        'viewableStudents'                => $evaluation['meta']['viewableStudents'],
        'viewableParents'                 => $evaluation['meta']['viewableParents'],
        'gibbonPersonID'                  => $gibbonPersonID,
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
    foreach ($evaluation['rows'] as $entry) {
        $entryStatement->execute([
            'gibbonInternalAssessmentColumnID' => $gibbonInternalAssessmentColumnID,
            'gibbonPersonIDStudent'            => $entry['gibbonPersonID'],
            'attainmentValue'                  => $entry['normalizedValue'],
            'attainmentDescriptor'             => $entry['descriptor'],
            'comment'                          => $entry['comment'],
            'gibbonPersonID'                   => $gibbonPersonID,
        ]);
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
        // The assessment is already committed; connection cleanup will release the lock.
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
    } elseif ($e->getMessage() === 'Lock unavailable') {
        $return = 'error2';
    }
    $redirect($return, $return === 'error6' ? 'scoreLogPublish' : '');
}

header(
    'Location: '.$session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_view.php'
    .'&gibbonCourseClassID='.urlencode((string) $draft['gibbonCourseClassID'])
    .'&gibbonScaleIDAttainment='.urlencode((string) $draft['gibbonScaleIDAttainment'])
    .'&return=success0'
);
exit;
