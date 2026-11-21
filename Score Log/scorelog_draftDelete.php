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
    ->add(scoreLogT('Saved Drafts'), 'scorelog_drafts.php')
    ->add(scoreLogT('Discard Draft'));

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$scoreLogDraftID = is_scalar($_GET['draftID'] ?? null) ? trim((string) $_GET['draftID']) : '';
$returnPage = ($_GET['returnPage'] ?? '') === 'drafts' ? 'drafts' : 'view';

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
$title = scoreLogT('Untitled draft');
foreach ($payload['columnOrder'] as $columnID) {
    $columnName = trim((string) ($payload['columns'][$columnID]['name'] ?? ''));
    if ($columnName !== '') {
        $title = $columnName;
        break;
    }
}
$form = Form::create(
    'scoreLogDiscardDraft',
    $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_discardProcess.php',
    'post'
);
$form->setTitle(scoreLogT('Discard Draft'));
$form->addHiddenValue('scoreLogDraftID', $scoreLogDraftID);
$form->addHiddenValue('revision', (string) $draft['revision']);
$form->addHiddenValue('returnPage', $returnPage);
$row = $form->addRow();
$row->addAlert(sprintf(
    scoreLogT('Discard %s? This permanently removes the private draft and cannot be undone.'),
    $title
), 'warning');
$row = $form->addRow();
$row->addFooter();
$row->addSubmit(scoreLogT('Discard Draft'))
    ->setColor('red')
    ->onClick('return confirm('.json_encode(scoreLogT('Discard this draft? This cannot be undone.')).');');

$page->write($form->getOutput());
