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

use Gibbon\Domain\System\SettingGateway;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Session\TokenHandler;
use Gibbon\Tables\DataTable;

require_once __DIR__.'/moduleFunctions.php';

$page->breadcrumbs->add(scoreLogT('Create Internal Assessment'), 'scorelog_view.php');

$page->return->addReturns([
    'success0' => scoreLogT('Assessment published.'),
    'success1' => scoreLogT('Draft saved.'),
    'success2' => scoreLogT('Draft discarded.'),
    'success3' => scoreLogT('Imported score values applied to the draft.'),
    'error0'   => scoreLogT('You do not have permission to perform this action.'),
    'error1'   => scoreLogT('The submitted data is invalid. Check the class, scale and scores.'),
    'error2'   => scoreLogT('Unable to save. Another assessment may be in progress; try again shortly.'),
    'error3'   => scoreLogT('No submitted data was received.'),
    'error4'   => scoreLogT('This draft changed in another tab. Reopen it before continuing.'),
    'error6'   => scoreLogT('The draft changed after the import preview. Reopen the draft and import again.'),
    'error7'   => scoreLogT('The import mapping changed after preview. Refresh the preview before applying.'),
    'error8'   => scoreLogT('Confirm that unmatched or invalid import rows will be skipped.'),
    'error9'   => scoreLogT('Your session expired. Refresh the page and try again.'),
    'error10'  => scoreLogT('This legacy action is no longer available. Start or resume a draft.'),
]);

if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php')
) {
    $page->addError(scoreLogT('You do not have access to this page.'));
    return;
}

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$scoreLogDraftID = is_scalar($_GET['draftID'] ?? null) ? trim((string) $_GET['draftID']) : '';
$gibbonCourseClassID = is_scalar($_GET['gibbonCourseClassID'] ?? null) ? trim((string) $_GET['gibbonCourseClassID']) : '';
$gibbonScaleIDAttainment = is_scalar($_GET['gibbonScaleIDAttainment'] ?? null) ? trim((string) $_GET['gibbonScaleIDAttainment']) : '';
$settingGateway = $container->get(SettingGateway::class);
$allowedTypes = scoreLogAllowedTypes($settingGateway->getSettingByScope('Formal Assessment', 'internalAssessmentTypes'));
if (empty($allowedTypes)) {
    $page->addError(scoreLogT('No Formal Assessment types are configured. Ask an administrator to update Formal Assessment settings.'));
    return;
}
$defaultType = $allowedTypes[0];

