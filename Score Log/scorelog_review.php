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
use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;

require_once __DIR__.'/moduleFunctions.php';

$page->breadcrumbs
    ->add(scoreLogT('Create Internal Assessment'), 'scorelog_view.php')
    ->add(scoreLogT('Review and Publish'));

$page->return->addReturns([
    'success0' => scoreLogT('Assessment published.'),
    'success1' => scoreLogT('Draft saved. Review the current roster and scores before publishing.'),
    'error0'   => scoreLogT('You do not have permission to perform this action.'),
    'error1'   => scoreLogT('The draft is no longer available or its data is invalid.'),
    'error2'   => scoreLogT('Unable to save. Another assessment may be in progress; try again shortly.'),
    'error4'   => scoreLogT('This draft changed in another tab. Reopen it before publishing.'),
    'error5'   => scoreLogT('The class roster changed after review. Review the current roster again.'),
    'error6'   => scoreLogT('Confirm the students who will be published without a score.'),
    'error9'   => scoreLogT('Your session expired. Refresh the page and try again.'),
]);

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$scoreLogDraftID = is_scalar($_GET['draftID'] ?? null) ? trim((string) $_GET['draftID']) : '';

if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php')
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

$payload = $draft['payloadData'];
if (count((array) ($payload['columnOrder'] ?? [])) !== 1) {
    $page->addError(scoreLogT('This draft was created with more than one assessment. It has not been changed.'));
    return;
}
$roster = scoreLogCurrentRoster($pdo, $draft['gibbonCourseClassID']);
$diff = scoreLogRosterDiff($payload, $roster);
$scaleRows = $pdo->select(
    "SELECT gibbonScaleID, name, `usage`, `numeric`, active
     FROM gibbonScale ORDER BY name"
)->fetchAll();
$scales = [];
foreach ($scaleRows as $scale) {
    if ($scale['active'] === 'Y') {
        $scales[(string) $scale['gibbonScaleID']] = $scale;
    }
}
$grades = $pdo->select(
    "SELECT gibbonScaleID, value, descriptor
     FROM gibbonScaleGrade ORDER BY gibbonScaleID, sequenceNumber"
)->fetchAll();
$gradesByScale = [];
foreach ($grades as $grade) {
    $gradesByScale[(string) $grade['gibbonScaleID']][(string) $grade['value']] = $grade['descriptor'];
}

$settingGateway = $container->get(SettingGateway::class);
$allowedTypes = scoreLogAllowedTypes($settingGateway->getSettingByScope('Formal Assessment', 'internalAssessmentTypes'));
if (empty($allowedTypes)) {
    $page->addError(scoreLogT('No Formal Assessment types are configured. Ask an administrator to update Formal Assessment settings.'));
    return;
}
$evaluation = scoreLogEvaluateMultiAssessmentPayload($payload, $roster, $scales, $gradesByScale, $allowedTypes);

$page->navigator->addHeaderAction('back', scoreLogT('Back to Draft'))
    ->setURL('/modules/Score Log/scorelog_view.php')
    ->addParam('draftID', $scoreLogDraftID)
    ->setIcon('back')
    ->displayLabel();

if ($diff['changed']) {
    echo '<div class="warning"><p><strong>'.scoreLogT('The class roster changed since this draft was saved.').'</strong></p>';
    if (!empty($diff['added'])) {
        echo '<p>'.sprintf(scoreLogT('New students: %s'), htmlPrep(implode(', ', $diff['added']))).'</p>';
    }
    if (!empty($diff['removed'])) {
        echo '<p>'.sprintf(scoreLogT('Removed students: %s'), htmlPrep(implode(', ', $diff['removed']))).'</p>';
    }
    echo '<p>'.scoreLogT('Only the current roster will be published.').'</p></div>';
}

if (!empty($evaluation['errors'])) {
    echo '<div class="error"><ul>';
    foreach ($evaluation['errors'] as $error) {
        echo '<li>'.htmlPrep($error).'</li>';
    }
    echo '</ul></div>';
}

