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
use Gibbon\Forms\Input\Button as FormButton;
use Gibbon\Services\Format;
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
$commentLibrary = $pdo->select(
    "SELECT scoreLogCommentID, label, comment
     FROM score_log_comment
     WHERE gibbonPersonIDCreator=:gibbonPersonID
     ORDER BY label",
    ['gibbonPersonID' => $gibbonPersonID]
)->fetchAll();

// Module landing: choose a class or start from an existing draft.
$selectedRosterEmpty = false;
if ($scoreLogDraftID === '' && $gibbonCourseClassID !== '' && $gibbonScaleIDAttainment !== '') {
    $selectedRosterEmpty = empty(scoreLogCurrentRoster($pdo, $gibbonCourseClassID));
}
if ($scoreLogDraftID === '' && ($gibbonCourseClassID === '' || $gibbonScaleIDAttainment === '' || $selectedRosterEmpty)) {
    if ($selectedRosterEmpty) {
        $page->addAlert(
            scoreLogT('The selected class has no records to display. Please choose another class.'),
            'warning'
        );
    }
    $page->navigator->addHeaderAction('savedDrafts', scoreLogT('Saved Drafts'))
        ->setURL('/modules/Score Log/scorelog_drafts.php')
        ->setIcon('markbook')
        ->displayLabel();
    $page->navigator->addHeaderAction('commentLibrary', scoreLogT('Comment Library'))
        ->setURL('/modules/Score Log/scorelog_comments.php')
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
                    ->setURL('/modules/Score Log/scorelog_view.php')
                    ->displayLabel();
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
           AND gibbonCourseClassPerson.role='Teacher'
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
        $row->addSearchSelect('gibbonCourseClassID')->fromArray($classes)->required()->selected($gibbonCourseClassID)->placeholder(scoreLogT('Please select...'));
        $row = $form->addRow();
        $row->addLabel('gibbonScaleIDAttainment', scoreLogT('Initial Assessment Scale'));
        $row->addSelect('gibbonScaleIDAttainment')
            ->fromArray($scales)
            ->selected($gibbonScaleIDAttainment !== '' ? $gibbonScaleIDAttainment : scoreLogDefaultScaleID($session))
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
    $page->navigator->addHeaderAction('commentLibrary', scoreLogT('Comment Library'))
        ->setURL('/modules/Score Log/scorelog_comments.php')
        ->setIcon('markbook')
        ->displayLabel();
    if (isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write.php')) {
        $page->navigator->addHeaderAction('viewMarks', scoreLogT('View Marks'))
            ->setURL('/modules/Formal Assessment/internalAssessment_write.php')
            ->addParam('gibbonCourseClassID', $gibbonCourseClassID)
            ->setIcon('markbook')
            ->displayLabel();
    }

    $draftNavigation = $pdo->select(
        "SELECT d.scoreLogDraftID
         FROM score_log_draft d
         JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=d.gibbonCourseClassID
         JOIN gibbonCourse course ON course.gibbonCourseID=cc.gibbonCourseID
         WHERE d.gibbonPersonIDCreator=:gibbonPersonID
           AND course.gibbonSchoolYearID=:gibbonSchoolYearID
         ORDER BY d.updateTimestamp DESC, d.scoreLogDraftID DESC",
        ['gibbonPersonID' => $gibbonPersonID, 'gibbonSchoolYearID' => $gibbonSchoolYearID]
    )->fetchAll(\PDO::FETCH_COLUMN);
    $draftPosition = false;
    foreach ($draftNavigation as $index => $draftID) {
        if ((int) $draftID === (int) $scoreLogDraftID) {
            $draftPosition = $index;
            break;
        }
    }
    if ($draftPosition !== false) {
        $olderDraftID = (string) ($draftNavigation[$draftPosition + 1] ?? '');
        $newerDraftID = (string) ($draftNavigation[$draftPosition - 1] ?? '');
        if ($olderDraftID !== '') {
            $page->navigator->addHeaderAction('previousDraft', scoreLogT('Previous Draft'))
                ->setURL('/modules/Score Log/scorelog_view.php')
                ->addParam('draftID', $olderDraftID)
                ->setIcon('back')
                ->setAttribute('aria-label', scoreLogT('Previous Draft'))
                ->displayLabel();
        }
        if ($newerDraftID !== '') {
            $page->navigator->addHeaderAction('nextDraft', scoreLogT('Next Draft'))
                ->setURL('/modules/Score Log/scorelog_view.php')
                ->addParam('draftID', $newerDraftID)
                ->setIcon('forward')
                ->setAttribute('aria-label', scoreLogT('Next Draft'))
                ->displayLabel();
        }
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
foreach ($scaleRows as $scaleRow) {
    $scalesById[(string) $scaleRow['gibbonScaleID']] = $scaleRow;
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
$savedColumnOrder = array_values(array_intersect((array) $payload['columnOrder'], $savedColumns));
if ($scoreLogDraftID !== '' && count($savedColumnOrder) > 1) {
    $page->addError(scoreLogT('This draft was created with more than one assessment. It has not been changed.'));
    return;
}
$columnID = (string) ($savedColumnOrder[0] ?? 'c1');
$scoreLogBaseURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/';
if ($scoreLogDraftID !== '') {
    $importURL = $scoreLogBaseURL.'scorelog_import.php&draftID='.urlencode($scoreLogDraftID).'&columnID='.urlencode($columnID);
    $exportTemplateURL = $scoreLogBaseURL.'scorelog_importTemplate.php&draftID='.urlencode($scoreLogDraftID).'&columnID='.urlencode($columnID).'&mode=template';
} else {
    $contextQuery = '&gibbonCourseClassID='.urlencode($gibbonCourseClassID).'&gibbonScaleIDAttainment='.urlencode($gibbonScaleIDAttainment);
    $importURL = $scoreLogBaseURL.'scorelog_import.php'.$contextQuery;
    $exportTemplateURL = $scoreLogBaseURL.'scorelog_importTemplate.php'.$contextQuery.'&mode=template';
}
$column = is_array($payload['columns'][$columnID] ?? null) ? $payload['columns'][$columnID] : [
    'name'             => '',
    'description'      => '',
    'type'             => $defaultType,
    'scaleID'          => $gibbonScaleIDAttainment,
    'completeDate'     => date('Y-m-d'),
    'viewableStudents' => 'Y',
    'viewableParents'  => 'Y',
];
$payload['columns'][$columnID] = $column;
$payload['columnOrder'] = [$columnID];
$gibbonScaleIDAttainment = (string) ($column['scaleID'] ?? $gibbonScaleIDAttainment);
$savedStudents = (array) ($payload['students'] ?? []);
$scale = $scalesById[$gibbonScaleIDAttainment] ?? ['name' => '', 'usage' => '', 'numeric' => 'N'];
$descriptorValues = $gradesByScale[$gibbonScaleIDAttainment] ?? [];
$columnCounts = ['entered' => 0, 'missing' => 0, 'invalid' => 0];
foreach ($roster as $student) {
    $studentID = (string) $student['gibbonPersonID'];
    $value = trim((string) ($savedStudents[$studentID]['scores'][$columnID]['attainmentValue'] ?? ''));
    if ($value === '') {
        $columnCounts['missing']++;
        continue;
    }
    $validated = scoreLogValidateImportScore($value, $scale, $descriptorValues);
    $columnCounts[$validated['valid'] ? 'entered' : 'invalid']++;
}

$reusableAssessments = $pdo->select(
    "SELECT assessment.gibbonInternalAssessmentColumnID, assessment.name,
            assessment.description, assessment.type,
            assessment.gibbonScaleIDAttainment AS scaleID, assessment.completeDate,
            assessment.viewableStudents, assessment.viewableParents
     FROM gibbonInternalAssessmentColumn assessment
     JOIN gibbonScale scale ON scale.gibbonScaleID=assessment.gibbonScaleIDAttainment
     WHERE assessment.gibbonCourseClassID=:gibbonCourseClassID
       AND assessment.gibbonScaleIDAttainment=:gibbonScaleID
       AND assessment.complete='Y'
       AND scale.active='Y'
     ORDER BY assessment.completeDate DESC, assessment.gibbonInternalAssessmentColumnID DESC
     LIMIT 10",
    [
        'gibbonCourseClassID' => $gibbonCourseClassID,
        'gibbonScaleID' => $gibbonScaleIDAttainment,
    ]
)->fetchAll();
$reusableAssessments = array_values(array_filter($reusableAssessments, function ($assessment) use ($allowedTypes) {
    return in_array((string) $assessment['type'], $allowedTypes, true);
}));
$structureOptions = [];
foreach ($reusableAssessments as $assessment) {
    $structureOptions[(string) $assessment['gibbonInternalAssessmentColumnID']] = (string) $assessment['name'];
}

$assessmentTitle = $scoreLogDraftID !== '' ? scoreLogT('Edit Draft') : scoreLogT('Create Internal Assessment');
$assessmentDescription = '<strong>'.scoreLogT('Class Name').':</strong> '.htmlPrep((string) $class['name']);
if ($scoreLogDraftID !== '') {
    $assessmentDescription .= '<br>'.sprintf(
        scoreLogT('Last saved %s · revision %s'),
        Format::dateTime($draft['updateTimestamp']),
        $draft['revision']
    );
}
$form = Form::create(
    'scoreLogAssessment',
    $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_draftProcess.php',
    'post'
);
$form->setAttribute(
    'data-autosave-url',
    $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_autosaveProcess.php'
);
$form->setFactory(DatabaseFormFactory::create($pdo));
$form->setTitle($assessmentTitle);
$form->setDescription($assessmentDescription);
$form->addHiddenValue('scoreLogDraftID', $scoreLogDraftID);
$form->addHiddenValue('revision', (string) $revision);
$form->addHiddenValue('autosaveKey', bin2hex(random_bytes(16)));
$form->addHiddenValue('autosaveSequence', '0');
$form->addHiddenValue('gibbonCourseClassID', $gibbonCourseClassID);
$form->addHiddenValue('gibbonScaleIDAttainment', $gibbonScaleIDAttainment);
$form->addHiddenValue('columnOrder', $columnID);
$form->addHiddenValue('targetColumnID', $columnID);
$form->addHiddenValue("columns[$columnID][scaleID]", $gibbonScaleIDAttainment);

$reloadURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_view.php'
    .($scoreLogDraftID !== '' ? '&draftID='.urlencode($scoreLogDraftID) : '');
$overwriteButton = new FormButton(
    scoreLogT('Overwrite latest with this tab'),
    'submit',
    null,
    'scoreLogAutosaveOverwrite'
);
$overwriteButton->setSize('sm')
    ->addClass('hidden')
    ->setIcon('solid', 'arrow-path');
$reloadButton = new FormButton(
    scoreLogT('Reload latest draft'),
    'button',
    null,
    'scoreLogAutosaveReload'
);
$reloadButton->setSize('sm')
    ->setColor('gray')
    ->addClass('hidden')
    ->setAttribute('data-reload-url', $reloadURL);
$row = $form->addRow();
$row->addContent(
    '<span id="scoreLogAutosaveStatus" class="text-sm font-semibold text-gray-600" data-state="'
    .($scoreLogDraftID !== '' ? 'saved' : 'unsaved').'" aria-live="polite">'
    .($scoreLogDraftID !== ''
        ? sprintf(scoreLogT('Saved · revision %s'), (string) $revision)
        : scoreLogT('Not saved yet'))
    .'</span>'
    .' '.$overwriteButton->getOutput()
    .' '.$reloadButton->getOutput()
);

$row = $form->addRow();
$topActions = $row->addColumn()->addClass('flex flex-wrap gap-2 justify-end');
$topActions->addButton(scoreLogT('Import Scores'), 'window.location.href='.json_encode($importURL))
    ->setSize('sm')
    ->setColor('gray')
    ->setIcon('solid', 'upload');
$topActions->addButton(scoreLogT('Export Template'), 'window.location.href='.json_encode($exportTemplateURL))
    ->setSize('sm')
    ->setColor('gray')
    ->setIcon('solid', 'download');

$form->addRow()->addHeading('assessmentDetails', scoreLogT('Assessment Details'));

$row = $form->addRow();
$row->addLabel('scoreLogAssessmentName', scoreLogT('Assessment Name'));
$row->addTextField("columns[$columnID][name]")
    ->setID('scoreLogAssessmentName')
    ->required()
    ->maxLength(30)
    ->setValue((string) ($column['name'] ?? ''));

$row = $form->addRow();
$row->addLabel('scoreLogAssessmentDescription', scoreLogT('Description'));
$row->addTextField("columns[$columnID][description]")
    ->setID('scoreLogAssessmentDescription')
    ->required()
    ->maxLength(1000)
    ->setValue((string) ($column['description'] ?? ''));

$row = $form->addRow();
$row->addLabel('scoreLogAssessmentType', scoreLogT('Type'));
$row->addSelect("columns[$columnID][type]")
    ->setID('scoreLogAssessmentType')
    ->fromArray(array_combine($allowedTypes, $allowedTypes))
    ->selected((string) ($column['type'] ?? $defaultType))
    ->required();

$row = $form->addRow();
$row->addLabel('scoreLogAssessmentScale', scoreLogT('Assessment Scale'))
    ->description(htmlPrep((string) ($scale['usage'] ?? '')));
$row->addTextField('scoreLogAssessmentScale')
    ->setID('scoreLogAssessmentScale')
    ->readOnly()
    ->setValue((string) ($scale['name'] ?? ''));

$row = $form->addRow();
$row->addLabel('scoreLogAssessmentDate', scoreLogT('Assessment Date'));
$row->addDate("columns[$columnID][completeDate]")
    ->setID('scoreLogAssessmentDate')
    ->setValue((string) ($column['completeDate'] ?? ''))
    ->required();

$row = $form->addRow();
$row->addLabel('scoreLogViewableStudents', scoreLogT('Viewable to Students'));
$row->addYesNo("columns[$columnID][viewableStudents]")
    ->setID('scoreLogViewableStudents')
    ->selected((string) ($column['viewableStudents'] ?? 'Y'))
    ->required();

$row = $form->addRow();
$row->addLabel('scoreLogViewableParents', scoreLogT('Viewable to Parents'));
$row->addYesNo("columns[$columnID][viewableParents]")
    ->setID('scoreLogViewableParents')
    ->selected((string) ($column['viewableParents'] ?? 'Y'))
    ->required();

if (!empty($structureOptions)) {
    $row = $form->addRow();
    $row->addLabel('scoreLogStructureSource', scoreLogT('Start from Previous Assessment'));
    $row->addSelect('scoreLogStructureSource')
        ->fromArray($structureOptions)
        ->placeholder(scoreLogT('Please select...'));
    $row->addButton(scoreLogT('Copy Structure'), null, 'scoreLogReuseStructure')
        ->setColor('gray');
    $row->addContent('<span id="scoreLogStructureStatus" aria-live="polite"></span>');
}

$row = $form->addRow();
$row->addLabel('scoreLogQuickFilter', scoreLogT('Show'));
$row->addSelect('scoreLogQuickFilter')
    ->setID('scoreLogQuickFilter')
    ->fromArray([
        'all' => scoreLogT('All'),
        'missing' => scoreLogT('Missing'),
        'invalid' => scoreLogT('Invalid'),
    ])
    ->selected('all');
$row->addContent(
    '<span id="scoreLogRowSummary">'
    .sprintf(
        scoreLogT('%s entered · %s missing · %s invalid'),
        $columnCounts['entered'],
        $columnCounts['missing'],
        $columnCounts['invalid']
    )
    .'</span>'
);

$table = $form->addRow()->setHeading('table')->addTable()
    ->setClass('smallIntBorder w-full colorOddEven noMargin noPadding noBorder');
$header = $table->addHeaderRow();
$header->addTableCell(scoreLogT('Student'));
$header->addTableCell((string) ($column['name'] ?? '') ?: scoreLogT('Assessment'))
    ->setTitle((string) ($scale['name'] ?? ''))
    ->setClass('textCenter');
$header->addTableCell(scoreLogT('Comment'));

foreach ($roster as $student) {
    $studentID = (string) $student['gibbonPersonID'];
    $entry = is_array($savedStudents[$studentID]['scores'][$columnID] ?? null)
        ? $savedStudents[$studentID]['scores'][$columnID]
        : [];
    $savedScore = trim((string) ($entry['attainmentValue'] ?? ''));
    $resolvedScore = scoreLogResolveScaleGrade($savedScore, $scale, $descriptorValues);
    $selectedScore = (string) ($resolvedScore['value'] ?? $savedScore);
    $scoreIsValid = $savedScore === '' || !empty($resolvedScore);
    $defaultComment = scoreLogDefaultComment((string) ($scale['name'] ?? ''));
    $autoComment = !empty($resolvedScore)
        ? (string) $resolvedScore['descriptor']
        : $defaultComment;
    $comment = array_key_exists('comment', $entry) ? (string) $entry['comment'] : $defaultComment;
    $studentName = Format::name('', $student['preferredName'], $student['surname'], 'Student', true);
    if (isset($diff['added'][$studentID])) {
        $studentName .= ' ('.scoreLogT('New').')';
    }

    $row = $table->addRow();
    $row->setAttribute('data-student-id', $studentID);
    $row->setAttribute('data-score-status', $savedScore === '' ? 'missing' : ($scoreIsValid ? 'entered' : 'invalid'));
    $row->addWebLink($studentName)
        ->setURL($session->get('absoluteURL').'/index.php?q=/modules/Students/student_view_details.php')
        ->addParam('gibbonPersonID', $studentID)
        ->addParam('subpage', 'Internal Assessment')
        ->wrap('<strong>', '</strong>');

    $scoreInput = $row->addTextField("scores[$studentID][$columnID][attainmentValue]")
        ->setID('scoreLogScore-'.$studentID)
        ->setClass('scoreLogScoreInput textCenter w-24')
        ->setValue($selectedScore)
        ->setAttribute('autocomplete', 'off')
        ->setAttribute('inputmode', ($scale['numeric'] ?? 'N') === 'Y' ? 'decimal' : 'text')
        ->setAttribute('list', 'scoreLogGradeOptions')
        ->setAttribute('data-score', 'Y')
        ->setAttribute('aria-label', sprintf(
            scoreLogT('%s score for %s'),
            (string) ($column['name'] ?? ''),
            $studentName
        ));
    if (($scale['numeric'] ?? 'N') === 'Y') {
        $scoreInput->setAttribute('placeholder', scoreLogT('Score'));
    } else {
        $scoreInput->setAttribute('placeholder', scoreLogT('Grade'));
    }

    $commentColumn = $row->addColumn()->addClass('stacked');
    $commentColumn->addTextArea("scores[$studentID][$columnID][comment]")
        ->setRows(1)
        ->autosize()
        ->setClass('max-h-32 overflow-y-auto')
        ->maxLength(1000)
        ->setValue($comment)
        ->setAttribute('data-auto-comment', $autoComment)
        ->setAttribute('aria-label', sprintf(
            scoreLogT('%s comment for %s'),
            (string) ($column['name'] ?? ''),
            $studentName
        ));
    if (!empty($commentLibrary)) {
        $commentOptions = [];
        foreach ($commentLibrary as $libraryComment) {
            $commentOptions[(string) $libraryComment['scoreLogCommentID']] = (string) $libraryComment['label'];
        }
        $commentActions = $commentColumn->addColumn()->addClass('flex items-center gap-2');
        $commentActions->addSelect('commentLibrary['.$studentID.']')
            ->fromArray($commentOptions)
            ->placeholder(scoreLogT('Library...'))
            ->setClass('scoreLogCommentLibrarySelect flex-1 min-w-0');
        $commentActions->addButton(scoreLogT('Append'), null)
            ->setClass('scoreLogCommentLibraryInsert shrink-0')
            ->setAttribute('aria-label', scoreLogT('Append comment'))
            ->setTitle(scoreLogT('Append comment'))
            ->setSize('sm')
            ->setColor('gray');
    } else {
        $commentActions = $commentColumn->addColumn()->addClass('flex items-center gap-2');
        $commentActions->addWebLink(scoreLogT('Add comment'))
            ->setURL('/modules/Score Log/scorelog_comments.php')
            ->setIcon('plus');
    }

    $form->addHiddenValue($studentID.'-gibbonPersonID', $studentID);
}

$row = $form->addRow();
$row->addFooter();
$row->addSubmit(scoreLogT('Save Draft'))
    ->setName('saveDraft')
    ->setSize('sm')
    ->setColor('gray')
    ->setAttribute('formnovalidate', 'formnovalidate');
$row->addButton(scoreLogT('Export Scores (XLSX)'), 'return window.scoreLogExportCurrent()')
    ->setSize('sm')
    ->setColor('gray')
    ->setIcon('solid', 'download');
$row->addSubmit(scoreLogT('Review and Publish'))
    ->setName('reviewPublish')
    ->setSize('sm');

echo '<a id="scoreLogStudents"></a>';
echo $form->getOutput();
echo '<datalist id="scoreLogGradeOptions">';
foreach ($descriptorValues as $gradeValue => $gradeDescriptor) {
    echo '<option value="'.htmlPrep((string) $gradeValue).'">'.htmlPrep((string) $gradeDescriptor).'</option>';
}
echo '</datalist>';

if ($scoreLogDraftID !== '') {
    $page->navigator->addHeaderAction('discard', scoreLogT('Discard Draft'))
        ->setURL('/modules/Score Log/scorelog_draftDelete.php')
        ->addParam('draftID', $scoreLogDraftID)
        ->addParam('returnPage', 'view')
        ->setIcon('delete')
        ->modalWindow()
        ->displayLabel();
}

if (!empty($reusableAssessments)) {
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
    echo $table->render($reusableAssessments);
}

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
echo '<script>window.scoreLogCommentLibrary='.json_encode(array_values($commentLibrary), $jsonFlags).';'
    .'window.scoreLogReusableAssessments='.json_encode(array_column(
        $reusableAssessments,
        null,
        'gibbonInternalAssessmentColumnID'
    ), $jsonFlags).';'
    .'window.scoreLogScaleData='.json_encode([
        'grades' => $descriptorValues,
        'numeric' => (string) ($scale['numeric'] ?? 'N'),
        'max' => scoreLogMaxScoreFromUsage($scale['usage'] ?? ''),
    ], $jsonFlags).';'
    .'window.scoreLogImportLabels='.json_encode([
        'unsavedChanges'=> scoreLogT('You have unsaved changes. Save the draft before leaving this page.'),
        'rowSummary'         => scoreLogT('%s entered · %s missing · %s invalid'),
        'structureRequired'  => scoreLogT('Choose an assessment structure to reuse.'),
        'structureAdded'     => scoreLogT('Assessment details copied without scores or comments.'),
        'autosaveUnsaved'    => scoreLogT('Unsaved changes'),
        'autosaveSaving'     => scoreLogT('Saving...'),
        'autosaveSaved'      => scoreLogT('Saved'),
        'autosaveRevision'   => scoreLogT('revision'),
        'autosaveOffline'    => scoreLogT('Offline — changes are not saved.'),
        'autosaveConflict'   => scoreLogT('Conflict — another tab saved a newer revision. Overwrite with this tab or reload the latest draft.'),
        'autosaveFailed'     => scoreLogT('Autosave failed. Your changes remain unsaved.'),
        'autosaveSessionExpired' => scoreLogT('Session expired. Reload the page and sign in again.'),
        'autosaveForbidden'  => scoreLogT('Autosave stopped: permission denied.'),
    ], $jsonFlags).';</script>';
