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

use Gibbon\Forms\Form;

require_once __DIR__.'/moduleFunctions.php';

$page->breadcrumbs
    ->add(scoreLogT('Create Internal Assessment'), 'scorelog_view.php')
    ->add(scoreLogT('Import Scores'));

$page->return->addReturns([
    'success0' => scoreLogT('Import parsed. Review the detected columns before applying it to the draft.'),
    'success1' => scoreLogT('Draft saved. Choose an import file or paste a table for this assessment column.'),
    'error0'   => scoreLogT('You do not have permission to perform this action.'),
    'error1'   => scoreLogT('The import data is invalid or empty.'),
    'error2'   => scoreLogT('The spreadsheet could not be read.'),
    'error3'   => scoreLogT('Choose a CSV/XLSX file or paste a table.'),
    'error4'   => scoreLogT('The import exceeds the file, row or paste-size limit.'),
    'error5'   => scoreLogT('Only CSV and XLSX files are supported.'),
    'error6'   => scoreLogT('The draft changed after the import preview. Reopen the draft and import again.'),
    'error7'   => scoreLogT('The import mapping changed after preview. Refresh the preview before applying.'),
    'error8'   => scoreLogT('Confirm that unmatched or invalid import rows will be skipped.'),
    'error9'   => scoreLogT('Your session expired. Refresh the page and try again.'),
]);

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$scoreLogDraftID = is_scalar($_GET['draftID'] ?? null) ? trim((string) $_GET['draftID']) : '';
$columnID = is_scalar($_GET['columnID'] ?? null) ? trim((string) $_GET['columnID']) : '';
$previewToken = is_scalar($_GET['previewToken'] ?? null) ? trim((string) $_GET['previewToken']) : '';

if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
) {
    $page->addError(scoreLogT('You do not have access to this page.'));
    return;
}

$draft = $scoreLogDraftID !== '' ? scoreLogLoadDraft($pdo, $scoreLogDraftID, $gibbonPersonID) : null;
if (
    empty($draft)
    || (string) $draft['gibbonSchoolYearID'] !== $gibbonSchoolYearID
    || !scoreLogCanEditClass($pdo, $gibbonPersonID, $draft['gibbonCourseClassID'], $gibbonSchoolYearID)
) {
    $page->addError(scoreLogT('The specified record does not exist.'));
    return;
}
if ($columnID === '' || empty($draft['payloadData']['columns'][$columnID])) {
    if ($columnID !== '') {
        $page->addError(scoreLogT('Select the assessment column to import.'));
    }

    $scaleNames = $pdo->select("SELECT gibbonScaleID AS value, name FROM gibbonScale")->fetchKeyPair();
    $columnOptions = [];
    foreach ($draft['payloadData']['columnOrder'] as $candidateID) {
        $column = $draft['payloadData']['columns'][$candidateID] ?? [];
        $name = trim((string) ($column['name'] ?? '')) ?: scoreLogT('Untitled draft');
        $scaleName = $scaleNames[(string) ($column['scaleID'] ?? '')] ?? '';
        $columnOptions[(string) $candidateID] = $name.($scaleName !== '' ? ' — '.$scaleName : '');
    }

    $targetForm = Form::create('scoreLogImportTarget', $session->get('absoluteURL').'/index.php', 'get');
    $targetForm->setTitle(scoreLogT('Choose Assessment Column for Import'));
    $targetForm->setDescription(scoreLogT('Import and template operations always target one explicit assessment column.'));
    $targetForm->addHiddenValue('q', '/modules/Score Log/scorelog_import.php');
    $targetForm->addHiddenValue('draftID', $scoreLogDraftID);
    $row = $targetForm->addRow();
    $row->addLabel('columnID', scoreLogT('Assessment'));
    $row->addSelect('columnID')->fromArray($columnOptions)->required()->placeholder(scoreLogT('Please select...'));
    $row = $targetForm->addRow();
    $row->addFooter();
    $row->addSubmit(scoreLogT('Continue'));
    $page->write($targetForm->getOutput());
    return;
}

