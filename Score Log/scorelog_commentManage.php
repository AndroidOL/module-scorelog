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

use Gibbon\Forms\Form;

require_once __DIR__.'/moduleFunctions.php';

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$scoreLogCommentID = is_scalar($_GET['scoreLogCommentID'] ?? null)
    ? trim((string) $_GET['scoreLogCommentID'])
    : '';
$confirmDelete = ($_GET['delete'] ?? '') === 'Y';

$page->breadcrumbs
    ->add(scoreLogT('Create Internal Assessment'), 'scorelog_view.php')
    ->add(scoreLogT('Comment Library'), 'scorelog_comments.php')
    ->add($scoreLogCommentID === '' ? scoreLogT('Add Comment') : scoreLogT('Edit Comment'));

$page->return->addReturns([
    'error0' => scoreLogT('You do not have permission to perform this action.'),
    'error1' => scoreLogT('The comment could not be saved. Check the label and comment text.'),
    'error3' => scoreLogT('No submitted data was received.'),
    'error9' => scoreLogT('Your session expired. Refresh the page and try again.'),
]);

if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php')
) {
    $page->addError(scoreLogT('You do not have access to this page.'));
    return;
}

$comment = [
    'scoreLogCommentID' => '',
    'label' => '',
    'comment' => '',
];
if ($scoreLogCommentID !== '') {
    $comment = $pdo->selectOne(
        "SELECT scoreLogCommentID, label, comment
         FROM score_log_comment
         WHERE scoreLogCommentID=:scoreLogCommentID
           AND gibbonPersonIDCreator=:gibbonPersonID",
        [
            'scoreLogCommentID' => $scoreLogCommentID,
            'gibbonPersonID' => $gibbonPersonID,
        ]
    );
    if (empty($comment)) {
        $page->addError(scoreLogT('The specified record does not exist.'));
        return;
    }
}

if ($confirmDelete && $scoreLogCommentID !== '') {
    $form = Form::create(
        'scoreLogCommentDelete',
        $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_commentProcess.php',
        'post'
    );
    $form->setTitle(scoreLogT('Delete Comment'));
    $form->setDescription(sprintf(
        scoreLogT('Delete “%s” from your private comment library?'),
        htmlPrep((string) $comment['label'])
    ));
    $form->addHiddenValue('action', 'delete');
    $form->addHiddenValue('scoreLogCommentID', $scoreLogCommentID);
    $row = $form->addRow();
    $row->addSubmit(scoreLogT('Delete Comment'))
        ->setColor('red')
        ->onClick("return confirm('".scoreLogT('Delete this reusable comment?')."');");
    echo $form->getOutput();
    return;
}

$form = Form::create(
    'scoreLogComment',
    $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_commentProcess.php',
    'post'
);
$form->setTitle($scoreLogCommentID === '' ? scoreLogT('Add Comment') : scoreLogT('Edit Comment'));
$form->setDescription(scoreLogT('Comments in this library are private to you and can be inserted into any Score Log draft.'));
$form->addHiddenValue('action', $scoreLogCommentID === '' ? 'add' : 'update');
$form->addHiddenValue('scoreLogCommentID', $scoreLogCommentID);

$row = $form->addRow();
$row->addLabel('label', scoreLogT('Label'))->description(scoreLogT('A short name for finding this comment.'));
$row->addTextField('label')->required()->maxLength(80)->setValue($comment['label']);

$row = $form->addRow();
$row->addLabel('comment', scoreLogT('Comment'))->description(scoreLogT('Maximum 1000 characters.'));
$row->addTextArea('comment')->required()->maxLength(1000)->setRows(8)->setValue($comment['comment']);

$row = $form->addRow();
$row->addFooter();
$row->addSubmit();
echo $form->getOutput();

if ($scoreLogCommentID !== '') {
    $deleteForm = Form::create(
        'scoreLogCommentDeleteLink',
        $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_commentManage.php',
        'get'
    );
    $deleteForm->addHiddenValue('scoreLogCommentID', $scoreLogCommentID);
    $deleteForm->addHiddenValue('delete', 'Y');
    $row = $deleteForm->addRow();
    $row->addSubmit(scoreLogT('Delete Comment'))->setColor('red');
    echo $deleteForm->getOutput();
}
