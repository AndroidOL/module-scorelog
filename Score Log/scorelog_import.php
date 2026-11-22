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
use Gibbon\Tables\DataTable;

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
    'error10'  => scoreLogT('Every worksheet must use the same assessment metadata and import column headers.'),
]);

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$scoreLogDraftID = is_scalar($_GET['draftID'] ?? null) ? trim((string) $_GET['draftID']) : '';
$columnID = is_scalar($_GET['columnID'] ?? null) ? trim((string) $_GET['columnID']) : '';
$previewToken = is_scalar($_GET['previewToken'] ?? null) ? trim((string) $_GET['previewToken']) : '';

if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php')
) {
    $page->addError(scoreLogT('You do not have access to this page.'));
    return;
}

// Class-context import: no draft yet, so provision one from the chosen class + scale and continue.
if ($scoreLogDraftID === '' && $columnID === '') {
    $ctxClassID = is_scalar($_GET['gibbonCourseClassID'] ?? null) ? trim((string) $_GET['gibbonCourseClassID']) : '';
    $ctxScaleID = is_scalar($_GET['gibbonScaleIDAttainment'] ?? null) ? trim((string) $_GET['gibbonScaleIDAttainment']) : '';
    if ($ctxClassID !== '' && $ctxScaleID !== '') {
        if (!scoreLogCanEditClass($pdo, $gibbonPersonID, $ctxClassID, $gibbonSchoolYearID)) {
            $page->addError(scoreLogT('You do not have access to this page.'));
            return;
        }
        $scale = $pdo->selectOne(
            "SELECT gibbonScaleID FROM gibbonScale WHERE gibbonScaleID=:gibbonScaleID AND active='Y'",
            ['gibbonScaleID' => $ctxScaleID]
        );
        $roster = $scale ? scoreLogCurrentRoster($pdo, $ctxClassID) : [];
        if (empty($scale) || empty($roster)) {
            $page->addError(scoreLogT('There are no records to display.'));
            return;
        }
        $settingGateway = $container->get(\Gibbon\Domain\System\SettingGateway::class);
        $allowedTypes = scoreLogAllowedTypes($settingGateway->getSettingByScope('Formal Assessment', 'internalAssessmentTypes'));
        $payload = [
            'version'      => 2,
            'columns'      => [
                'c1' => [
                    'name'             => '',
                    'description'      => '',
                    'type'             => (string) ($allowedTypes[0] ?? ''),
                    'scaleID'          => $ctxScaleID,
                    'completeDate'     => date('Y-m-d'),
                    'viewableStudents' => 'Y',
                    'viewableParents'  => 'Y',
                ],
            ],
            'columnOrder'  => ['c1'],
            'students'     => [],
            'studentNames' => [],
            'rosterIDs'    => [],
        ];
        $inserted = $pdo->insert(
            "INSERT INTO score_log_draft
             SET gibbonPersonIDCreator=:gibbonPersonID, gibbonCourseClassID=:gibbonCourseClassID,
                 gibbonScaleIDAttainment=:gibbonScaleIDAttainment, revision=1, payload=:payload",
            [
                'gibbonPersonID'          => $gibbonPersonID,
                'gibbonCourseClassID'     => $ctxClassID,
                'gibbonScaleIDAttainment' => $ctxScaleID,
                'payload'                 => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        if (empty($inserted)) {
            $page->addError(scoreLogT('Unable to save the draft.'));
            return;
        }
        header('Location: '.$session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_import.php'
            .'&draftID='.urlencode((string) $inserted).'&columnID=c1&return=success1');
        exit;
    }
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
if ($columnID === '' && count((array) ($draft['payloadData']['columnOrder'] ?? [])) === 1) {
    $columnID = (string) $draft['payloadData']['columnOrder'][0];
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

$page->navigator->addHeaderAction('back', scoreLogT('Back to Draft'))
    ->setURL('/modules/Score Log/scorelog_view.php')
    ->addParam('draftID', $scoreLogDraftID)
    ->setIcon('back')
    ->displayLabel();
$page->navigator->addHeaderAction('exportTemplate', scoreLogT('Export Template'))
    ->setURL('/modules/Score Log/scorelog_importTemplate.php')
    ->addParam('draftID', $scoreLogDraftID)
    ->addParam('columnID', $columnID)
    ->addParam('mode', 'template')
    ->setIcon('download')
    ->directLink()
    ->displayLabel();

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
$columnCount = max(1, (int) ($previewMeta['mapping']['columnCount'] ?? count($previewMeta['matrix'][0] ?? [])));
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
    $previewMeta['formulaCells'] ?? []
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

$output = '<p><strong>'.scoreLogT('Source').':</strong> '.htmlPrep((string) $previewMeta['sourceLabel']).'</p>'
    . '<p><strong>'.scoreLogT('Target Assessment').':</strong> '.htmlPrep((string) ($columnConfig['name'] ?? '')).' — '.htmlPrep((string) $scale['name']).'</p>'
    . '<p>'.sprintf(scoreLogT('%s data rows detected. Extra columns: %s.'), count($preview['rows']), (int) $previewMeta['ignoredColumns']).'</p>'
    . ($mapping['headerDetected']
        ? '<p>'.scoreLogT('A recognised header row was detected and skipped.').'</p>'
        : '<p>'.scoreLogT('No header row was required; columns were detected from the data.').'</p>')
    . '<p>'.sprintf(scoreLogT('Extra unmapped columns ignored: %s.'), $unusedColumns).'</p>';

if (!empty($initialMapping['confidence'])) {
    $output .= '<p><strong>'.scoreLogT('Detected mapping').':</strong></p><ul>';
    foreach ($initialMapping['confidence'] as $confidence) {
        $output .= '<li>'.sprintf(
            scoreLogT('%s: %s/%s rows matched as %s.'),
            htmlPrep(scoreLogImportColumnLabel($confidence['column'])),
            (int) $confidence['matched'],
            (int) $confidence['total'],
            htmlPrep(scoreLogImportRoleLabel($confidence['role']))
        ).'</li>';
    }
    $output .= '</ul>';
}

$detectedMetadata = is_array($previewMeta['metadata'] ?? null) ? $previewMeta['metadata'] : [];
if (!empty($detectedMetadata)) {
    $metadataParts = [];
    if (!empty($detectedMetadata['name'])) {
        $metadataParts[] = scoreLogT('Assessment Name').': '.htmlPrep((string) $detectedMetadata['name']);
    }
    if (!empty($detectedMetadata['description'])) {
        $metadataParts[] = scoreLogT('Description').': '.htmlPrep((string) $detectedMetadata['description']);
    }
    if (!empty($detectedMetadata['completeDate'])) {
        $metadataParts[] = scoreLogT('Assessment Date').': '.htmlPrep((string) $detectedMetadata['completeDate']);
    }
    if (!empty($metadataParts)) {
        $output .= '<p><strong>'.scoreLogT('Detected assessment details').':</strong> '.implode(' · ', $metadataParts).'</p>';
    }
}

$mapForm = Form::create('scoreLogImportMapping', $session->get('absoluteURL').'/index.php', 'get');
$mapForm->setTitle(scoreLogT('Confirm Import Mapping'));
$mapForm->setDescription(scoreLogT('Correct the detected columns before applying imported values. The preview updates only after Refresh Preview.'));
$mapForm->addHiddenValue('q', '/modules/Score Log/scorelog_import.php');
$mapForm->addHiddenValue('draftID', $scoreLogDraftID);
$mapForm->addHiddenValue('columnID', $columnID);
$mapForm->addHiddenValue('previewToken', $previewToken);

$columnOptionRows = [];
for ($column = 0; $column < $columnCount; $column++) {
    $columnOptionRows[] = ['value' => (string) $column, 'name' => scoreLogImportColumnLabel($column)];
}
$row = $mapForm->addRow();
$row->addLabel('identifierColumn', scoreLogT('Identifier Column'));
$row->addSelect('identifierColumn')->fromArray($columnOptionRows, 'value', 'name')->selected($identifierColumn === null ? '' : (string) $identifierColumn)->placeholder();
$row = $mapForm->addRow();
$row->addLabel('identifierType', scoreLogT('Identifier Type'));
$row->addSelect('identifierType')->fromArray([
    'id' => scoreLogT('Student ID'),
    'username' => scoreLogT('Username'),
    'name' => scoreLogT('Student Name'),
])->selected($identifierType)->placeholder();
$row = $mapForm->addRow();
$row->addLabel('scoreColumn', scoreLogT('Score Column'));
$row->addSelect('scoreColumn')->fromArray($columnOptionRows, 'value', 'name')->selected($scoreColumn === null ? '' : (string) $scoreColumn)->placeholder();
$row = $mapForm->addRow();
$row->addLabel('commentColumn', scoreLogT('Comment Column'))->description(scoreLogT('Optional. Extra columns are ignored.'));
$row->addSelect('commentColumn')->fromArray($columnOptionRows, 'value', 'name')->selected($commentColumn === null ? '' : (string) $commentColumn)->placeholder();
$row = $mapForm->addRow();
$row->addFooter();
$row->addSubmit(scoreLogT('Refresh Preview'));
$output .= $mapForm->getOutput();

$counts = $preview['counts'];
$countTable = DataTable::create('scoreLogImportCounts');
$countTable->setTitle(scoreLogT('Import Preview'));
$countTable->addColumn('total', scoreLogT('Rows'));
$countTable->addColumn('matched', scoreLogT('Matched'));
$countTable->addColumn('wouldFill', scoreLogT('Would Fill'));
$countTable->addColumn('wouldOverwrite', scoreLogT('Would Overwrite'));
$countTable->addColumn('preserved', scoreLogT('Unchanged'));
$countTable->addColumn('skipped', scoreLogT('Skipped / Invalid'));
$output .= $countTable->render([$counts + ['skipped' => $skipped]]);

$previewRows = [];
foreach ($preview['rows'] as $row) {
    $status = [
        'wouldFill' => scoreLogT('Would fill'),
        'wouldOverwrite' => scoreLogT('Would overwrite'),
        'preserved' => scoreLogT('Unchanged'),
        'unknown' => scoreLogT('Unknown student'),
        'ambiguous' => scoreLogT('Ambiguous student'),
        'duplicate' => scoreLogT('Duplicate student'),
        'invalid' => scoreLogT('Invalid row'),
    ][$row['status']] ?? $row['status'];
    $imported = (string) ($row['importScore'] !== '' ? $row['importScore'] : '-');
    if ($row['importComment'] !== '') {
        $imported .= ' — '.$row['importComment'];
    }
    $existing = (string) ($row['existingScore'] !== '' ? $row['existingScore'] : '-');
    if ($row['existingComment'] !== '') {
        $existing .= ' — '.$row['existingComment'];
    }
    $previewRows[] = [
        'rowNumber' => $row['rowNumber'],
        'identifier' => $row['identifier'],
        'studentName' => $row['studentName'],
        'imported' => $imported,
        'existing' => $existing,
        'status' => $status,
        'notes' => implode(' ', array_filter([
            !empty($row['overwrites']) ? sprintf(scoreLogT('Overwrites: %s'), implode(', ', $row['overwrites'])) : '',
            !empty($row['errors']) ? implode(' ', $row['errors']) : '',
        ])),
    ];
}
$previewTable = DataTable::create('scoreLogImportRows');
$previewTable->setTitle(scoreLogT('Rows'));
$previewTable->addColumn('rowNumber', scoreLogT('Row'));
$previewTable->addColumn('identifier', scoreLogT('Identifier'))->format(function ($row) {
    return htmlPrep($row['identifier']);
});
$previewTable->addColumn('studentName', scoreLogT('Student'))->format(function ($row) {
    return htmlPrep($row['studentName']);
});
$previewTable->addColumn('imported', scoreLogT('Imported'))->format(function ($row) {
    return htmlPrep($row['imported']);
});
$previewTable->addColumn('existing', scoreLogT('Existing'))->format(function ($row) {
    return htmlPrep($row['existing']);
});
$previewTable->addColumn('status', scoreLogT('Status'))->format(function ($row) {
    return htmlPrep($row['status']);
});
$previewTable->addColumn('notes', scoreLogT('Notes'))->format(function ($row) {
    return htmlPrep($row['notes']);
});
$output .= $previewTable->render($previewRows);

if ($mappingConflict) {
    $output .= '<div class="error">'.scoreLogT('The identifier and score must use different columns.').'</div>';
}

if ($previewStale) {
    $output .= '<div class="error">'.scoreLogT('The draft changed after the import preview. Reopen the draft and import again.').'</div>';
} elseif (!$mappingReady) {
    $output .= '<div class="warning">'.scoreLogT('Choose the identifier column/type and score column, then refresh the preview.').'</div>';
} elseif (empty($preview['updates'])) {
    $output .= '<div class="warning">'.scoreLogT('This import would not change the draft. Adjust the mapping or imported data if necessary.').'</div>';
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
