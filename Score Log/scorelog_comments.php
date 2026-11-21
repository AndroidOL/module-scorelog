<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

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

use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;

require_once __DIR__.'/moduleFunctions.php';

$page->breadcrumbs
    ->add(scoreLogT('Create Internal Assessment'), 'scorelog_view.php')
    ->add(scoreLogT('Comment Library'));

$page->return->addReturns([
    'success0' => scoreLogT('Comment added to your private library.'),
    'success1' => scoreLogT('Comment updated.'),
    'success2' => scoreLogT('Comment deleted.'),
    'error0'   => scoreLogT('You do not have permission to perform this action.'),
    'error1'   => scoreLogT('The comment could not be saved. Check the label and comment text.'),
    'error3'   => scoreLogT('No submitted data was received.'),
    'error9'   => scoreLogT('Your session expired. Refresh the page and try again.'),
]);

$gibbonPersonID = (string) $session->get('gibbonPersonID');

if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php')
) {
    $page->addError(scoreLogT('You do not have access to this page.'));
    return;
}

$comments = $pdo->select(
    "SELECT scoreLogCommentID, label, comment, updateTimestamp
     FROM score_log_comment
     WHERE gibbonPersonIDCreator=:gibbonPersonID
     ORDER BY label",
    ['gibbonPersonID' => $gibbonPersonID]
)->fetchAll();

$page->navigator->addHeaderAction('commentAdd', scoreLogT('Add Comment'))
    ->setURL('/modules/Score Log/scorelog_commentManage.php')
    ->setIcon('markbook')
    ->displayLabel();

$table = DataTable::create('scoreLogCommentLibrary');
$table->setTitle(scoreLogT('Private Comment Library'));
$table->setDescription(scoreLogT('Only you can see and reuse these comments in your Score Log drafts.'));
$table->addMetaData('blankSlate', scoreLogT('You do not have any reusable comments.'));
$table->addColumn('label', scoreLogT('Label'))->format(function ($comment) {
    return htmlPrep((string) $comment['label']);
});
$table->addColumn('comment', scoreLogT('Comment'))->format(function ($comment) {
    return htmlPrep(mb_strimwidth((string) $comment['comment'], 0, 120, '…'));
});
$table->addColumn('updateTimestamp', scoreLogT('Last Updated'))->format(function ($comment) {
    return Format::dateTime($comment['updateTimestamp']);
});
$table->addActionColumn()
    ->addParam('scoreLogCommentID')
    ->format(function ($comment, $actions) {
        $actions->addAction('edit', scoreLogT('Edit'))
            ->setIcon('config')
            ->setURL('/modules/Score Log/scorelog_commentManage.php');
        $actions->addAction('delete', scoreLogT('Delete'))
            ->setIcon('garbage')
            ->setURL('/modules/Score Log/scorelog_commentManage.php')
            ->addParam('delete', 'Y');
    });

$page->write($table->render($comments));
