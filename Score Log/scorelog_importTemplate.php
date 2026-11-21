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

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__.'/moduleFunctions.php';

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
$savedStudents = is_array($draft['payloadData']['students'] ?? null) ? $draft['payloadData']['students'] : [];
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(scoreLogT('Scores'));
$headers = [
    scoreLogT('Student ID'),
    scoreLogT('Username'),
    scoreLogT('Student Name'),
    scoreLogT('Score'),
    scoreLogT('Comment'),
];
$sheet->fromArray($headers, null, 'A1');

$rowNumber = 2;
foreach ($roster as $student) {
    $studentID = (string) ($student['gibbonPersonID'] ?? '');
    $saved = is_array($savedStudents[$studentID] ?? null) ? $savedStudents[$studentID] : [];
    $templateStudentID = trim((string) ($student['studentID'] ?? ''));
    if ($templateStudentID === '') {
        $templateStudentID = $studentID;
    }
    $sheet->setCellValueExplicit('A'.$rowNumber, $templateStudentID, DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B'.$rowNumber, (string) ($student['username'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit(
        'C'.$rowNumber,
        trim((string) ($student['preferredName'] ?? '').' '.(string) ($student['surname'] ?? '')),
        DataType::TYPE_STRING
    );
    $sheet->setCellValueExplicit('D'.$rowNumber, (string) ($saved['attainmentValue'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('E'.$rowNumber, (string) ($saved['comment'] ?? ''), DataType::TYPE_STRING);
    $rowNumber++;
}

foreach (['A', 'B', 'C', 'D', 'E'] as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}
$sheet->getStyle('A1:E1')->getFont()->setBold(true);
$sheet->freezePane('A2');

$filename = 'score-log-import-'.date('Ymd').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: no-store, no-cache, must-revalidate');

(new Xlsx($spreadsheet))->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;
