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
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;

require_once __DIR__.'/moduleFunctions.php';

$page->breadcrumbs
    ->add(scoreLogT('Create Internal Assessment'), 'scorelog_view.php')
    ->add(scoreLogT('Saved Drafts'));

$page->return->addReturns([
    'success2' => scoreLogT('Draft discarded.'),
    'error0'   => scoreLogT('You do not have permission to perform this action.'),
    'error4'   => scoreLogT('This draft changed in another tab. Reopen it before continuing.'),
]);

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');

if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
) {
    $page->addError(scoreLogT('You do not have access to this page.'));
    return;
}

$drafts = $pdo->select(
    "SELECT d.*, CONCAT(course.nameShort, '.', cc.nameShort) AS className,
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

$rows = [];
foreach ($drafts as $draft) {
    if (!scoreLogCanEditClass($pdo, $gibbonPersonID, $draft['gibbonCourseClassID'], $gibbonSchoolYearID)) {
        continue;
    }

    $payload = json_decode((string) $draft['payload'], true);
    if (!is_array($payload) || ($payload['version'] ?? null) !== 1) {
        continue;
    }

    $progress = scoreLogDraftProgress($payload);
    $draft['title'] = trim((string) ($payload['meta']['name'] ?? '')) ?: scoreLogT('Untitled draft');
    $draft['progress'] = sprintf(scoreLogT('%s of %s entered'), $progress['entered'], $progress['total']);
    $rows[] = $draft;
}

$actions = Form::createBlank('scoreLogSavedDraftActions');
$actions->addHeaderAction('create', scoreLogT('Create New Assessment'))
    ->setURL('/modules/Score Log/scorelog_view.php')
    ->setIcon('plus')
    ->displayLabel();
$page->write($actions->getOutput());

$table = DataTable::create('scoreLogSavedDrafts');
$table->setTitle(scoreLogT('Saved Drafts'));
$table->setDescription(scoreLogT('Private drafts created by you for the current school year. Only you can resume or discard them.'));
$table->addMetaData('blankSlate', scoreLogT('You do not have any saved drafts.'));
$table->addColumn('title', scoreLogT('Name'));
$table->addColumn('className', scoreLogT('Class'));
$table->addColumn('scaleName', scoreLogT('Assessment Scale'));
$table->addColumn('progress', scoreLogT('Progress'));
$table->addColumn('revision', scoreLogT('Revision'));
$table->addColumn('updateTimestamp', scoreLogT('Last Saved'))->format(function ($draft) {
    return Format::dateTime($draft['updateTimestamp']);
});
$table->addActionColumn()
    ->addParam('draftID')
    ->format(function ($draft, $actions) {
        $actions->addAction('resume', scoreLogT('Resume Draft'))
            ->setIcon('markbook')
            ->setURL('/modules/Score Log/scorelog_view.php');
        $actions->addAction('discard', scoreLogT('Discard Draft'))
            ->setIcon('delete')
            ->setURL('/modules/Score Log/scorelog_draftDelete.php')
            ->addParam('returnPage', 'drafts');
    });

$page->write($table->render($rows));
