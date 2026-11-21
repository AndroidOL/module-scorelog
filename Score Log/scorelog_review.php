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

$roster = scoreLogCurrentRoster($pdo, $draft['gibbonCourseClassID']);
$payload = $draft['payloadData'];
$diff = scoreLogRosterDiff($payload, $roster);
$settingGateway = $container->get(SettingGateway::class);
$allowedTypes = scoreLogAllowedTypes($settingGateway->getSettingByScope('Formal Assessment', 'internalAssessmentTypes'));
$grades = $pdo->select(
    "SELECT value, descriptor FROM gibbonScaleGrade WHERE gibbonScaleID=:gibbonScaleID",
    ['gibbonScaleID' => $draft['gibbonScaleIDAttainment']]
)->fetchAll();
$gradeDescriptors = [];
foreach ($grades as $grade) {
    $gradeDescriptors[(string) $grade['value']] = $grade['descriptor'];
}

$evaluation = scoreLogEvaluateDraftPayload($payload, $roster, [
    'usage'   => $draft['scaleUsage'],
    'numeric' => $draft['scaleNumeric'],
], $gradeDescriptors, $allowedTypes);
$meta = $evaluation['meta'];
$editURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_view.php&draftID='.urlencode($scoreLogDraftID);
$assessmentDate = scoreLogIsValidDate((string) $meta['completeDate'])
    ? Format::date((string) $meta['completeDate'])
    : '-';

$output = '<div class="scoreLogReviewSummary">'
    . '<h2>'.htmlPrep((string) $meta['name']).'</h2>'
    . '<p><strong>'.scoreLogT('Class').':</strong> '.htmlPrep((string) $draft['className']).'</p>'
    . '<p><strong>'.scoreLogT('Assessment Scale').':</strong> '.htmlPrep((string) $draft['scaleName']).'</p>'
    . '<p><strong>'.scoreLogT('Type').':</strong> '.htmlPrep((string) $meta['type']).'</p>'
    . '<p><strong>'.scoreLogT('Assessment Date').':</strong> '.htmlPrep($assessmentDate).'</p>'
    . '<p><strong>'.scoreLogT('Visible to Students').':</strong> '.(($meta['viewableStudents'] ?? '') === 'Y' ? scoreLogT('Yes') : scoreLogT('No')).'</p>'
    . '<p><strong>'.scoreLogT('Visible to Parents').':</strong> '.(($meta['viewableParents'] ?? '') === 'Y' ? scoreLogT('Yes') : scoreLogT('No')).'</p>'
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
    $output .= '<p>'.scoreLogT('Only the current roster will be published. Review all missing scores before continuing.').'</p></div>';
}

if (!empty($evaluation['metaErrors'])) {
    $output .= '<div class="error"><p><strong>'.scoreLogT('Correct these assessment details before publishing:').'</strong></p><ul>';
    foreach ($evaluation['metaErrors'] as $message) {
        $output .= '<li>'.htmlPrep($message).'</li>';
    }
    $output .= '</ul></div>';
}

$output .= '<div class="scoreLogReviewCounts">'
    . '<div><span>'.scoreLogT('Students').'</span><strong>'.count($evaluation['rows']).'</strong></div>'
    . '<div><span>'.scoreLogT('Entered').'</span><strong>'.$evaluation['entered'].'</strong></div>'
    . '<div><span>'.scoreLogT('Missing').'</span><strong>'.$evaluation['missing'].'</strong></div>'
    . '<div><span>'.scoreLogT('Invalid').'</span><strong>'.$evaluation['invalid'].'</strong></div>'
    . '</div>';

$output .= '<h3>'.scoreLogT('Publish Preview').'</h3><table class="scoreLogReviewTable"><thead><tr>'
    . '<th>'.scoreLogT('Student').'</th><th>'.scoreLogT('Score').'</th><th>'.scoreLogT('Comment').'</th><th>'.scoreLogT('Status').'</th>'
    . '</tr></thead><tbody>';
foreach ($evaluation['rows'] as $row) {
    $statusLabel = [
        'entered' => scoreLogT('Entered'),
        'missing' => scoreLogT('Missing'),
        'invalid' => scoreLogT('Invalid'),
    ][$row['status']] ?? $row['status'];
    $rowError = !empty($row['errors']) ? ' '.implode(' ', $row['errors']) : '';
    $output .= '<tr>'
        . '<td>'.htmlPrep($row['name']).'</td>'
        . '<td>'.htmlPrep((string) $row['attainmentValue']).'</td>'
        . '<td>'.htmlPrep((string) $row['comment']).'</td>'
        . '<td><span class="scoreLogStatus scoreLogStatus-'.$row['status'].'">'.htmlPrep($statusLabel).'</span>'
        . ($rowError !== '' ? '<br/><small class="text-red-700">'.htmlPrep($rowError).'</small>' : '')
        . '</td></tr>';
}
$output .= '</tbody></table>';

if (!$evaluation['valid']) {
    $output .= '<div class="error">'.scoreLogT('This draft cannot be published until all invalid rows and assessment details are corrected.').'</div>';
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
    if ($evaluation['missing'] > 0) {
        $row = $form->addRow();
        $row->addCheckbox('confirmBlank')->setValue('Y')->required()->alignLeft()
            ->description(sprintf(scoreLogT('I confirm that %s students will be published without a score.'), $evaluation['missing']));
    }

    $row = $form->addRow();
    $row->addContent(sprintf(
        scoreLogT('Publish this assessment to the current roster of %s students?'),
        count($roster)
    ));
    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(scoreLogT('Publish Assessment'));
    $output .= $form->getOutput();
}

$page->write($output);
