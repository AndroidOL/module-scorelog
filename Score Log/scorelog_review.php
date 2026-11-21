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

require_once __DIR__.'/moduleFunctions.php';

$page->breadcrumbs
    ->add(scoreLogT('Create Internal Assessment'), 'scorelog_view.php')
    ->add(scoreLogT('Review and Publish'));

$page->return->addReturns([
    'success0' => scoreLogT('Assessments published.'),
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
$evaluation = scoreLogEvaluateMultiAssessmentPayload($payload, $roster, $scales, $gradesByScale, $allowedTypes);
$editURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_view.php&draftID='.urlencode($scoreLogDraftID);

$output = '<div class="scoreLogReviewSummary">'
    . '<h2>'.htmlPrep((string) $draft['className']).'</h2>'
    . '<p>'.sprintf(scoreLogT('%s assessment columns'), count($evaluation['columns'])).'</p>'
    . '<p><a class="button" href="'.htmlPrep($editURL).'#scoreLogStudents">'.scoreLogT('Back to Draft').'</a></p>'
    . '</div>';

if ($diff['changed']) {
    $output .= '<div class="warning"><p><strong>'.scoreLogT('The class roster changed since this draft was saved.').'</strong></p>';
    if (!empty($diff['added'])) {
        $output .= '<p>'.sprintf(scoreLogT('New students: %s'), htmlPrep(implode(', ', $diff['added']))).'</p>';
    }
    if (!empty($diff['removed'])) {
        $output .= '<p>'.sprintf(scoreLogT('Removed students: %s'), htmlPrep(implode(', ', $diff['removed']))).'</p>';
    }
    $output .= '<p>'.scoreLogT('Only the current roster will be published. Review every assessment column before continuing.').'</p></div>';
}

if (!empty($evaluation['errors'])) {
    $output .= '<div class="error"><ul>';
    foreach ($evaluation['errors'] as $error) {
        $output .= '<li>'.htmlPrep($error).'</li>';
    }
    $output .= '</ul></div>';
}

$output .= '<table class="scoreLogReviewTable"><thead><tr>'
    . '<th>'.scoreLogT('Assessment').'</th><th>'.scoreLogT('Type').'</th><th>'.scoreLogT('Assessment Scale').'</th>'
    . '<th>'.scoreLogT('Assessment Date').'</th><th>'.scoreLogT('Visibility').'</th>'
    . '<th>'.scoreLogT('Entered').'</th><th>'.scoreLogT('Missing').'</th><th>'.scoreLogT('Invalid').'</th>'
    . '</tr></thead><tbody>';
foreach ($evaluation['columns'] as $column) {
    $config = $column['config'];
    $visibility = sprintf(
        scoreLogT('Students: %s; Parents: %s'),
        $config['viewableStudents'] === 'Y' ? scoreLogT('Yes') : scoreLogT('No'),
        $config['viewableParents'] === 'Y' ? scoreLogT('Yes') : scoreLogT('No')
    );
    $output .= '<tr>'
        . '<td>'.htmlPrep($config['name']).(!empty($column['errors']) ? '<br><small class="text-red-700">'.htmlPrep(implode(' ', $column['errors'])).'</small>' : '').'</td>'
        . '<td>'.htmlPrep($config['type']).'</td>'
        . '<td>'.htmlPrep($config['scaleName']).'</td>'
        . '<td>'.htmlPrep(scoreLogIsValidDate($config['completeDate']) ? Format::date($config['completeDate']) : '-').'</td>'
        . '<td>'.htmlPrep($visibility).'</td>'
        . '<td>'.$column['entered'].'</td><td>'.$column['missing'].'</td><td>'.$column['invalid'].'</td>'
        . '</tr>';
}
$output .= '</tbody></table>';

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
    $output .= '<h3>'.scoreLogT('Rows Requiring Correction').'</h3><table class="scoreLogReviewTable"><thead><tr>'
        . '<th>'.scoreLogT('Assessment').'</th><th>'.scoreLogT('Student').'</th><th>'.scoreLogT('Errors').'</th>'
        . '</tr></thead><tbody>';
    foreach ($invalidRows as $row) {
        $output .= '<tr><td>'.htmlPrep($row['column']).'</td><td>'.htmlPrep($row['student']).'</td><td>'.htmlPrep($row['errors']).'</td></tr>';
    }
    $output .= '</tbody></table>';
}

if (!$evaluation['valid']) {
    $output .= '<div class="error">'.scoreLogT('This draft cannot be published until all invalid rows and incomplete assessment columns are corrected.').'</div>';
} else {
    $output .= '<a id="scoreLogPublish"></a>';
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
    $row->addContent(sprintf(
        scoreLogT('Publish all %s assessment columns to the current roster of %s students?'),
        count($evaluation['columns']),
        count($roster)
    ));
    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(scoreLogT('Publish Assessments'));
    $output .= $form->getOutput();
}

$page->write($output);