$actionForm = Form::createBlank('scoreLogImportActions');
$actionForm->addHeaderAction('back', scoreLogT('Back to Draft'))
    ->setURL('/modules/Score Log/scorelog_view.php')
    ->addParam('draftID', $scoreLogDraftID)
    ->displayLabel();
$actionForm->addHeaderAction('template', scoreLogT('Download XLSX Template'))
    ->setURL('/modules/Score Log/scorelog_importTemplate.php')
    ->addParam('draftID', $scoreLogDraftID)
    ->addParam('columnID', $columnID)
    ->setIcon('download')
    ->directLink()
    ->displayLabel();
$page->write($actionForm->getOutput());

$previews = $session->get('scoreLogImportPreviews', []);
$previewMeta = $previewToken !== '' ? ($previews[$previewToken] ?? null) : null;
if (empty($previewMeta)
    || (string) ($previewMeta['draftID'] ?? '') !== $scoreLogDraftID
    || (string) ($previewMeta['columnID'] ?? '') !== $columnID
    || !scoreLogSamePersonID($previewMeta['gibbonPersonID'] ?? '', $gibbonPersonID)
    || (int) ($previewMeta['createdAt'] ?? 0) < time() - 1800
) {
    $form = Form::create(
        'scoreLogImport',
        $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_importProcess.php',
        'post'
    );
    $form->setTitle(scoreLogT('Import Scores into Draft'));
    $form->setDescription(scoreLogT('Upload CSV/XLSX or paste from a spreadsheet. Two columns are enough: student name/ID/username and score. A third column may contain comments.'));
    $form->addHiddenValue('scoreLogDraftID', $scoreLogDraftID);
    $form->addHiddenValue('columnID', $columnID);

    $row = $form->addRow();
    $row->addLabel('file', scoreLogT('CSV or XLSX File'));
    $row->addFileUpload('file')->setMaxUpload(false);
    $row = $form->addRow();
    $row->addLabel('paste', scoreLogT('Paste from Spreadsheet'))->description(scoreLogT('Paste tab-separated rows. Leave the file field empty.'));
    $row->addTextArea('paste')->setRows(8)->maxLength(524288);
    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(scoreLogT('Parse Import'));

    $page->write($form->getOutput());
    return;
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
    $page->addError(scoreLogT('The selected assessment scale is not active.'));
    return;
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

$initialMapping = is_array($previewMeta['mapping'] ?? null) ? $previewMeta['mapping'] : [];
$columnCount = max(1, count($previewMeta['matrix'][0] ?? []));
$validColumn = function ($value) use ($columnCount) {
    return $value !== '' && is_numeric($value) && (int) $value >= 0 && (int) $value < $columnCount;
};
$hasMappingInput = array_key_exists('identifierColumn', $_GET);
$identifierColumn = $hasMappingInput && $validColumn($_GET['identifierColumn'] ?? null)
    ? (int) $_GET['identifierColumn']
    : ($initialMapping['identifierColumn'] ?? null);
$identifierType = in_array($_GET['identifierType'] ?? '', ['id', 'username', 'name'], true)
    ? (string) $_GET['identifierType']
    : (string) ($initialMapping['identifierType'] ?? '');
$scoreColumn = $hasMappingInput && $validColumn($_GET['scoreColumn'] ?? null)
    ? (int) $_GET['scoreColumn']
    : ($initialMapping['scoreColumn'] ?? null);
$mappingConflict = $identifierColumn !== null && $scoreColumn !== null && $identifierColumn === $scoreColumn;
if ($mappingConflict) {
    $scoreColumn = null;
}
$commentInput = $hasMappingInput && ($_GET['commentColumn'] ?? '') !== '' && $validColumn($_GET['commentColumn'] ?? null)
    ? (int) $_GET['commentColumn']
    : null;
if (!$hasMappingInput) {
    $commentColumn = $initialMapping['commentColumn'] ?? null;
} else {
    $commentColumn = $commentInput;
}
if ($commentColumn !== null && ($commentColumn === $identifierColumn || $commentColumn === $scoreColumn)) {
    $commentColumn = null;
}
$overwrite = ($_GET['overwrite'] ?? 'N') === 'Y';
$mapping = [
    'identifierColumn' => $identifierColumn,
    'identifierType'   => $identifierType,
    'scoreColumn'      => $scoreColumn,
    'commentColumn'    => $commentColumn,
    'headerRow'        => $initialMapping['headerRow'] ?? null,
    'headerDetected'   => (bool) ($initialMapping['headerDetected'] ?? false),
    'columnCount'      => $columnCount,
];
$preview = scoreLogBuildImportPreview(
    $previewMeta['matrix'],
    $mapping,
    ['students' => $savedScores],
    $roster,
    ['usage' => $scale['usage'], 'numeric' => $scale['numeric']],
    $gradeDescriptors,
    $previewMeta['formulaCells'] ?? [],
    $overwrite
);
$previewHash = scoreLogImportPreviewHash($mapping, $preview, $draft['revision']);
$skipped = scoreLogImportSkippedCount($preview['counts']);
$mappingReady = $identifierColumn !== null && $identifierType !== '' && $scoreColumn !== null;
$previewStale = (int) ($previewMeta['revision'] ?? 0) !== (int) $draft['revision'];
$mappedColumns = array_filter([
    $identifierColumn,
    $scoreColumn,
    $commentColumn,
], function ($column) {
    return $column !== null;
});
$unusedColumns = max(0, $columnCount - count(array_unique($mappedColumns)));

$output = '<div class="schoolCommunityImportSummary">'
    . '<h2>'.htmlPrep((string) $previewMeta['sourceLabel']).'</h2>'
    . '<p><strong>'.scoreLogT('Target Assessment').':</strong> '.htmlPrep((string) ($columnConfig['name'] ?? '')).' — '.htmlPrep((string) $scale['name']).'</p>'
    . '<p>'.sprintf(scoreLogT('%s data rows detected. Extra columns: %s.'), count($preview['rows']), (int) $previewMeta['ignoredColumns']).'</p>'
    . ($mapping['headerDetected']
        ? '<p>'.scoreLogT('A recognised header row was detected and skipped.').'</p>'
        : '<p>'.scoreLogT('No header row was required; columns were detected from the data.').'</p>')
    . '<p>'.sprintf(scoreLogT('Extra unmapped columns ignored: %s.'), $unusedColumns).'</p>'
    . '</div>';

if (!empty($initialMapping['confidence'])) {
    $output .= '<div class="schoolCommunityImportConfidence"><strong>'.scoreLogT('Detected mapping').':</strong><ul>';
    foreach ($initialMapping['confidence'] as $confidence) {
        $output .= '<li>'.sprintf(
            scoreLogT('%s: %s/%s rows matched as %s.'),
            htmlPrep(scoreLogImportColumnLabel($confidence['column'])),
            (int) $confidence['matched'],
            (int) $confidence['total'],
            htmlPrep(scoreLogImportRoleLabel($confidence['role']))
        ).'</li>';
    }
    $output .= '</ul></div>';
}

$mapForm = Form::create('scoreLogImportMapping', $session->get('absoluteURL').'/index.php', 'get');
$mapForm->setTitle(scoreLogT('Confirm Import Mapping'));
$mapForm->setDescription(scoreLogT('Correct the detected columns before applying imported values. The preview updates only after Refresh Preview.'));
$mapForm->addHiddenValue('q', '/modules/Score Log/scorelog_import.php');
$mapForm->addHiddenValue('draftID', $scoreLogDraftID);
$mapForm->addHiddenValue('columnID', $columnID);
$mapForm->addHiddenValue('previewToken', $previewToken);

$columnOptions = [];
for ($column = 0; $column < $columnCount; $column++) {
    $columnOptions[(string) $column] = scoreLogImportColumnLabel($column);
}
$row = $mapForm->addRow();
$row->addLabel('identifierColumn', scoreLogT('Identifier Column'));
$row->addSelect('identifierColumn')->fromArray($columnOptions)->selected($identifierColumn === null ? '' : (string) $identifierColumn)->placeholder();
$row = $mapForm->addRow();
$row->addLabel('identifierType', scoreLogT('Identifier Type'));
$row->addSelect('identifierType')->fromArray([
    'id' => scoreLogT('Student ID'),
    'username' => scoreLogT('Username'),
    'name' => scoreLogT('Student Name'),
])->selected($identifierType)->placeholder();
$row = $mapForm->addRow();
$row->addLabel('scoreColumn', scoreLogT('Score Column'));
$row->addSelect('scoreColumn')->fromArray($columnOptions)->selected($scoreColumn === null ? '' : (string) $scoreColumn)->placeholder();
$row = $mapForm->addRow();
$row->addLabel('commentColumn', scoreLogT('Comment Column'))->description(scoreLogT('Optional. Extra columns are ignored.'));
$row->addSelect('commentColumn')->fromArray($columnOptions)->selected($commentColumn === null ? '' : (string) $commentColumn)->placeholder();
$row = $mapForm->addRow();
$row->addLabel('overwrite', scoreLogT('Overwrite or Clear Existing Values'))
    ->description(scoreLogT('Unchecked preserves existing values. Checked overwrites changed values and clears an existing mapped Score or Comment when the imported cell is blank.'));
$row->addCheckbox('overwrite')->setValue('Y')->checked($overwrite);
$row = $mapForm->addRow();
$row->addFooter();
$row->addSubmit(scoreLogT('Refresh Preview'));
$output .= $mapForm->getOutput();

$counts = $preview['counts'];
$output .= '<div class="schoolCommunityImportCounts">'
    . '<div><span>'.scoreLogT('Rows').'</span><strong>'.$counts['total'].'</strong></div>'
    . '<div><span>'.scoreLogT('Matched').'</span><strong>'.$counts['matched'].'</strong></div>'
    . '<div><span>'.scoreLogT('Would Fill').'</span><strong>'.$counts['wouldFill'].'</strong></div>'
    . '<div><span>'.scoreLogT('Would Overwrite').'</span><strong>'.$counts['wouldOverwrite'].'</strong></div>'
    . '<div><span>'.scoreLogT('Would Clear').'</span><strong>'.$counts['wouldClear'].'</strong></div>'
    . '<div><span>'.scoreLogT('Preserved Manual Values').'</span><strong>'.$counts['preserved'].'</strong></div>'
    . '<div><span>'.scoreLogT('Skipped / Invalid').'</span><strong>'.$skipped.'</strong></div>'
    . '</div>';

$output .= '<table class="schoolCommunityImportTable"><thead><tr>'
    . '<th>'.scoreLogT('Row').'</th><th>'.scoreLogT('Identifier').'</th><th>'.scoreLogT('Student').'</th>'
    . '<th>'.scoreLogT('Imported').'</th><th>'.scoreLogT('Existing').'</th><th>'.scoreLogT('Status').'</th>'
    . '</tr></thead><tbody>';
foreach ($preview['rows'] as $row) {
    $status = [
        'wouldFill' => scoreLogT('Would fill'),
        'wouldOverwrite' => scoreLogT('Would overwrite'),
        'wouldClear' => scoreLogT('Would clear'),
        'preserved' => scoreLogT('Manual value preserved'),
        'unknown' => scoreLogT('Unknown student'),
        'ambiguous' => scoreLogT('Ambiguous student'),
        'duplicate' => scoreLogT('Duplicate student'),
        'invalid' => scoreLogT('Invalid row'),
        'noChange' => scoreLogT('No change'),
    ][$row['status']] ?? $row['status'];
    $imported = htmlPrep($row['importScore'] !== '' ? $row['importScore'] : '-');
    if ($row['importComment'] !== '') {
        $imported .= '<br><small>'.htmlPrep($row['importComment']).'</small>';
    }
    $existing = htmlPrep($row['existingScore'] !== '' ? $row['existingScore'] : '-');
    if ($row['existingComment'] !== '') {
        $existing .= '<br><small>'.htmlPrep($row['existingComment']).'</small>';
    }
    $output .= '<tr>'
        . '<td>'.$row['rowNumber'].'</td>'
        . '<td>'.htmlPrep($row['identifier']).'</td>'
        . '<td>'.htmlPrep($row['studentName']).'</td>'
        . '<td>'.$imported.'</td>'
        . '<td>'.$existing.'</td>'
        . '<td><span class="schoolCommunityImportStatus schoolCommunityImportStatus-'.$row['status'].'">'.htmlPrep($status).'</span>'
        . (!empty($row['overwrites']) ? '<br><small>'.sprintf(scoreLogT('Overwrites: %s'), htmlPrep(implode(', ', $row['overwrites']))).'</small>' : '')
        . (!empty($row['clears']) ? '<br><small>'.sprintf(scoreLogT('Clears: %s'), htmlPrep(implode(', ', $row['clears']))).'</small>' : '')
        . (!empty($row['errors']) ? '<br><small>'.htmlPrep(implode(' ', $row['errors'])).'</small>' : '')
        . '</td></tr>';
}
$output .= '</tbody></table>';

if ($mappingConflict) {
    $output .= '<div class="error">'.scoreLogT('The identifier and score must use different columns.').'</div>';
}

if ($previewStale) {
    $output .= '<div class="error">'.scoreLogT('The draft changed after the import preview. Reopen the draft and import again.').'</div>';
} elseif (!$mappingReady) {
    $output .= '<div class="warning">'.scoreLogT('Choose the identifier column/type and score column, then refresh the preview.').'</div>';
} elseif (empty($preview['updates'])) {
    $output .= '<div class="warning">'.scoreLogT('This import would not change the draft. Adjust the mapping or overwrite setting if necessary.').'</div>';
} else {
    $applyForm = Form::create(
        'scoreLogImportApply',
        $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_importApplyProcess.php',
        'post'
    );
    $applyForm->setTitle(scoreLogT('Apply to Draft'));
    $applyForm->addHiddenValue('draftID', $scoreLogDraftID);
    $applyForm->addHiddenValue('columnID', $columnID);
    $applyForm->addHiddenValue('previewToken', $previewToken);
    $applyForm->addHiddenValue('previewHash', $previewHash);
    $applyForm->addHiddenValue('identifierColumn', (string) $identifierColumn);
    $applyForm->addHiddenValue('identifierType', $identifierType);
    $applyForm->addHiddenValue('scoreColumn', (string) $scoreColumn);
    $applyForm->addHiddenValue('commentColumn', $commentColumn === null ? '' : (string) $commentColumn);
    $applyForm->addHiddenValue('overwrite', $overwrite ? 'Y' : 'N');
    $applyForm->addHiddenValue('revision', (string) $draft['revision']);

    if ($skipped > 0) {
        $row = $applyForm->addRow();
        $row->addCheckbox('confirmSkipped')->setValue('Y')->required()
            ->description(sprintf(scoreLogT('I understand that %s invalid or unmatched rows will not be applied.'), $skipped));
    }
    $row = $applyForm->addRow();
    $row->addContent(sprintf(
        scoreLogT('Apply %s changed student rows to the private draft?'),
        count($preview['updates'])
    ));
    $row = $applyForm->addRow();
    $row->addFooter();
    $row->addSubmit(scoreLogT('Apply to Draft'));
    $output .= $applyForm->getOutput();
}

$page->write($output);
