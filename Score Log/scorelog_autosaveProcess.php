<?php
/*
Gibbon: the flexible, open school platform
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

use Gibbon\Data\Validator;
use Gibbon\Services\Format;
use Gibbon\Session\TokenHandler;

require_once __DIR__.'/../../gibbon.php';
require_once __DIR__.'/moduleFunctions.php';

$respond = function (int $httpStatus, array $payload) {
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(405, ['status' => 'error', 'message' => 'POST required']);
}
if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php')
) {
    $respond(403, ['status' => 'error', 'message' => 'Forbidden']);
}
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    $respond(419, ['status' => 'error', 'message' => 'Session expired']);
}

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$input = $container->get(Validator::class)->sanitize($_POST);
$scoreLogDraftID = scoreLogInputString($input, 'scoreLogDraftID');
$autosaveKey = scoreLogInputString($input, 'autosaveKey');
$autosaveSequence = is_scalar($input['autosaveSequence'] ?? null)
    ? max(0, (int) $input['autosaveSequence'])
    : 0;
$revision = is_scalar($input['revision'] ?? null) ? max(0, (int) $input['revision']) : 0;
$columnOrder = is_scalar($input['columnOrder'] ?? null)
    ? preg_split('/[,;]+/', (string) $input['columnOrder'], -1, PREG_SPLIT_NO_EMPTY)
    : [];
$columnOrder = array_values(array_unique(array_filter(array_map('trim', $columnOrder), function ($columnID) {
    return preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $columnID) === 1;
})));
if (count($columnOrder) !== 1) {
    $respond(422, ['status' => 'error', 'message' => 'Autosave supports one assessment column']);
}
$columnID = $columnOrder[0];
$columnDate = is_array($input['columns'][$columnID] ?? null)
    ? trim((string) ($input['columns'][$columnID]['completeDate'] ?? ''))
    : '';
if ($columnDate !== '' && !scoreLogIsValidDate($columnDate)) {
    $convertedDate = Format::dateConvert($columnDate);
    if (scoreLogIsValidDate($convertedDate)) {
        $input['columns'][$columnID]['completeDate'] = $convertedDate;
    }
}

$autosaveLockName = null;
$autosaveLockAcquired = false;
$releaseAutosaveLock = function () use (&$autosaveLockName, &$autosaveLockAcquired, $connection2) {
    if (!$autosaveLockAcquired || $autosaveLockName === null) return;
    try {
        $statement = $connection2->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$autosaveLockName]);
    } catch (Throwable $e) {
        // Connection cleanup also releases a session-level MySQL lock.
    }
    $autosaveLockAcquired = false;
};

try {
    if ($scoreLogDraftID === '' && preg_match('/^[a-f0-9]{32}$/D', $autosaveKey)) {
        $autosaveLockName = 'scorelog_auto_'.substr(hash('sha256', $gibbonPersonID.'|'.$autosaveKey), 0, 40);
        $lockStatement = $connection2->prepare('SELECT GET_LOCK(?, 5)');
        $lockStatement->execute([$autosaveLockName]);
        $autosaveLockAcquired = (int) $lockStatement->fetchColumn() === 1;
        if (!$autosaveLockAcquired) {
            $respond(503, ['status' => 'error', 'message' => 'Autosave busy']);
        }
    }

    $connection2->beginTransaction();
    if ($scoreLogDraftID !== '') {
        if ($revision < 1) {
            throw new RuntimeException('invalid-revision');
        }
        $marker = $pdo->selectOne(
            "SELECT revision
             FROM score_log_draft
             WHERE scoreLogDraftID=:scoreLogDraftID
               AND gibbonPersonIDCreator=:gibbonPersonID
             FOR UPDATE",
            [
                'scoreLogDraftID' => $scoreLogDraftID,
                'gibbonPersonID' => $gibbonPersonID,
            ]
        );
        if (empty($marker)) {
            throw new RuntimeException('not-found');
        }
        if ((int) $marker['revision'] !== $revision) {
            $connection2->rollBack();
            $respond(409, [
                'status' => 'conflict',
                'revision' => (int) $marker['revision'],
            ]);
        }

        $draft = scoreLogLoadDraft($pdo, $scoreLogDraftID, $gibbonPersonID);
        if (
            empty($draft)
            || (string) $draft['gibbonSchoolYearID'] !== $gibbonSchoolYearID
            || !scoreLogCanEditClass($pdo, $gibbonPersonID, $draft['gibbonCourseClassID'], $gibbonSchoolYearID)
        ) {
            throw new RuntimeException('not-found');
        }
        $savedColumns = array_values(array_intersect(
            (array) ($draft['payloadData']['columnOrder'] ?? []),
            array_keys((array) ($draft['payloadData']['columns'] ?? []))
        ));
        if (count($savedColumns) !== 1 || $savedColumns[0] !== $columnID) {
            throw new RuntimeException('column-changed');
        }

        $roster = scoreLogCurrentRoster($pdo, $draft['gibbonCourseClassID']);
        if (empty($roster)) {
            throw new RuntimeException('empty-roster');
        }
        $payload = scoreLogBuildMultiAssessmentPayload($input, $roster);
        $payload = scoreLogPreserveRosterBaseline($payload, $draft['payloadData']);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false || strlen($payloadJson) > 1048576) {
            throw new RuntimeException('payload-too-large');
        }
        $updated = $pdo->update(
            "UPDATE score_log_draft
             SET payload=:payload, revision=revision+1
             WHERE scoreLogDraftID=:scoreLogDraftID
               AND gibbonPersonIDCreator=:gibbonPersonID",
            [
                'payload' => $payloadJson,
                'scoreLogDraftID' => $scoreLogDraftID,
                'gibbonPersonID' => $gibbonPersonID,
            ]
        );
        if ((int) $updated !== 1) {
            throw new RuntimeException('draft-unavailable');
        }
        $revisionRow = $pdo->selectOne(
            "SELECT revision FROM score_log_draft WHERE scoreLogDraftID=:scoreLogDraftID",
            ['scoreLogDraftID' => $scoreLogDraftID]
        );
        $newRevision = (int) ($revisionRow['revision'] ?? ($revision + 1));
    } else {
        $gibbonCourseClassID = scoreLogInputString($input, 'gibbonCourseClassID');
        $gibbonScaleIDAttainment = scoreLogInputString($input, 'gibbonScaleIDAttainment');
        if (
            $gibbonCourseClassID === ''
            || $gibbonScaleIDAttainment === ''
            || !scoreLogCanEditClass($pdo, $gibbonPersonID, $gibbonCourseClassID, $gibbonSchoolYearID)
        ) {
            throw new RuntimeException('invalid-class');
        }
        $scale = $pdo->selectOne(
            "SELECT gibbonScaleID
             FROM gibbonScale
             WHERE gibbonScaleID=:gibbonScaleID AND active='Y'",
            ['gibbonScaleID' => $gibbonScaleIDAttainment]
        );
        if (empty($scale)) {
            throw new RuntimeException('invalid-scale');
        }
        $roster = scoreLogCurrentRoster($pdo, $gibbonCourseClassID);
        if (empty($roster)) {
            throw new RuntimeException('empty-roster');
        }
        if (!preg_match('/^[a-f0-9]{32}$/D', $autosaveKey) || $autosaveSequence < 1) {
            throw new RuntimeException('invalid-autosave-key');
        }
        $payload = scoreLogBuildMultiAssessmentPayload($input, $roster);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false || strlen($payloadJson) > 1048576) {
            throw new RuntimeException('payload-too-large');
        }
        $existingAutosave = $pdo->selectOne(
            "SELECT scoreLogDraftID, revision, payload
             FROM score_log_draft
             WHERE gibbonPersonIDCreator=:gibbonPersonID
               AND gibbonCourseClassID=:gibbonCourseClassID
               AND payload LIKE :autosavePattern
             ORDER BY updateTimestamp DESC
             LIMIT 1",
            [
                'gibbonPersonID' => $gibbonPersonID,
                'gibbonCourseClassID' => $gibbonCourseClassID,
                'autosavePattern' => '%"autosaveKey":"'.$autosaveKey.'"%',
            ]
        );
        $existingPayload = !empty($existingAutosave)
            ? json_decode((string) $existingAutosave['payload'], true)
            : null;
        if (
            !is_array($existingPayload)
            || !is_string($existingPayload['autosaveKey'] ?? null)
            || !hash_equals($existingPayload['autosaveKey'], $autosaveKey)
        ) {
            $existingAutosave = null;
        }
        if (!empty($existingAutosave)) {
            $locked = $pdo->selectOne(
                "SELECT revision, payload
                 FROM score_log_draft
                 WHERE scoreLogDraftID=:scoreLogDraftID
                   AND gibbonPersonIDCreator=:gibbonPersonID
                 FOR UPDATE",
                [
                    'scoreLogDraftID' => $existingAutosave['scoreLogDraftID'],
                    'gibbonPersonID' => $gibbonPersonID,
                ]
            );
            if (empty($locked)) {
                throw new RuntimeException('not-found');
            }
            $lockedPayload = json_decode((string) $locked['payload'], true);
            $lockedSequence = is_array($lockedPayload)
                ? max(0, (int) ($lockedPayload['autosaveSequence'] ?? 0))
                : 0;
            if ($autosaveSequence > $lockedSequence) {
                $pdo->update(
                    "UPDATE score_log_draft
                     SET payload=:payload, revision=revision+1
                     WHERE scoreLogDraftID=:scoreLogDraftID
                       AND gibbonPersonIDCreator=:gibbonPersonID",
                    [
                        'payload' => $payloadJson,
                        'scoreLogDraftID' => $existingAutosave['scoreLogDraftID'],
                        'gibbonPersonID' => $gibbonPersonID,
                    ]
                );
                $newRevision = (int) $locked['revision'] + 1;
            } else {
                $newRevision = (int) $locked['revision'];
            }
            $scoreLogDraftID = (string) $existingAutosave['scoreLogDraftID'];
        } else {
            $inserted = $pdo->insert(
                "INSERT INTO score_log_draft
                 SET gibbonPersonIDCreator=:gibbonPersonID,
                     gibbonCourseClassID=:gibbonCourseClassID,
                     gibbonScaleIDAttainment=:gibbonScaleIDAttainment,
                     revision=1, payload=:payload",
                [
                    'gibbonPersonID' => $gibbonPersonID,
                    'gibbonCourseClassID' => $gibbonCourseClassID,
                    'gibbonScaleIDAttainment' => $gibbonScaleIDAttainment,
                    'payload' => $payloadJson,
                ]
            );
            if (empty($inserted)) {
                throw new RuntimeException('insert-failed');
            }
            $scoreLogDraftID = (string) $inserted;
            $newRevision = 1;
        }
    }

    $connection2->commit();
    $releaseAutosaveLock();
    $respond(200, [
        'status' => 'saved',
        'draftID' => (string) $scoreLogDraftID,
        'revision' => (int) $newRevision,
        'savedAt' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    if ($connection2->inTransaction()) {
        $connection2->rollBack();
    }
    $releaseAutosaveLock();
    if ($e->getMessage() === 'conflict') {
        $current = $pdo->selectOne(
            "SELECT revision
             FROM score_log_draft
             WHERE scoreLogDraftID=:scoreLogDraftID
               AND gibbonPersonIDCreator=:gibbonPersonID",
            [
                'scoreLogDraftID' => $scoreLogDraftID,
                'gibbonPersonID' => $gibbonPersonID,
            ]
        );
        $respond(409, [
            'status' => 'conflict',
            'revision' => (int) ($current['revision'] ?? 0),
        ]);
    }
    $respond(422, ['status' => 'error', 'message' => 'Draft could not be saved']);
}
