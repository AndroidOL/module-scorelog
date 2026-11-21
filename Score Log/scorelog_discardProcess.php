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

use Gibbon\Data\Validator;
use Gibbon\Session\TokenHandler;

require_once __DIR__.'/../../gibbon.php';
require_once __DIR__.'/moduleFunctions.php';

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$viewURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_view.php';
$draftsURL = $session->get('absoluteURL').'/index.php?q=/modules/Score Log/scorelog_drafts.php';
$scoreLogDraftID = is_scalar($_POST['scoreLogDraftID'] ?? null) ? trim((string) $_POST['scoreLogDraftID']) : '';
$returnPage = ($_POST['returnPage'] ?? '') === 'drafts' ? 'drafts' : 'view';
$redirect = function (string $return, string $draftID = '') use ($viewURL, $draftsURL, &$returnPage) {
    $url = $returnPage === 'drafts'
        ? $draftsURL
        : $viewURL.($draftID !== '' ? '&draftID='.urlencode($draftID) : '');
    header('Location: '.$url.'&return='.$return);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $redirect('error3', $scoreLogDraftID);
}
if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php')
) {
    $redirect('error0', $scoreLogDraftID);
}
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    $redirect('error9', $scoreLogDraftID);
}

$input = $container->get(Validator::class)->sanitize($_POST);
$revision = is_scalar($input['revision'] ?? null) ? max(0, (int) $input['revision']) : 0;
if ($scoreLogDraftID === '' || $revision < 1) {
    $redirect('error1', $scoreLogDraftID);
}

$deleted = $pdo->delete(
    "DELETE FROM score_log_draft
     WHERE scoreLogDraftID=:scoreLogDraftID
       AND gibbonPersonIDCreator=:gibbonPersonID
       AND revision=:revision",
    [
        'scoreLogDraftID' => $scoreLogDraftID,
        'gibbonPersonID'  => $gibbonPersonID,
        'revision'        => $revision,
    ]
);

$redirect((int) $deleted === 1 ? 'success2' : 'error4');