$summaryRows = [];
foreach ($evaluation['columns'] as $column) {
    $config = $column['config'];
    $visibility = sprintf(
        scoreLogT('Students: %s; Parents: %s'),
        $config['viewableStudents'] === 'Y' ? scoreLogT('Yes') : scoreLogT('No'),
        $config['viewableParents'] === 'Y' ? scoreLogT('Yes') : scoreLogT('No')
    );
    $summaryRows[] = [
        'className' => $draft['className'],
        'name' => $config['name'],
        'type' => $config['type'],
        'scaleName' => $config['scaleName'],
        'completeDate' => scoreLogIsValidDate($config['completeDate']) ? $config['completeDate'] : '',
        'visibility' => $visibility,
        'entered' => $column['entered'],
        'missing' => $column['missing'],
        'invalid' => $column['invalid'],
    ];
}
$table = DataTable::create('scoreLogReviewAssessment');
$table->setTitle(scoreLogT('Assessment Summary'));
$table->setDescription(scoreLogT('Review the assessment details and entry totals before publishing.'));
$table->addColumn('className', scoreLogT('Class'));
$table->addColumn('name', scoreLogT('Assessment'));
$table->addColumn('type', scoreLogT('Type'));
$table->addColumn('scaleName', scoreLogT('Assessment Scale'));
$table->addColumn('completeDate', scoreLogT('Assessment Date'))->format(function ($assessment) {
    return !empty($assessment['completeDate']) ? Format::date($assessment['completeDate']) : '-';
});
$table->addColumn('visibility', scoreLogT('Visibility'));
$table->addColumn('entered', scoreLogT('Entered'));
$table->addColumn('missing', scoreLogT('Missing'));
$table->addColumn('invalid', scoreLogT('Invalid'));
echo $table->render($summaryRows);

$invalidRows = [];
foreach ($evaluation['columns'] as $column) {
    foreach ($column['rows'] as $row) {
        if (!empty($row['errors'])) {
            $invalidRows[] = [
                'column' => $column['config']['name'],
                'student' => $row['name'],
                'errors' => implode(' ', $row['errors']),
            ];
        }
    }
}
if (!empty($invalidRows)) {
    $invalidTable = DataTable::create('scoreLogReviewInvalidRows');
    $invalidTable->setTitle(scoreLogT('Rows Requiring Correction'));
    $invalidTable->addColumn('column', scoreLogT('Assessment'));
    $invalidTable->addColumn('student', scoreLogT('Student'));
    $invalidTable->addColumn('errors', scoreLogT('Errors'));
    echo $invalidTable->render($invalidRows);
}

if (!$evaluation['valid']) {
    echo '<div class="error">'.scoreLogT('This draft cannot be published until invalid rows and incomplete assessment details are corrected.').'</div>';
} else {
    echo '<a id="scoreLogPublish"></a>';
    $form = Form::create(
        'scoreLogPublish',
        $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_publishProcess.php',
        'post'
    );
    $form->setTitle(scoreLogT('Confirm Publication'));
    $form->addHiddenValue('scoreLogDraftID', $scoreLogDraftID);
    $form->addHiddenValue('revision', (string) $draft['revision']);
    $form->addHiddenValue('rosterHash', scoreLogRosterHash($roster));

    if ($diff['changed']) {
        $row = $form->addRow();
        $row->addCheckbox('confirmRoster')->setValue('Y')->required()->alignLeft()
            ->description(scoreLogT('I reviewed the current roster and understand that only current students will be published.'));
    }
    if ($evaluation['totals']['missing'] > 0) {
        $row = $form->addRow();
        $row->addCheckbox('confirmBlank')->setValue('Y')->required()->alignLeft()
            ->description(sprintf(
                scoreLogT('I confirm that %s student-assessment scores will be published without a score.'),
                $evaluation['totals']['missing']
            ));
    }

    $row = $form->addRow();
    $row->addContent(sprintf(scoreLogT('Publish this assessment to the current roster of %s students?'), count($roster)));
    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(scoreLogT('Publish Assessment'));
    echo $form->getOutput();
}
