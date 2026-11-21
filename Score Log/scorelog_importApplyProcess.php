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
use Gibbon\Session\TokenHandler;

require_once __DIR__.'/../../gibbon.php';
require_once __DIR__.'/moduleFunctions.php';

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$scoreLogDraftID = is_scalar($_POST['draftID'] ?? null) ? trim((string) $_POST['draftID']) : '';
$columnID = is_scalar($_POST['columnID'] ?? null) ? trim((string) $_POST['columnID']) : '';
$previewToken = is_scalar($_POST['previewToken'] ?? null) ? trim((string) $_POST['previewToken']) : '';
$viewURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_view.php';
$importURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_import.php&draftID='.urlencode($scoreLogDraftID).'&columnID='.urlencode($columnID);
$redirect = function (string $return, bool $toDraft = false) use ($viewURL, $importURL, $scoreLogDraftID) {
    if ($toDraft) {
        $url = $viewURL.'&draftID='.urlencode($scoreLogDraftID);
    } else {
        $url = $importURL.'&previewToken='.urlencode((string) ($_POST['previewToken'] ?? ''));
    }
    header('Location: '.$url.'&return='.$return);
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
$previewRedirect = function (string $return) use ($session, $scoreLogDraftID, $columnID, $input) {
    $query = [
        'q'               => '/modules/Score Log/scorelog_import.php',
        'draftID'         => $scoreLogDraftID,
        'columnID'        => $columnID,
        'previewToken'    => scoreLogInputString($input, 'previewToken'),
        'identifierColumn'=> scoreLogInputString($input, 'identifierColumn'),
        'identifierType'  => scoreLogInputString($input, 'identifierType'),
        'scoreColumn'     => scoreLogInputString($input, 'scoreColumn'),
        'commentColumn'   => scoreLogInputString($input, 'commentColumn'),
        'return'          => $return,
    ];

    header('Location: '.$session->get('absoluteURL').'/index.php?'.http_build_query($query));
    exit;
};
$draft = $scoreLogDraftID !== '' ? scoreLogLoadDraft($pdo, $scoreLogDraftID, $gibbonPersonID) : null;
if (
    empty($draft)
    || (string) $draft['gibbonSchoolYearID'] !== $gibbonSchoolYearID
    || !scoreLogCanEditClass($pdo, $gibbonPersonID, $draft['gibbonCourseClassID'], $gibbonSchoolYearID)
) {
    $redirect('error0');
}
if ($columnID === '' || empty($draft['payloadData']['columns'][$columnID])) {
    $redirect('error1');
}

$previews = $session->get('scoreLogImportPreviews', []);
$previewMeta = $previewToken !== '' ? ($previews[$previewToken] ?? null) : null;
if (
    empty($previewMeta)
    || (string) ($previewMeta['draftID'] ?? '') !== $scoreLogDraftID
    || (string) ($previewMeta['columnID'] ?? '') !== $columnID
    || !scoreLogSamePersonID($previewMeta['gibbonPersonID'] ?? '', $gibbonPersonID)
    || (int) ($previewMeta['createdAt'] ?? 0) < time() - 1800
) {
    $redirect('error1');
}
if ((int) ($previewMeta['revision'] ?? 0) !== (int) $draft['revision']) {
    $previewRedirect('error6');
}

$identifierColumn = is_scalar($input['identifierColumn'] ?? null) && is_numeric($input['identifierColumn'])
    ? (int) $input['identifierColumn']
    : -1;
$identifierType = (string) ($input['identifierType'] ?? '');
$scoreColumn = is_scalar($input['scoreColumn'] ?? null) && is_numeric($input['scoreColumn'])
    ? (int) $input['scoreColumn']
    : -1;
$commentColumn = is_scalar($input['commentColumn'] ?? null) && is_numeric($input['commentColumn'])
    ? (int) $input['commentColumn']
    : null;
$columnCount = max(1, count($previewMeta['matrix'][0] ?? []));
if (
    $identifierColumn < 0 || $identifierColumn >= $columnCount
    || $scoreColumn < 0 || $scoreColumn >= $columnCount
    || $identifierColumn === $scoreColumn
    || !in_array($identifierType, ['id', 'username', 'name'], true)
    || ($commentColumn !== null && ($commentColumn < 0 || $commentColumn >= $columnCount))
) {
    $previewRedirect('error1');
}
if ($commentColumn === $identifierColumn || $commentColumn === $scoreColumn) {
    $commentColumn = null;
}

$initialMapping = $previewMeta['mapping'];
$mapping = [
    'identifierColumn' => $identifierColumn,
    'identifierType'   => $identifierType,
    'scoreColumn'      => $scoreColumn,
    'commentColumn'    => $commentColumn,
    'headerRow'        => $initialMapping['headerRow'] ?? null,
    'headerDetected'   => (bool) ($initialMapping['headerDetected'] ?? false),
    'columnCount'      => $columnCount,
];

$roster = scoreLogCurrentRoster($pdo, $draft['gibbonCourseClassID']);
$columnConfig = $draft['payloadData']['columns'][$columnID];
$scaleID = (string) ($columnConfig['scaleID'] ?? '');
$scale = $pdo->selectOne(
    "SELECT gibbonScaleID, name, `usage`, `numeric`
     FROM gibbonScale WHERE gibbonScaleID=:gibbonScaleID AND active='Y'",
    ['gibbonScaleID' => $scaleID]
);
if (empty($scale)) {
    $redirect('error1');
}
$savedScores = [];
foreach ($roster as $student) {
    $studentID = (string) $student['gibbonPersonID'];
    $savedScores[$studentID] = $draft['payloadData']['students'][$studentID]['scores'][$columnID]
        ?? ['attainmentValue' => '', 'comment' => ''];
}
$grades = $pdo->select(
    "SELECT value, descriptor FROM gibbonScaleGrade WHERE gibbonScaleID=:gibbonScaleID",
    ['gibbonScaleID' => $scaleID]
)->fetchAll();
$gradeDescriptors = [];
foreach ($grades as $grade) {
    $gradeDescriptors[(string) $grade['value']] = $grade['descriptor'];
}
$preview = scoreLogBuildImportPreview(
    $previewMeta['matrix'],
    $mapping,
    ['students' => $savedScores],
    $roster,
    ['usage' => $scale['usage'], 'numeric' => $scale['numeric']],
    $gradeDescriptors,
    $previewMeta['formulaCells'] ?? []
);
$expectedHash = scoreLogInputString($input, 'previewHash');
$actualHash = scoreLogImportPreviewHash($mapping, $preview, $draft['revision']);
if ($expectedHash === '' || !hash_equals($actualHash, $expectedHash)) {
    $previewRedirect('error7');
}

$skipped = scoreLogImportSkippedCount($preview['counts']);
if ($skipped > 0 && scoreLogInputString($input, 'confirmSkipped') !== 'Y') {
    $previewRedirect('error8');
}
if (empty($preview['updates'])) {
    $redirect('error1', true);
}

$payload = $draft['payloadData'];
foreach ($preview['updates'] as $studentID => $changes) {
    if (!isset($payload['students'][$studentID]) || !is_array($payload['students'][$studentID])) {
        $payload['students'][$studentID] = ['scores' => []];
    }
    if (!isset($payload['students'][$studentID]['scores'])
        || !is_array($payload['students'][$studentID]['scores'])) {
        $payload['students'][$studentID]['scores'] = [];
    }
    if (!isset($payload['students'][$studentID]['scores'][$columnID])
        || !is_array($payload['students'][$studentID]['scores'][$columnID])) {
        $payload['students'][$studentID]['scores'][$columnID] = [
            'attainmentValue' => '',
            'comment' => '',
        ];
    }
    foreach (['attainmentValue', 'comment'] as $field) {
        if (array_key_exists($field, $changes)) {
            $payload['students'][$studentID]['scores'][$columnID][$field] = (string) $changes[$field];
        }
    }
}

$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($payloadJson === false || strlen($payloadJson) > 1048576) {
    $previewRedirect('error1');
}

$connection2->beginTransaction();
try {
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
            'revision'        => $draft['revision'],
        ]
    );
    if ((int) $updated !== 1) {
        throw new RuntimeException('Stale draft');
    }
    $connection2->commit();
} catch (\Throwable $e) {
    if ($connection2->inTransaction()) {
        $connection2->rollBack();
    }
    if ($e->getMessage() === 'Stale draft') {
        $previewRedirect('error6');
    }
    $previewRedirect('error1');
}

unset($previews[$previewToken]);
$session->set('scoreLogImportPreviews', $previews);
$redirect('success3', true);
