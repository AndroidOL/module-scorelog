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
$defaultType = $allowedTypes[0] ?? 'Assessment';

// Select an existing private draft or start a new one.
if ($scoreLogDraftID === '' && ($gibbonCourseClassID === '' || $gibbonScaleIDAttainment === '')) {
    $page->navigator->addHeaderAction('savedDrafts', scoreLogT('Saved Drafts'))
    ->setURL('/modules/Score Log/scorelog_drafts.php')
    ->setIcon('markbook')
    ->displayLabel();

    $draftRows = [];
    $drafts = $pdo->select(
        "SELECT d.scoreLogDraftID, d.gibbonCourseClassID, d.revision, d.payload, d.updateTimestamp,
                CONCAT(course.nameShort, '.', cc.nameShort) AS className,
                scale.name AS scaleName
         FROM score_log_draft d
         JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID=d.gibbonCourseClassID
         JOIN gibbonCourse course ON course.gibbonCourseID=cc.gibbonCourseID
         JOIN gibbonScale scale ON scale.gibbonScaleID=d.gibbonScaleIDAttainment
         WHERE d.gibbonPersonIDCreator=:gibbonPersonID
           AND course.gibbonSchoolYearID=:gibbonSchoolYearID
           AND scale.active='Y'
         ORDER BY d.updateTimestamp DESC",
        ['gibbonPersonID' => $gibbonPersonID, 'gibbonSchoolYearID' => $gibbonSchoolYearID]
    )->fetchAll();

    foreach ($drafts as $draft) {
        if (!scoreLogCanEditClass($pdo, $gibbonPersonID, $draft['gibbonCourseClassID'] ?? 0, $gibbonSchoolYearID)) {
            continue;
        }
        $payload = json_decode((string) $draft['payload'], true);
        $students = is_array($payload['students'] ?? null) ? $payload['students'] : [];
        $entered = 0;
        foreach ($students as $student) {
            if (is_array($student) && trim((string) ($student['attainmentValue'] ?? '')) !== '') {
                $entered++;
            }
        }
        $draft['title'] = trim((string) ($payload['meta']['name'] ?? '')) ?: scoreLogT('Untitled draft');
        $draft['entered'] = $entered.' / '.count($students);
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
        $row->addSelect('gibbonCourseClassID')->fromArray($classes)->required()->placeholder(scoreLogT('Please select...'));
        $row = $form->addRow();
        $row->addLabel('gibbonScaleIDAttainment', scoreLogT('Assessment Scale'));
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
    $gibbonScaleIDAttainment = (string) $draft['gibbonScaleIDAttainment'];
    $payload = $draft['payloadData'];
    $class = ['gibbonCourseClassID' => $gibbonCourseClassID, 'name' => $draft['className']];
    $scale = [
        'gibbonScaleID' => $gibbonScaleIDAttainment,
        'name'          => $draft['scaleName'],
        'usage'         => $draft['scaleUsage'],
        'numeric'       => $draft['scaleNumeric'],
    ];
    $revision = (int) $draft['revision'];
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
        'meta' => [
            'name'             => '',
            'description'      => '',
            'type'             => $defaultType,
            'viewableStudents' => 'Y',
            'viewableParents'  => 'Y',
            'completeDate'     => date('Y-m-d'),
        ],
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
$meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
$savedStudents = is_array($payload['students'] ?? null) ? $payload['students'] : [];
$grades = $pdo->select(
    "SELECT value, descriptor, isDefault
     FROM gibbonScaleGrade
     WHERE gibbonScaleID=:gibbonScaleID
     ORDER BY sequenceNumber",
    ['gibbonScaleID' => $gibbonScaleIDAttainment]
)->fetchAll();
$defaultGrade = '';
foreach ($grades as $grade) {
    if ($grade['isDefault'] === 'Y') {
        $defaultGrade = (string) $grade['value'];
        break;
    }
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

echo '<a id="scoreLogStudents"></a>';
$form = Form::create(
    'scoreLogAssessment',
    $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_draftProcess.php',
    'post'
);
$form->setFactory(DatabaseFormFactory::create($pdo));
$form->setTitle($scoreLogDraftID !== '' ? scoreLogT('Edit Draft') : scoreLogT('Create Internal Assessment'));
if ($scoreLogDraftID !== '') {
    $form->setDescription(scoreLogT('Export the current scores, edit the XLSX file, then import it back. Import changes are previewed before they are applied.'));
}
$form->addHiddenValue('scoreLogDraftID', $scoreLogDraftID);
$form->addHiddenValue('revision', (string) $revision);
$form->addHiddenValue('gibbonCourseClassID', $gibbonCourseClassID);
$form->addHiddenValue('gibbonScaleIDAttainment', $gibbonScaleIDAttainment);

if ($scoreLogDraftID !== '') {
    $form->addRow()->addContent(sprintf(
        scoreLogT('Last saved %s · revision %s'),
        Format::dateTime($draft['updateTimestamp']),
        $draft['revision']
    ))->wrap('<div class="text-sm text-gray-600">', '</div>');
}

if ($scoreLogDraftID !== '') {
    $page->navigator->addHeaderAction('importScores', scoreLogT('Import Scores'))
        ->setURL('/modules/Score Log/scorelog_import.php')
        ->addParam('draftID', $scoreLogDraftID)
        ->setIcon('upload')
        ->displayLabel();
    $page->navigator->addHeaderAction('exportCurrentScores', scoreLogT('Export Current Scores (XLSX)'))
        ->setURL('/modules/Score Log/scorelog_importTemplate.php')
        ->addParam('draftID', $scoreLogDraftID)
        ->setIcon('download')
        ->directLink()
        ->displayLabel();
}
if (isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write.php')) {
    $page->navigator->addHeaderAction('viewMarks', scoreLogT('View Marks'))
        ->setURL('/modules/Formal Assessment/internalAssessment_write.php')
        ->addParam('gibbonCourseClassID', $gibbonCourseClassID)
        ->setIcon('markbook')
        ->displayLabel();
}

$row = $form->addRow();
$row->addLabel('gibbonCourseClassIDName', scoreLogT('Class Name'));
$row->addTextField('gibbonCourseClassIDName')->setValue($class['name'])->readonly();
$row = $form->addRow();
$row->addLabel('gibbonScaleIDAttainmentName', scoreLogT('Assessment Scale'));
$row->addTextField('gibbonScaleIDAttainmentName')->setValue($scale['name'])->readonly();
$row = $form->addRow();
$row->addLabel('name', scoreLogT('Name'))->description(scoreLogT('Maximum 30 characters.'));
$row->addTextField('name')->required()->maxLength(30)->setValue((string) ($meta['name'] ?? ''));
$row = $form->addRow();
$row->addLabel('description', scoreLogT('Description'))->description(scoreLogT('Maximum 1000 characters.'));
$row->addTextField('description')->maxLength(1000)->setValue((string) ($meta['description'] ?? ''));

$type = (string) ($meta['type'] ?? $defaultType);
if (!empty($allowedTypes)) {
    $row = $form->addRow();
    $row->addLabel('type', scoreLogT('Type'));
    $row->addSelect('type')->fromString(implode(',', $allowedTypes))->required()->selected($type)->placeholder(scoreLogT('Please select...'));
} else {
    $form->addHiddenValue('type', $defaultType);
}

$form->addRow()->addHeading('Students', scoreLogT('Students'));
$table = $form->addRow()->addTable()
    ->setClass('scoreLogEntryTable w-full')
    ->setAttribute('aria-label', scoreLogT('Students').' — '.scoreLogT('Score').' / '.scoreLogT('Comment'));
$header = $table->addHeaderRow();
$header->addTableCell(scoreLogT('Student'))->rowSpan(2)->setClass('scoreLogStudentColumn');
$header->addTableCell($scale['name'])->setTitle($scale['usage'])->setClass('scoreLogScaleHeader textCenter')->colSpan(2);
$header = $table->addHeaderRow();
$header->addContent(scoreLogT('Score'))->setClass('scoreLogScoreColumn textCenter');
$header->addContent(scoreLogT('Comment'))->setClass('scoreLogCommentColumn textCenter');

$defaultComment = scoreLogDefaultComment($scale['name']);
$maxScore = scoreLogMaxScoreFromUsage($scale['usage']);
foreach ($roster as $index => $student) {
    $studentID = (string) $student['gibbonPersonID'];
    $saved = is_array($savedStudents[$studentID] ?? null) ? $savedStudents[$studentID] : [];
    $savedValue = (string) ($saved['attainmentValue'] ?? '');
    $savedComment = array_key_exists('comment', $saved) ? (string) $saved['comment'] : $defaultComment;

    $row = $table->addRow();
    $name = Format::name('', $student['preferredName'], $student['surname'], 'Student', true);
    if (isset($diff['added'][$studentID])) {
        $name .= ' ('.scoreLogT('New').')';
    }
    $row->addContent(htmlPrep($name))->setClass('scoreLogStudentCell')->wrap('<strong>', '</strong>');

    if ($scale['numeric'] === 'N') {
        $selectedGrade = $savedValue !== '' ? $savedValue : $defaultGrade;
        $input = $row->addSelectGradeScaleGrade('score-'.$studentID, $gibbonScaleIDAttainment)
            ->selected($selectedGrade)
            ->setClass('textCenter gradeSelect')
            ->setAttribute('aria-label', scoreLogT('Score'));
    } else {
        $input = $row->addNumber('score-'.$studentID)
            ->onlyInteger(false)
            ->decimalPlaces(1)
            ->step(0.5)
            ->setValue($savedValue)
            ->setAttribute('aria-label', scoreLogT('Score'));
        if ($maxScore !== null) {
            $input->minimum(0)->maximum($maxScore);
        }
    }
    $input->addClass('scoreLogScoreCell')->wrap(
        '<div class="scoreLogEntryField"><span class="scoreLogEntryFieldLabel">'.scoreLogT('Score').'</span>',
        '</div>'
    );

    $row->addTextArea('comment-'.$studentID)
        ->setRows(2)
        ->maxLength(1000)
        ->setValue($savedComment)
        ->setClass('scoreLogCommentCell')
        ->setAttribute('aria-label', scoreLogT('Comment'))
        ->wrap(
            '<div class="scoreLogEntryField"><span class="scoreLogEntryFieldLabel">'.scoreLogT('Comment').'</span>',
            '</div>'
        );
}

$form->addRow()->addHeading('Publish Settings', scoreLogT('Publish Settings'));
$row = $form->addRow();
$row->addLabel('viewableStudents', scoreLogT('Viewable to Students'));
$row->addYesNo('viewableStudents')->required()->selected((string) ($meta['viewableStudents'] ?? 'Y'));
$row = $form->addRow();
$row->addLabel('viewableParents', scoreLogT('Viewable to Parents'));
$row->addYesNo('viewableParents')->required()->selected((string) ($meta['viewableParents'] ?? 'Y'));
$row = $form->addRow();
$row->addLabel('completeDate', scoreLogT('Assessment Date'))
    ->description(scoreLogT('The assessment is hidden from students, parents and reports until this date.'));
$storedDate = (string) ($meta['completeDate'] ?? '');
$row->addDate('completeDate')->required()->setValue(
    scoreLogIsValidDate($storedDate) ? Format::date($storedDate) : ''
);
$row = $form->addRow();
$row->addFooter();
$row->addSubmit(scoreLogT('Save Draft'))->setName('saveDraft')->setColor('gray')
    ->setAttribute('formnovalidate', 'formnovalidate');
$row->addSubmit(scoreLogT('Review and Publish'))->setName('reviewPublish')
    ->setAttribute('formnovalidate', 'formnovalidate');
echo $form->getOutput();

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
    $row = $discardForm->addRow();
    $row->addSubmit(scoreLogT('Discard Draft'))
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
