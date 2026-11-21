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

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Gibbon\Forms\Form;

require_once __DIR__.'/moduleFunctions.php';

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$scoreLogDraftID = is_scalar($_GET['draftID'] ?? null) ? trim((string) $_GET['draftID']) : '';
$columnID = is_scalar($_GET['columnID'] ?? null) ? trim((string) $_GET['columnID']) : '';
$exportMode = ($_GET['mode'] ?? '') === 'scores' ? 'scores' : 'template';

if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php')
) {
    $page->addError(scoreLogT('You do not have access to this page.'));
    return;
}

// Class-context template export: no draft yet, so build a one-column template from the class + scale.
$ctxClassID = is_scalar($_GET['gibbonCourseClassID'] ?? null) ? trim((string) $_GET['gibbonCourseClassID']) : '';
$ctxScaleID = is_scalar($_GET['gibbonScaleIDAttainment'] ?? null) ? trim((string) $_GET['gibbonScaleIDAttainment']) : '';
if ($scoreLogDraftID === '' && $ctxClassID !== '' && $ctxScaleID !== '') {
    $class = $pdo->selectOne(
        "SELECT cc.gibbonCourseClassID
         FROM gibbonCourseClass cc
         JOIN gibbonCourse c ON c.gibbonCourseID=cc.gibbonCourseID
         WHERE cc.gibbonCourseClassID=:gibbonCourseClassID AND c.gibbonSchoolYearID=:gibbonSchoolYearID",
        ['gibbonCourseClassID' => $ctxClassID, 'gibbonSchoolYearID' => $gibbonSchoolYearID]
    );
    $scale = $pdo->selectOne(
        "SELECT gibbonScaleID FROM gibbonScale WHERE gibbonScaleID=:gibbonScaleID AND active='Y'",
        ['gibbonScaleID' => $ctxScaleID]
    );
    if (empty($class) || empty($scale) || !scoreLogCanEditClass($pdo, $gibbonPersonID, $ctxClassID, $gibbonSchoolYearID)) {
        $page->addError(scoreLogT('The specified record does not exist.'));
        return;
    }
    $roster = scoreLogCurrentRoster($pdo, $ctxClassID);
    if (empty($roster)) {
        $page->addError(scoreLogT('There are no records to display.'));
        return;
    }
    $payload = [
        'version'      => 2,
        'columns'      => [
            'c1' => [
                'name'             => '',
                'description'      => '',
                'type'             => '',
                'scaleID'          => $ctxScaleID,
                'completeDate'     => date('Y-m-d'),
                'viewableStudents' => 'Y',
                'viewableParents'  => 'Y',
            ],
        ],
        'columnOrder'  => ['c1'],
        'students'     => [],
        'studentNames' => [],
        'rosterIDs'    => [],
    ];
    $spreadsheet = scoreLogBuildAssessmentSpreadsheet($payload, $roster, 'c1', 'template');
    $filename = 'score-log-template-'.date('Ymd').'.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    (new Xlsx($spreadsheet))->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
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
$columnOrder = $draft['payloadData']['columnOrder'] ?? [];
if ($columnID === '' && count($columnOrder) === 1) {
    $columnID = (string) $columnOrder[0];
}
if ($columnID === '' || empty($draft['payloadData']['columns'][$columnID])) {
    if ($columnID !== '') {
        $page->addError(scoreLogT('Select the assessment column to import.'));
    }
    $scaleNames = $pdo->select("SELECT gibbonScaleID AS value, name FROM gibbonScale")->fetchKeyPair();
    $columnOptions = [];
    foreach ($draft['payloadData']['columnOrder'] as $candidateID) {
        $column = $draft['payloadData']['columns'][$candidateID] ?? [];
        $name = trim((string) ($column['name'] ?? '')) ?: scoreLogT('Untitled draft');
        $scaleName = $scaleNames[(string) ($column['scaleID'] ?? '')] ?? '';
        $columnOptions[(string) $candidateID] = $name.($scaleName !== '' ? ' — '.$scaleName : '');
    }
    $form = Form::create('scoreLogExportTarget', $session->get('absoluteURL').'/index.php', 'get');
    $form->setTitle(scoreLogT('Choose Assessment Column for Export'));
    $form->addHiddenValue('q', '/modules/Score Log/scorelog_importTemplate.php');
    $form->addHiddenValue('draftID', $scoreLogDraftID);
    $form->addHiddenValue('mode', $exportMode);
    $row = $form->addRow();
    $row->addLabel('columnID', scoreLogT('Assessment'));
    $row->addSelect('columnID')->fromArray($columnOptions)->required()->placeholder(scoreLogT('Please select...'));
    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit($exportMode === 'scores' ? scoreLogT('Export Scores (XLSX)') : scoreLogT('Export Template'));
    $page->write($form->getOutput());
    return;
}

$roster = scoreLogCurrentRoster($pdo, $draft['gibbonCourseClassID']);
$spreadsheet = scoreLogBuildAssessmentSpreadsheet($draft['payloadData'], $roster, $columnID, $exportMode);

$filename = 'score-log-'.($exportMode === 'scores' ? 'scores' : 'template').'-'.date('Ymd').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: no-store, no-cache, must-revalidate');

(new Xlsx($spreadsheet))->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;