// Module landing: choose a class or start from an existing draft.
if ($scoreLogDraftID === '' && ($gibbonCourseClassID === '' || $gibbonScaleIDAttainment === '')) {
    $page->navigator->addHeaderAction('savedDrafts', scoreLogT('Saved Drafts'))
        ->setURL('/modules/Score Log/scorelog_drafts.php')
        ->setIcon('markbook')
        ->displayLabel();
    $draftRows = [];
    $drafts = $pdo->select(
        "SELECT d.scoreLogDraftID, d.gibbonCourseClassID, d.gibbonScaleIDAttainment, d.revision, d.payload, d.updateTimestamp,
                CONCAT(course.nameShort, '.', cc.nameShort) AS className
         FROM score_log_draft d
         JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=d.gibbonCourseClassID
         JOIN gibbonCourse course ON course.gibbonCourseID=cc.gibbonCourseID
         WHERE d.gibbonPersonIDCreator=:gibbonPersonID
           AND course.gibbonSchoolYearID=:gibbonSchoolYearID
         ORDER BY d.updateTimestamp DESC",
        ['gibbonPersonID' => $gibbonPersonID, 'gibbonSchoolYearID' => $gibbonSchoolYearID]
    )->fetchAll();
    $scaleNames = $pdo->select("SELECT gibbonScaleID AS value, name FROM gibbonScale")->fetchKeyPair();

    foreach ($drafts as $draft) {
        if (!scoreLogCanEditClass($pdo, $gibbonPersonID, $draft['gibbonCourseClassID'], $gibbonSchoolYearID)) {
            continue;
        }
        $payload = scoreLogUpgradeDraftPayload(
            json_decode((string) $draft['payload'], true) ?: [],
            (string) $draft['gibbonScaleIDAttainment']
        );
        $progress = scoreLogDraftProgress($payload);
        $draft['title'] = trim((string) ($payload['columns']['c1']['name'] ?? '')) ?: scoreLogT('Untitled draft');
        foreach ($payload['columnOrder'] as $columnID) {
            $name = trim((string) ($payload['columns'][$columnID]['name'] ?? ''));
            if ($name !== '') {
                $draft['title'] = $name;
                break;
            }
        }
        $draft['entered'] = $progress['entered'].' / '.$progress['total'];
        $draft['scaleName'] = scoreLogDraftScaleLabels($payload, $scaleNames) ?: scoreLogT('Unassigned');
        $draftRows[] = $draft;
    }

    if (!empty($draftRows)) {
        $table = DataTable::create('scoreLogDrafts');
        $table->setTitle(scoreLogT('My Score Log Drafts'));
        $table->setDescription(scoreLogT('Drafts are private to their creator and are hidden from students, parents and reports.'));
        $table->addColumn('title', scoreLogT('Name'));
        $table->addColumn('className', scoreLogT('Class'));
        $table->addColumn('scaleName', scoreLogT('Assessment Scale'));
        $table->addColumn('entered', scoreLogT('Entered'));
        $table->addColumn('updateTimestamp', scoreLogT('Last Saved'))->format(function ($draft) {
            return Format::dateTime($draft['updateTimestamp']);
        });
        $table->addActionColumn()
            ->addParam('draftID')
            ->format(function ($draft, $actions) {
                $actions->addAction('resume', scoreLogT('Resume Draft'))
                    ->setIcon('markbook')
                    ->setURL('/modules/Score Log/scorelog_view.php');
            });
        echo $table->render($draftRows);
    }

    $form = Form::create('scoreLogClassChooser', $session->get('absoluteURL').'/index.php', 'get');
    $form->setFactory(DatabaseFormFactory::create($pdo));
    $form->addHiddenValue('q', '/modules/'.$session->get('module').'/scorelog_view.php');
    $form->setTitle(scoreLogT('Start New Assessment'));

    $classes = [];
    $myClasses = $pdo->select(
        "SELECT gibbonCourseClass.gibbonCourseClassID AS value,
                CONCAT(gibbonCourse.nameShort, '.', gibbonCourseClass.nameShort) AS name
         FROM gibbonCourseClass
         JOIN gibbonCourse ON gibbonCourseClass.gibbonCourseID=gibbonCourse.gibbonCourseID
         JOIN gibbonCourseClassPerson
           ON gibbonCourseClassPerson.gibbonCourseClassID=gibbonCourseClass.gibbonCourseClassID
         WHERE gibbonCourse.gibbonSchoolYearID=:gibbonSchoolYearID
           AND gibbonCourseClassPerson.gibbonPersonID=:gibbonPersonID
           AND gibbonCourseClassPerson.role IN ('Teacher', 'Assistant', 'Technician')
         ORDER BY name",
        ['gibbonSchoolYearID' => $gibbonSchoolYearID, 'gibbonPersonID' => $gibbonPersonID]
    )->fetchKeyPair();
    if (!empty($myClasses)) {
        $classes['--'.scoreLogT('My Classes').'--'] = $myClasses;
    }

    if (scoreLogCanViewAllClasses($gibbonPersonID)) {
        $allClasses = $pdo->select(
            "SELECT gibbonCourseClass.gibbonCourseClassID AS value,
                    CONCAT(gibbonCourse.nameShort, '.', gibbonCourseClass.nameShort) AS name
             FROM gibbonCourseClass
             JOIN gibbonCourse ON gibbonCourseClass.gibbonCourseID=gibbonCourse.gibbonCourseID
             WHERE gibbonCourse.gibbonSchoolYearID=:gibbonSchoolYearID
             ORDER BY name",
            ['gibbonSchoolYearID' => $gibbonSchoolYearID]
        )->fetchKeyPair();
        if (!empty($allClasses)) {
            $classes['--'.scoreLogT('All Classes').'--'] = $allClasses;
        }
    }

    $scales = $pdo->select(
        "SELECT gibbonScaleID AS value, name FROM gibbonScale WHERE active='Y' ORDER BY name"
    )->fetchKeyPair();

    if (empty($classes)) {
        $page->addError(scoreLogT('There are no records to display.'));
    } else {
        $row = $form->addRow();
        $row->addLabel('gibbonCourseClassID', scoreLogT('Class Name'));
        $row->addSearchSelect('gibbonCourseClassID')->fromArray($classes)->required()->placeholder(scoreLogT('Please select...'));
        $row = $form->addRow();
        $row->addLabel('gibbonScaleIDAttainment', scoreLogT('Initial Assessment Scale'));
        $row->addSelect('gibbonScaleIDAttainment')
            ->fromArray($scales)
            ->selected(scoreLogDefaultScaleID($session))
            ->required()
            ->placeholder(scoreLogT('Please select...'));
        $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit(scoreLogT('Continue'));
        echo $form->getOutput();
    }
    return;
}

