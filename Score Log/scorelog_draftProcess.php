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
use Gibbon\Services\Format;
use Gibbon\Session\TokenHandler;

require_once __DIR__.'/../../gibbon.php';
require_once __DIR__.'/moduleFunctions.php';

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$scoreLogDraftID = is_scalar($_POST['scoreLogDraftID'] ?? null) ? trim((string) $_POST['scoreLogDraftID']) : '';
$action = array_key_exists('reviewPublish', $_POST)
    ? 'review'
    : (array_key_exists('importScores', $_POST)
        ? 'import'
        : (array_key_exists('exportTemplate', $_POST)
            ? 'exportTemplate'
            : (array_key_exists('exportScores', $_POST) ? 'exportScores' : 'draft')));
$viewURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_view.php';

$redirect = function (string $return, string $draftID = '') use ($viewURL) {
    $url = $viewURL;
    if ($draftID !== '') {
        $url .= '&draftID='.urlencode($draftID);
    }

    header('Location: '.$url.'&return='.$return.($draftID !== '' ? '#scoreLogStudents' : ''));
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
if (empty($input)) {
    $redirect('error3', $scoreLogDraftID);
}

$columnOrder = is_scalar($input['columnOrder'] ?? null)
    ? preg_split('/[,;]+/', (string) $input['columnOrder'], -1, PREG_SPLIT_NO_EMPTY)
    : [];
$columnOrder = array_values(array_unique(array_filter(array_map('trim', $columnOrder), function ($columnID) {
    return preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $columnID) === 1;
})));
if (count($columnOrder) !== 1) {
    $redirect('error1', $scoreLogDraftID);
}
foreach ($columnOrder as $columnID) {
    $columnDate = is_array($input['columns'][$columnID] ?? null)
        ? trim((string) ($input['columns'][$columnID]['completeDate'] ?? ''))
        : '';
    if ($columnDate !== '' && !scoreLogIsValidDate($columnDate)) {
        $convertedDate = Format::dateConvert($columnDate);
        if (scoreLogIsValidDate($convertedDate)) {
            $input['columns'][$columnID]['completeDate'] = $convertedDate;
        }
    }
}

try {
    $connection2->beginTransaction();

    if ($scoreLogDraftID !== '') {
        $draft = scoreLogLoadDraft($pdo, $scoreLogDraftID, $gibbonPersonID);
        if (empty($draft) || (string) $draft['gibbonSchoolYearID'] !== $gibbonSchoolYearID) {
            throw new RuntimeException('Draft unavailable');
        }

        $gibbonCourseClassID = (string) $draft['gibbonCourseClassID'];
        if (!scoreLogCanEditClass($pdo, $gibbonPersonID, $gibbonCourseClassID, $gibbonSchoolYearID)) {
            throw new RuntimeException('Class permission changed');
        }

        $roster = scoreLogCurrentRoster($pdo, $gibbonCourseClassID);
        $payload = scoreLogBuildMultiAssessmentPayload($input, $roster);
        $payload = scoreLogPreserveRosterBaseline($payload, $draft['payloadData']);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false || strlen($payloadJson) > 1048576) {
            throw new RuntimeException('Unable to encode draft');
        }

        $revision = is_scalar($input['revision'] ?? null) ? max(0, (int) $input['revision']) : 0;
        if ($revision < 1) {
            throw new RuntimeException('Invalid revision');
        }
        $updated = $pdo->update(
            "UPDATE score_log_draft
             SET payload=:payload, revision=revision+1
             WHERE scoreLogDraftID=:scoreLogDraftID
               AND gibbonPersonIDCreator=:gibbonPersonID
               AND revision=:revision",
            [
                'payload'         => $payloadJson,
                'scoreLogDraftID' => $scoreLogDraftID,
                'gibbonPersonID'  => $gibbonPersonID,
                'revision'        => $revision,
            ]
        );
        if ((int) $updated !== 1) {
            throw new RuntimeException('Stale draft');
        }
    } else {
        $gibbonCourseClassID = scoreLogInputString($input, 'gibbonCourseClassID');
        $gibbonScaleIDAttainment = scoreLogInputString($input, 'gibbonScaleIDAttainment');
        if (
            $gibbonCourseClassID === ''
            || $gibbonScaleIDAttainment === ''
            || !scoreLogCanEditClass($pdo, $gibbonPersonID, $gibbonCourseClassID, $gibbonSchoolYearID)
        ) {
            throw new RuntimeException('Invalid class');
        }

        $scale = $pdo->selectOne(
            "SELECT gibbonScaleID
             FROM gibbonScale
             WHERE gibbonScaleID=:gibbonScaleID AND active='Y'",
            ['gibbonScaleID' => $gibbonScaleIDAttainment]
        );
        if (empty($scale)) {
            throw new RuntimeException('Invalid scale');
        }

        $roster = scoreLogCurrentRoster($pdo, $gibbonCourseClassID);
        if (empty($roster)) {
            throw new RuntimeException('Empty roster');
        }

        $payload = scoreLogBuildMultiAssessmentPayload($input, $roster);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false || strlen($payloadJson) > 1048576) {
            throw new RuntimeException('Unable to encode draft');
        }

        $draftStatement = $connection2->prepare(
            "INSERT INTO score_log_draft
             SET gibbonPersonIDCreator=:gibbonPersonID,
                 gibbonCourseClassID=:gibbonCourseClassID,
                 gibbonScaleIDAttainment=:gibbonScaleIDAttainment,
                 revision=1, payload=:payload"
        );
        $draftStatement->execute([
            'gibbonPersonID'          => $gibbonPersonID,
            'gibbonCourseClassID'     => $gibbonCourseClassID,
            'gibbonScaleIDAttainment' => $gibbonScaleIDAttainment,
            'payload'                 => $payloadJson,
        ]);
        $scoreLogDraftID = (string) $connection2->lastInsertId();
    }

    $targetColumnID = in_array($action, ['import', 'exportTemplate', 'exportScores'], true)
        ? scoreLogInputString($input, 'targetColumnID')
        : '';
    if ($targetColumnID !== ''
        && (empty($payload['columns'][$targetColumnID]) || !in_array($targetColumnID, $payload['columnOrder'], true))
    ) {
        throw new RuntimeException('Invalid target column');
    }

    $connection2->commit();
    if ($action === 'import') {
        $newURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_import.php'
            .'&draftID='.urlencode($scoreLogDraftID).'&columnID='.urlencode($targetColumnID).'&return=success1';
    } elseif ($action === 'exportTemplate') {
        $newURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_importTemplate.php'
            .'&draftID='.urlencode($scoreLogDraftID).'&columnID='.urlencode($targetColumnID).'&mode=template';
    } elseif ($action === 'exportScores') {
        $newURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_importTemplate.php'
            .'&draftID='.urlencode($scoreLogDraftID).'&columnID='.urlencode($targetColumnID).'&mode=scores';
    } elseif ($action === 'review') {
        $newURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_review.php&draftID='.urlencode($scoreLogDraftID).'&return=success1';
    } else {
        $newURL = $viewURL.'&draftID='.urlencode($scoreLogDraftID).'&return=success1';
    }
    header('Location: '.$newURL);
    exit;
} catch (\Throwable $e) {
    if ($connection2->inTransaction()) {
        $connection2->rollBack();
    }

    $redirect($e->getMessage() === 'Stale draft' ? 'error4' : 'error1', $scoreLogDraftID);
}
