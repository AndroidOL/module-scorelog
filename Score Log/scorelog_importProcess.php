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
$scoreLogDraftID = is_scalar($_POST['scoreLogDraftID'] ?? null) ? trim((string) $_POST['scoreLogDraftID']) : '';
$columnID = is_scalar($_POST['columnID'] ?? null) ? trim((string) $_POST['columnID']) : '';
$importURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_import.php&draftID='.urlencode($scoreLogDraftID).'&columnID='.urlencode($columnID);
$redirect = function (string $return) use ($importURL) {
    header('Location: '.$importURL.'&return='.$return);
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

$input = $container->get(Validator::class)->sanitize($_POST);
$paste = scoreLogInputString($input, 'paste');
$upload = $_FILES['file'] ?? null;
$hasUpload = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($hasUpload && $paste !== '') {
    $redirect('error1');
}
if (!$hasUpload && $paste === '') {
    $redirect('error3');
}

try {
    if ($hasUpload) {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || empty($upload['tmp_name'])
            || !is_uploaded_file($upload['tmp_name'])
            || (int) ($upload['size'] ?? 0) > 2097152
            || filesize((string) $upload['tmp_name']) > 2097152
        ) {
            throw new RuntimeException('Invalid upload');
        }

        $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            throw new RuntimeException('Unsupported file type');
        }
        $parsed = scoreLogParseImportFile((string) $upload['tmp_name'], $extension);
        $sourceLabel = basename((string) ($upload['name'] ?? 'import.'.$extension));
    } else {
        $parsed = scoreLogParseImportPaste($paste);
        $sourceLabel = scoreLogT('Pasted table');
    }
} catch (\Throwable $e) {
    $codes = [
        'Invalid upload'      => 'error4',
        'Unsupported file type' => 'error5',
        'Too many rows'       => 'error4',
        'Paste too large'     => 'error4',
        'Unable to read file' => 'error2',
        'Unable to read workbook' => 'error2',
    ];
    $redirect($codes[$e->getMessage()] ?? 'error2');
}

if (empty($parsed['matrix']) || empty($parsed['rowCount'])) {
    $redirect('error1');
}

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
$grades = $pdo->select(
    "SELECT value, descriptor FROM gibbonScaleGrade WHERE gibbonScaleID=:gibbonScaleID",
    ['gibbonScaleID' => $scaleID]
)->fetchAll();
$gradeDescriptors = [];
foreach ($grades as $grade) {
    $gradeDescriptors[(string) $grade['value']] = $grade['descriptor'];
}
$scale = ['usage' => $scale['usage'], 'numeric' => $scale['numeric']];
$mapping = scoreLogDetectImportMapping($parsed['matrix'], $roster, $scale, $gradeDescriptors);

$previews = $session->get('scoreLogImportPreviews', []);
$now = time();
foreach ($previews as $token => $preview) {
    if (($preview['createdAt'] ?? 0) < $now - 1800) {
        unset($previews[$token]);
    }
}

$previewToken = bin2hex(random_bytes(24));
$previews[$previewToken] = [
    'draftID'       => $scoreLogDraftID,
    'columnID'      => $columnID,
    'gibbonPersonID'=> $gibbonPersonID,
    'revision'      => (int) $draft['revision'],
    'createdAt'     => $now,
    'sourceLabel'   => $sourceLabel,
    'sheetName'     => $parsed['sheetName'] ?? '',
    'matrix'        => $parsed['matrix'],
    'formulaCells'  => $parsed['formulaCells'],
    'ignoredColumns'=> (int) ($parsed['ignoredColumns'] ?? 0),
    'mapping'       => $mapping,
];
$session->set('scoreLogImportPreviews', $previews);

header(
    'Location: '.$session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_import.php'
    .'&draftID='.urlencode($scoreLogDraftID)
    .'&columnID='.urlencode($columnID)
    .'&previewToken='.urlencode($previewToken)
    .'&return=success0'
);
exit;