if ($scoreLogDraftID !== '') {
    $page->breadcrumbs
        ->add(scoreLogT('Saved Drafts'), 'scorelog_drafts.php')
        ->add(scoreLogT('Edit Draft'));

    $draft = scoreLogLoadDraft($pdo, $scoreLogDraftID, $gibbonPersonID);
    if (
        empty($draft)
        || (string) $draft['gibbonSchoolYearID'] !== $gibbonSchoolYearID
        || !scoreLogCanEditClass($pdo, $gibbonPersonID, $draft['gibbonCourseClassID'], $gibbonSchoolYearID)
    ) {
        $page->addError(scoreLogT('The specified record does not exist.'));
        return;
    }
    $gibbonCourseClassID = (string) $draft['gibbonCourseClassID'];
    $payload = $draft['payloadData'];
    $class = ['gibbonCourseClassID' => $gibbonCourseClassID, 'name' => $draft['className']];
    $firstColumnID = (string) ($payload['columnOrder'][0] ?? '');
    $gibbonScaleIDAttainment = (string) ($payload['columns'][$firstColumnID]['scaleID'] ?? $draft['gibbonScaleIDAttainment']);
    $revision = (int) $draft['revision'];
    if (isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write.php')) {
        $page->navigator->addHeaderAction('viewMarks', scoreLogT('View Marks'))
            ->setURL('/modules/Formal Assessment/internalAssessment_write.php')
            ->addParam('gibbonCourseClassID', $gibbonCourseClassID)
            ->setIcon('markbook')
            ->displayLabel();
    }
} else {
    if (!scoreLogCanEditClass($pdo, $gibbonPersonID, $gibbonCourseClassID, $gibbonSchoolYearID)) {
        $page->addError(scoreLogT('You do not have access to this page.'));
        return;
    }
    $class = $pdo->selectOne(
        "SELECT cc.gibbonCourseClassID, CONCAT(c.nameShort, '.', cc.nameShort) AS name
         FROM gibbonCourseClass cc
         JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
         WHERE cc.gibbonCourseClassID=:gibbonCourseClassID
           AND c.gibbonSchoolYearID=:gibbonSchoolYearID",
        ['gibbonCourseClassID' => $gibbonCourseClassID, 'gibbonSchoolYearID' => $gibbonSchoolYearID]
    );
    $scale = $pdo->selectOne(
        "SELECT gibbonScaleID, name, `usage`, `numeric`
         FROM gibbonScale WHERE gibbonScaleID=:gibbonScaleID AND active='Y'",
        ['gibbonScaleID' => $gibbonScaleIDAttainment]
    );
    if (empty($class) || empty($scale)) {
        $page->addError(scoreLogT('The specified record does not exist.'));
        return;
    }
    $payload = [
        'version' => 2,
        'columns' => [
            'c1' => [
                'name'             => '',
                'description'      => '',
                'type'             => $defaultType,
                'scaleID'          => $gibbonScaleIDAttainment,
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
    $revision = 0;
}

$roster = scoreLogCurrentRoster($pdo, $gibbonCourseClassID);
if (empty($roster)) {
    $page->addError(scoreLogT('There are no records to display.'));
    return;
}
$diff = $scoreLogDraftID !== ''
    ? scoreLogRosterDiff($payload, $roster)
    : ['added' => [], 'removed' => [], 'changed' => false];

$scaleRows = $pdo->select(
    "SELECT gibbonScaleID, name, `usage`, `numeric`, active
     FROM gibbonScale ORDER BY active DESC, name"
)->fetchAll();
$scalesById = [];
$scaleOptions = [];
foreach ($scaleRows as $scaleRow) {
    $scalesById[(string) $scaleRow['gibbonScaleID']] = $scaleRow;
    $scaleOptions[(string) $scaleRow['gibbonScaleID']] = $scaleRow['name'].($scaleRow['active'] === 'Y' ? '' : ' (' . scoreLogT('Inactive') . ')');
}
$gradeRows = $pdo->select(
    "SELECT gibbonScaleID, value, descriptor, isDefault
     FROM gibbonScaleGrade ORDER BY gibbonScaleID, sequenceNumber"
)->fetchAll();
$gradesByScale = [];
foreach ($gradeRows as $gradeRow) {
    $gradesByScale[(string) $gradeRow['gibbonScaleID']][(string) $gradeRow['value']] = $gradeRow['descriptor'];
}

if ($diff['changed']) {
    echo '<div class="warning"><p><strong>'.scoreLogT('The class roster changed since this draft was saved.').'</strong></p>';
    if (!empty($diff['added'])) {
        echo '<p>'.sprintf(scoreLogT('New students: %s'), htmlPrep(implode(', ', $diff['added']))).'</p>';
    }
    if (!empty($diff['removed'])) {
        echo '<p>'.sprintf(scoreLogT('Removed students: %s'), htmlPrep(implode(', ', $diff['removed']))).'</p>';
    }
    echo '<p>'.scoreLogT('Only current students appear in the publish review.').'</p></div>';
}

$savedColumns = array_keys((array) $payload['columns']);
$columnOrder = array_slice(array_values(array_intersect((array) $payload['columnOrder'], $savedColumns)), 0, 10);
$placeholderNumber = 1;
while (count($columnOrder) < 10) {
    do {
        $columnID = 'slot'.$placeholderNumber++;
    } while (in_array($columnID, $savedColumns, true));
    $payload['columns'][$columnID] = [
        'name'             => '',
        'description'      => '',
        'type'             => $defaultType,
        'scaleID'          => $gibbonScaleIDAttainment,
        'completeDate'     => date('Y-m-d'),
        'viewableStudents' => 'Y',
        'viewableParents'  => 'Y',
    ];
    $columnOrder[] = $columnID;
}
$initialColumnCount = max(1, count(array_intersect((array) $payload['columnOrder'], $savedColumns)));
$persistedColumns = $scoreLogDraftID !== '' ? array_fill_keys($savedColumns, true) : [];
$savedStudents = (array) ($payload['students'] ?? []);
$csrfToken = $container->get(TokenHandler::class)->getCSRF();
$columnCounts = [];
foreach ($columnOrder as $columnID) {
    $column = $payload['columns'][$columnID];
    $scale = $scalesById[(string) ($column['scaleID'] ?? '')] ?? ['usage' => '', 'numeric' => 'N'];
    $descriptorValues = $gradesByScale[(string) ($column['scaleID'] ?? '')] ?? [];
    $counts = ['entered' => 0, 'missing' => 0, 'invalid' => 0];
    foreach ($roster as $student) {
        $studentID = (string) $student['gibbonPersonID'];
        $value = trim((string) ($savedStudents[$studentID]['scores'][$columnID]['attainmentValue'] ?? ''));
        if ($value === '') {
            $counts['missing']++;
            continue;
        }
        $validated = scoreLogValidateImportScore($value, $scale, $descriptorValues);
        $counts[$validated['valid'] ? 'entered' : 'invalid']++;
    }
    $columnCounts[$columnID] = $counts;
}

echo '<a id="scoreLogStudents"></a>';
echo '<form id="scoreLogAssessment" class="scoreLogAssessmentForm" method="post" action="'.htmlPrep($session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_draftProcess.php').'">';
echo '<input type="hidden" name="csrftoken" value="'.htmlPrep($csrfToken).'">';
echo '<input type="hidden" name="scoreLogDraftID" value="'.htmlPrep($scoreLogDraftID).'">';
echo '<input type="hidden" name="revision" value="'.htmlPrep((string) $revision).'">';
echo '<input type="hidden" name="gibbonCourseClassID" value="'.htmlPrep($gibbonCourseClassID).'">';
echo '<input type="hidden" name="gibbonScaleIDAttainment" value="'.htmlPrep($gibbonScaleIDAttainment).'">';
echo '<input type="hidden" id="scoreLogColumnOrder" name="columnOrder" value="'.htmlPrep(implode(',', array_slice($columnOrder, 0, $initialColumnCount))).'">';

echo '<div class="scoreLogEditorHeader"><div>'
    .'<h2>'.($scoreLogDraftID !== '' ? scoreLogT('Edit Draft') : scoreLogT('Create Internal Assessment')).'</h2>'
    .'<p><strong>'.scoreLogT('Class Name').':</strong> '.htmlPrep($class['name']).'</p>';
if ($scoreLogDraftID !== '') {
    echo '<p class="text-sm text-gray-600">'.sprintf(
        scoreLogT('Last saved %s · revision %s'),
        Format::dateTime($draft['updateTimestamp']),
        $draft['revision']
    ).'</p>';
}
echo '</div><div class="scoreLogEditorTools">'
    .'<select id="scoreLogToolColumn" class="w-full min-w-16 border py-2 text-gray-900 placeholder:text-gray-500 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5" aria-label="'.htmlPrep(scoreLogT('Assessment')).'">';
foreach (array_slice($columnOrder, 0, $initialColumnCount) as $columnID) {
    $name = trim((string) ($payload['columns'][$columnID]['name'] ?? '')) ?: scoreLogT('Assessment');
    echo '<option value="'.htmlPrep($columnID).'">'.htmlPrep($name).'</option>';
}
echo '</select>'
    .'<button type="button" id="scoreLogTopImport" class="button">'.scoreLogT('Import Scores').'</button>'
    .'<button type="button" id="scoreLogTopExport" class="button">'.scoreLogT('Export Current Scores (XLSX)').'</button>'
    .'<button type="button" id="scoreLogAddColumn" class="button">'.scoreLogT('Add Assessment Column').'</button>'
    .'</div></div>';

echo '<div class="scoreLogGridScroll"><table class="smallIntBorder w-full colorOddEven noMargin noPadding noBorder scoreLogGrid" id="scoreLogGrid"><thead>';
echo '<tr class="scoreLogConfigRow"><th class="scoreLogStudentColumn bg-gray-50">'.scoreLogT('Student').'</th>';
foreach ($columnOrder as $index => $columnID) {
    $column = $payload['columns'][$columnID];
    $hidden = $index >= $initialColumnCount;
    echo '<th class="scoreLogAssessmentColumn bg-gray-50" data-column-id="'.htmlPrep($columnID).'"'.($hidden ? ' hidden' : '').'>';
    echo '<div class="scoreLogColumnConfig">';
    echo '<input type="text" aria-label="'.htmlPrep(scoreLogT('Assessment Name')).'" class="scoreLogNameInput w-full rounded-md border py-2 text-gray-900 placeholder:text-gray-500 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5" name="columns['.htmlPrep($columnID).'][name]" value="'.htmlPrep((string) $column['name']).'" maxlength="30" placeholder="'.htmlPrep(scoreLogT('Assessment Name')).'"'.($hidden ? ' disabled' : '').'>';
    echo '<input type="text" aria-label="'.htmlPrep(scoreLogT('Description')).'" class="w-full rounded-md border py-2 text-gray-900 placeholder:text-gray-500 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5" name="columns['.htmlPrep($columnID).'][description]" value="'.htmlPrep((string) $column['description']).'" maxlength="1000" placeholder="'.htmlPrep(scoreLogT('Description')).'"'.($hidden ? ' disabled' : '').'>';
    echo '<select aria-label="'.htmlPrep(scoreLogT('Type')).'" class="w-full min-w-16 border py-2 text-gray-900 placeholder:text-gray-500 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5" name="columns['.htmlPrep($columnID).'][type]"'.($hidden ? ' disabled' : '').'>';
    foreach ($allowedTypes as $typeOption) {
        echo '<option value="'.htmlPrep($typeOption).'"'.($typeOption === ($column['type'] ?? '') ? ' selected' : '').'>'.htmlPrep($typeOption).'</option>';
    }
    echo '</select>';
    echo '<select aria-label="'.htmlPrep(scoreLogT('Assessment Scale')).'" class="scoreLogScaleSelect w-full min-w-16 border py-2 text-gray-900 placeholder:text-gray-500 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5" name="columns['.htmlPrep($columnID).'][scaleID]"'.($hidden ? ' disabled' : '').'>';
    foreach ($scaleOptions as $scaleID => $scaleName) {
        echo '<option value="'.htmlPrep($scaleID).'"'.((string) $scaleID === (string) ($column['scaleID'] ?? '') ? ' selected' : '').'>'.htmlPrep($scaleName).'</option>';
    }
    echo '</select>';
    echo '<input type="date" aria-label="'.htmlPrep(scoreLogT('Assessment Date')).'" class="w-full rounded-md border py-2 text-gray-900 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5" name="columns['.htmlPrep($columnID).'][completeDate]" value="'.htmlPrep((string) ($column['completeDate'] ?? '')).'"'.($hidden ? ' disabled' : '').'>';
    echo '<select aria-label="'.htmlPrep(scoreLogT('Viewable to Students')).'" class="w-full min-w-16 border py-2 text-gray-900 placeholder:text-gray-500 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5" name="columns['.htmlPrep($columnID).'][viewableStudents]"'.($hidden ? ' disabled' : '').'>';
    echo '<option value="Y"'.(($column['viewableStudents'] ?? 'Y') === 'Y' ? ' selected' : '').'>'.scoreLogT('Students Visible').'</option>';
    echo '<option value="N"'.(($column['viewableStudents'] ?? 'Y') === 'N' ? ' selected' : '').'>'.scoreLogT('Students Hidden').'</option>';
    echo '</select>';
    echo '<select aria-label="'.htmlPrep(scoreLogT('Viewable to Parents')).'" class="w-full min-w-16 border py-2 text-gray-900 placeholder:text-gray-500 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5" name="columns['.htmlPrep($columnID).'][viewableParents]"'.($hidden ? ' disabled' : '').'>';
    echo '<option value="Y"'.(($column['viewableParents'] ?? 'Y') === 'Y' ? ' selected' : '').'>'.scoreLogT('Parents Visible').'</option>';
    echo '<option value="N"'.(($column['viewableParents'] ?? 'Y') === 'N' ? ' selected' : '').'>'.scoreLogT('Parents Hidden').'</option>';
    echo '</select>';
    echo '<div class="scoreLogColumnProgress text-xs font-semibold text-gray-600" data-column-progress>'
        .scoreLogT('Entered').' '.$columnCounts[$columnID]['entered'].' / '
        .scoreLogT('Missing').' '.$columnCounts[$columnID]['missing'].' / '
        .scoreLogT('Invalid').' '.$columnCounts[$columnID]['invalid']
        .'</div>';
    echo '<div class="scoreLogColumnButtons">';
    echo '<button type="button" class="scoreLogMoveColumn button text-xs" data-direction="-1"'.($hidden ? ' disabled' : '').'>'.scoreLogT('Move Left').'</button>';
    echo '<button type="button" class="scoreLogMoveColumn button text-xs" data-direction="1"'.($hidden ? ' disabled' : '').'>'.scoreLogT('Move Right').'</button>';
    echo '<button type="button" class="scoreLogColumnTool button text-xs" data-action-name="importColumnID" value="'.htmlPrep($columnID).'"'.($hidden ? ' disabled' : '').'>'.scoreLogT('Import Scores').'</button>';
    echo '<button type="button" class="scoreLogColumnTool button text-xs" data-action-name="exportColumnID" value="'.htmlPrep($columnID).'"'.($hidden ? ' disabled' : '').'>'.scoreLogT('Export Current Scores (XLSX)').'</button>';
    echo '<button type="button" class="scoreLogRemoveColumn button text-xs"'.($hidden ? ' disabled' : '').'>'.scoreLogT('Remove Column').'</button>';
    echo '</div></div></th>';
}
echo '</tr>';
echo '<tr class="scoreLogScoreHeader"><th class="scoreLogStudentColumn bg-gray-50">'.scoreLogT('Student').'</th>';
foreach ($columnOrder as $index => $columnID) {
    $hidden = $index >= $initialColumnCount;
    $column = $payload['columns'][$columnID];
    $scaleName = $scalesById[(string) ($column['scaleID'] ?? '')]['name'] ?? '';
    echo '<th class="bg-gray-50" data-column-id="'.htmlPrep($columnID).'"'.($hidden ? ' hidden' : '').'>'
        .'<span data-column-sticky-name>'.htmlPrep((string) ($column['name'] ?? '') ?: scoreLogT('Assessment')).'</span>'
        .'<br><small data-column-sticky-scale>'.htmlPrep((string) $scaleName).'</small></th>';
}
echo '</tr></thead><tbody>';

foreach ($roster as $student) {
    $studentID = (string) $student['gibbonPersonID'];
    echo '<tr><th class="scoreLogStudentColumn bg-gray-50">';
    $studentName = Format::name('', $student['preferredName'], $student['surname'], 'Student', true);
    if (isset($diff['added'][$studentID])) {
        $studentName .= ' ('.scoreLogT('New').')';
    }
    echo '<strong>'.htmlPrep($studentName).'</strong></th>';

    foreach ($columnOrder as $index => $columnID) {
        $hidden = $index >= $initialColumnCount;
        $column = $payload['columns'][$columnID];
        $entry = is_array($savedStudents[$studentID]['scores'][$columnID] ?? null)
            ? $savedStudents[$studentID]['scores'][$columnID]
            : [];
        $scale = $scalesById[(string) ($column['scaleID'] ?? '')] ?? [];
        $defaultComment = !empty($scale['name']) ? scoreLogDefaultComment((string) $scale['name']) : '';
        $comment = array_key_exists('comment', $entry) ? (string) $entry['comment'] : $defaultComment;
        $hasCustomComment = trim($comment) !== '' && trim($comment) !== trim($defaultComment);
        echo '<td data-column-id="'.htmlPrep($columnID).'"'.($hidden ? ' hidden' : '').'>';
        $scoreList = isset($gradesByScale[(string) ($column['scaleID'] ?? '')])
            ? ' list="scoreLogGrades-'.htmlPrep((string) ($column['scaleID'] ?? '')).'"'
            : '';
        echo '<input aria-label="'.htmlPrep((string) ($column['name'] ?? '')).' / '.htmlPrep($studentName).'" class="scoreLogScoreInput w-full rounded-md border py-2 text-center text-gray-900 placeholder:text-gray-500 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5" type="text" inputmode="'.(($scale['numeric'] ?? 'N') === 'Y' ? 'decimal' : 'text').'" data-score="Y"'.$scoreList.' name="scores['.htmlPrep($studentID).']['.htmlPrep($columnID).'][attainmentValue]" value="'.htmlPrep((string) ($entry['attainmentValue'] ?? '')).'"'.($hidden ? ' disabled' : '').'>';
        echo '<details class="scoreLogCommentEditor'.($hasCustomComment ? ' hasComment' : '').'"><summary class="text-xs font-semibold text-blue-800">'.($hasCustomComment ? scoreLogT('Comment') : scoreLogT('Add Comment')).'</summary>';
        echo '<textarea data-default-comment="'.htmlPrep($defaultComment).'" aria-label="'.htmlPrep(scoreLogT('Comment')).' / '.htmlPrep((string) ($column['name'] ?? '')).' / '.htmlPrep($studentName).'" class="w-full rounded-md border py-2 font-sans text-gray-900 placeholder:text-gray-500 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5" name="scores['.htmlPrep($studentID).']['.htmlPrep($columnID).'][comment]" maxlength="1000" rows="4"'.($hidden ? ' disabled' : '').'>'.htmlPrep($comment).'</textarea></details>';
        echo '</td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div>';

foreach ($gradesByScale as $gradeScaleID => $gradeValues) {
    echo '<datalist id="scoreLogGrades-'.htmlPrep((string) $gradeScaleID).'">';
    foreach ($gradeValues as $gradeValue => $descriptor) {
        echo '<option value="'.htmlPrep((string) $gradeValue).'">'.htmlPrep((string) $descriptor).'</option>';
    }
    echo '</datalist>';
}

echo '<div class="scoreLogFormFooter"><button type="submit" name="saveDraft" value="Y" formnovalidate class="button button-secondary">'.scoreLogT('Save Draft').'</button>';
echo '<button type="submit" name="reviewPublish" value="Y" formnovalidate class="button">'.scoreLogT('Review and Publish').'</button></div>';
echo '</form>';

if ($scoreLogDraftID !== '') {
    $discardForm = Form::create(
        'scoreLogDiscardDraft',
        $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_discardProcess.php',
        'post'
    );
    $discardForm->setTitle(scoreLogT('Discard Draft'));
    $discardForm->setDescription(scoreLogT('Discard this private draft and return to the draft list.'));
    $discardForm->addHiddenValue('scoreLogDraftID', $scoreLogDraftID);
    $discardForm->addHiddenValue('revision', (string) $revision);
    $formRow = $discardForm->addRow();
    $formRow->addSubmit(scoreLogT('Discard Draft'))
        ->setColor('red')
        ->onClick("return confirm('".scoreLogT('Discard this draft? This cannot be undone.')."');");
    echo $discardForm->getOutput();
}

$recentAssessments = $pdo->select(
    "SELECT gibbonInternalAssessmentColumnID, name, type, completeDate
     FROM gibbonInternalAssessmentColumn
     WHERE gibbonCourseClassID=:gibbonCourseClassID
       AND complete='Y'
     ORDER BY completeDate DESC, gibbonInternalAssessmentColumnID DESC
     LIMIT 10",
    ['gibbonCourseClassID' => $gibbonCourseClassID]
)->fetchAll();

if (!empty($recentAssessments)) {
    $canEnterData = isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php');
    $table = DataTable::create('scoreLogRecentAssessments');
    $table->setTitle(scoreLogT('Recent Assessments'));
    $table->setDescription(scoreLogT('The 10 most recent published internal assessments for this class.'));
    $table->addColumn('name', scoreLogT('Name'));
    $table->addColumn('type', scoreLogT('Type'));
    $table->addColumn('completeDate', scoreLogT('Date'))->format(function ($assessment) {
        return !empty($assessment['completeDate']) ? Format::date($assessment['completeDate']) : '-';
    });
    if ($canEnterData) {
        $table->addActionColumn()
            ->addParam('gibbonCourseClassID', $gibbonCourseClassID)
            ->addParam('gibbonInternalAssessmentColumnID')
            ->format(function ($assessment, $actions) {
                $actions->addAction('viewMarks', scoreLogT('View / Enter Marks'))
                    ->setIcon('markbook')
                    ->setURL('/modules/Formal Assessment/internalAssessment_write_data.php');
            });
    }
    echo $table->render($recentAssessments);
}

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
echo '<script>window.scoreLogImportScaleData='.json_encode($gradesByScale, $jsonFlags).';'
    .'window.scoreLogScaleData='.json_encode(array_map(function ($scale) {
        return [
            'numeric' => $scale['numeric'],
            'max'     => scoreLogMaxScoreFromUsage($scale['usage']),
        ];
    }, $scalesById), $jsonFlags).';'
    .'window.scoreLogImportLabels='.json_encode([
        'entered'       => scoreLogT('Entered'),
        'missing'       => scoreLogT('Missing'),
        'invalid'       => scoreLogT('Invalid'),
        'score'         => scoreLogT('Score'),
        'grade'         => scoreLogT('Grade'),
        'comment'       => scoreLogT('Comment'),
        'addComment'    => scoreLogT('Add Comment'),
        'assessment'    => scoreLogT('Assessment'),
        'confirmRemove' => scoreLogT('Remove this assessment column and its current scores/comments?'),
        'unsavedChanges'=> scoreLogT('You have unsaved changes. Save the draft before leaving this page.'),
    ], $jsonFlags).';</script>';
