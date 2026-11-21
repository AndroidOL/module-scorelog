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

use Gibbon\Data\Validator;
use Gibbon\Session\TokenHandler;

require_once __DIR__.'/../../gibbon.php';
require_once __DIR__.'/moduleFunctions.php';

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$commentsURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_comments.php';

$redirect = function (string $return) use ($commentsURL) {
    header('Location: '.$commentsURL.'&return='.$return);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $redirect('error3');
}
if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php')
) {
    $redirect('error0');
}
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    $redirect('error9');
}

$input = $container->get(Validator::class)->sanitize($_POST);
$action = scoreLogInputString($input, 'action');
$scoreLogCommentID = scoreLogInputString($input, 'scoreLogCommentID');
if (!in_array($action, ['add', 'update', 'delete'], true)) {
    $redirect('error1');
}

if ($action === 'delete') {
    if ($scoreLogCommentID === '') {
        $redirect('error1');
    }
    $deleted = $pdo->delete(
        "DELETE FROM score_log_comment
         WHERE scoreLogCommentID=:scoreLogCommentID
           AND gibbonPersonIDCreator=:gibbonPersonID",
        [
            'scoreLogCommentID' => $scoreLogCommentID,
            'gibbonPersonID' => $gibbonPersonID,
        ]
    );
    $redirect((int) $deleted === 1 ? 'success2' : 'error1');
}

$commentValues = scoreLogValidateComment($input);
if (!$commentValues['valid']) {
    $redirect('error1');
}
$label = $commentValues['label'];
$comment = $commentValues['comment'];

try {
    if ($action === 'update') {
        if ($scoreLogCommentID === '') {
            $redirect('error1');
        }
        $owned = $pdo->selectOne(
            "SELECT scoreLogCommentID
             FROM score_log_comment
             WHERE scoreLogCommentID=:scoreLogCommentID
               AND gibbonPersonIDCreator=:gibbonPersonID",
            [
                'scoreLogCommentID' => $scoreLogCommentID,
                'gibbonPersonID' => $gibbonPersonID,
            ]
        );
        if (empty($owned)) {
            $redirect('error1');
        }
        $pdo->update(
            "UPDATE score_log_comment
             SET label=:label, comment=:comment
             WHERE scoreLogCommentID=:scoreLogCommentID
               AND gibbonPersonIDCreator=:gibbonPersonID",
            [
                'label' => $label,
                'comment' => $comment,
                'scoreLogCommentID' => $scoreLogCommentID,
                'gibbonPersonID' => $gibbonPersonID,
            ]
        );
        $redirect('success1');
    }

    $inserted = $pdo->insert(
        "INSERT INTO score_log_comment
         SET gibbonPersonIDCreator=:gibbonPersonID, label=:label, comment=:comment",
        [
            'gibbonPersonID' => $gibbonPersonID,
            'label' => $label,
            'comment' => $comment,
        ]
    );
    if (empty($inserted)) {
        throw new RuntimeException('Unable to save comment');
    }
    $redirect('success0');
} catch (Throwable $e) {
    $redirect('error1');
}
