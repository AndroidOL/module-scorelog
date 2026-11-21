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

use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Tables\DataTable;

require_once __DIR__ . '/moduleFunctions.php';

$page->breadcrumbs->add(scoreLogT('Create Internal Assessment'));

$page->return->addReturns([
    'success0' => scoreLogT('Assessment created.'),
    'error0' => scoreLogT('You do not have permission to perform this action.'),
    'error1' => scoreLogT('The submitted data is invalid. Check the class, scale and scores.'),
    'error2' => scoreLogT('Unable to save. Another assessment may be in progress; try again shortly.'),
    'error3' => scoreLogT('No submitted data was received.'),
    'error9' => scoreLogT('Your session expired. Refresh the page and try again.'),
]);

if (
    !isActionAccessible($guid, $connection2, '/modules/Score Log/scorelog_view.php')
    || !isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php')
) {
    $page->addError(scoreLogT('You do not have access to this page.'));
} else {
    $gibbonPersonID = $session->get('gibbonPersonID');
    $gibbonSchoolYearID = $session->get('gibbonSchoolYearID');
    $gibbonCourseClassID = is_scalar($_GET['gibbonCourseClassID'] ?? null) ? trim((string) $_GET['gibbonCourseClassID']) : '';
    $gibbonScaleIDAttainment = is_scalar($_GET['gibbonScaleIDAttainment'] ?? null) ? trim((string) $_GET['gibbonScaleIDAttainment']) : '';

    // Step 1: choose a class and scale. A class-only URL is also handled here,
    // which fixes the blank page after creating an assessment.
    if (empty($gibbonCourseClassID) || empty($gibbonScaleIDAttainment)) {
        $form = Form::create('scoreLogClassChooser', $session->get('absoluteURL').'/index.php', 'get');
        $form->setFactory(DatabaseFormFactory::create($pdo));
        $form->addHiddenValue('q', '/modules/'.$session->get('module').'/scorelog_view.php');
        $form->setTitle(scoreLogT('Choose Class'));

        $classes = [];

        $myClasses = $pdo->select(
            "SELECT gibbonCourseClass.gibbonCourseClassID AS value, CONCAT(gibbonCourse.nameShort, '.', gibbonCourseClass.nameShort) AS name
             FROM gibbonCourseClass
             JOIN gibbonCourse ON (gibbonCourseClass.gibbonCourseID=gibbonCourse.gibbonCourseID)
             JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID=gibbonCourseClass.gibbonCourseClassID)
             WHERE gibbonCourse.gibbonSchoolYearID=:gibbonSchoolYearID
             AND gibbonCourseClassPerson.gibbonPersonID=:gibbonPersonID
             AND gibbonCourseClassPerson.role IN ('Teacher', 'Assistant', 'Technician')
             ORDER BY name",
            ['gibbonSchoolYearID' => $gibbonSchoolYearID, 'gibbonPersonID' => $gibbonPersonID]
        )->fetchKeyPair();

        if (!empty($myClasses)) {
            $classes['--'.scoreLogT('My Classes').'--'] = $myClasses;
        }

        if (scoreLogCanViewAllClasses($gibbonPersonID)) {
            $allClasses = $pdo->select(
                "SELECT gibbonCourseClass.gibbonCourseClassID AS value, CONCAT(gibbonCourse.nameShort, '.', gibbonCourseClass.nameShort) AS name
                 FROM gibbonCourseClass
                 JOIN gibbonCourse ON (gibbonCourseClass.gibbonCourseID=gibbonCourse.gibbonCourseID)
                 WHERE gibbonCourse.gibbonSchoolYearID=:gibbonSchoolYearID
                 ORDER BY name",
                ['gibbonSchoolYearID' => $gibbonSchoolYearID]
            )->fetchKeyPair();

            if (!empty($allClasses)) {
                $classes['--'.scoreLogT('All Classes').'--'] = $allClasses;
            }
        }

        $scales = $pdo->select(
            "SELECT gibbonScaleID AS value, name FROM gibbonScale WHERE active='Y' ORDER BY name"
        )->fetchKeyPair();

        if (empty($classes)) {
            $page->addError(scoreLogT('There are no records to display.'));
        } else {
            $row = $form->addRow();
                $row->addLabel('gibbonCourseClassID', scoreLogT('Class Name'));
                $row->addSelect('gibbonCourseClassID')
                    ->fromArray($classes)
                    ->selected($gibbonCourseClassID)
                    ->required()
                    ->placeholder(scoreLogT('Please select...'));

            $row = $form->addRow();
                $row->addLabel('gibbonScaleIDAttainment', scoreLogT('Assessment Scale'));
                $row->addSelect('gibbonScaleIDAttainment')
                    ->fromArray($scales)
                    ->selected($gibbonScaleIDAttainment ?: scoreLogDefaultScaleID($session))
                    ->required()
                    ->placeholder(scoreLogT('Please select...'));

            $row = $form->addRow();
                $row->addSubmit(scoreLogT('Confirm Class'));

            echo $form->getOutput();
        }
    } else {
        // Step 2: create an assessment for the selected class and scale.
        $class = $pdo->selectOne(
            "SELECT gibbonCourseClass.gibbonCourseClassID, CONCAT(gibbonCourse.nameShort, '.', gibbonCourseClass.nameShort) AS name
             FROM gibbonCourseClass
             JOIN gibbonCourse ON (gibbonCourseClass.gibbonCourseID=gibbonCourse.gibbonCourseID)
             WHERE gibbonCourseClass.gibbonCourseClassID=:gibbonCourseClassID
             AND gibbonCourse.gibbonSchoolYearID=:gibbonSchoolYearID",
            ['gibbonCourseClassID' => $gibbonCourseClassID, 'gibbonSchoolYearID' => $gibbonSchoolYearID]
        );

        $isTeacherOfClass = $pdo->selectOne(
            "SELECT 1 FROM gibbonCourseClassPerson
             WHERE gibbonCourseClassID=:gibbonCourseClassID
             AND gibbonPersonID=:gibbonPersonID
             AND role IN ('Teacher', 'Assistant', 'Technician')
             LIMIT 1",
            ['gibbonCourseClassID' => $gibbonCourseClassID, 'gibbonPersonID' => $gibbonPersonID]
        );

        $scale = $pdo->selectOne(
            "SELECT gibbonScaleID, name, `usage`, `numeric`
             FROM gibbonScale WHERE gibbonScaleID=:gibbonScaleID AND active='Y'",
            ['gibbonScaleID' => $gibbonScaleIDAttainment]
        );

        if (empty($class)) {
            $page->addError(scoreLogT('The specified record does not exist.'));
        } elseif (!scoreLogCanViewAllClasses($gibbonPersonID) && empty($isTeacherOfClass)) {
            $page->addError(scoreLogT('You do not have access to this page.'));
        } elseif (empty($scale)) {
            $page->addError(scoreLogT('The specified record does not exist.'));
        } else {
            $students = $pdo->select(
                "SELECT gibbonPerson.gibbonPersonID, gibbonPerson.surname, gibbonPerson.preferredName
                 FROM gibbonCourseClassPerson
                 JOIN gibbonPerson ON (gibbonCourseClassPerson.gibbonPersonID=gibbonPerson.gibbonPersonID)
                 WHERE gibbonCourseClassPerson.gibbonCourseClassID=:gibbonCourseClassID
                 AND gibbonCourseClassPerson.reportable='Y'
                 AND gibbonCourseClassPerson.role='Student'
                 AND gibbonPerson.status='Full'
                 ORDER BY gibbonPerson.surname, gibbonPerson.preferredName",
                ['gibbonCourseClassID' => $gibbonCourseClassID]
            )->fetchAll();

            $grades = $pdo->select(
                "SELECT value, descriptor, isDefault
                 FROM gibbonScaleGrade
                 WHERE gibbonScaleID=:gibbonScaleID
                 ORDER BY sequenceNumber",
                ['gibbonScaleID' => $gibbonScaleIDAttainment]
            )->fetchAll();

            $defaultGrade = '';
            foreach ($grades as $grade) {
                if ($grade['isDefault'] === 'Y') {
                    $defaultGrade = $grade['value'];
                    break;
                }
            }

            $form = Form::create('scoreLogAssessment', $session->get('absoluteURL').'/index.php?q=/modules/'.$session->get('module').'/scorelog_createProcess.php');
            $form->setFactory(DatabaseFormFactory::create($pdo));
            $form->setTitle(scoreLogT('Enter Scores'));

            if (isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write.php')) {
                $form->addHeaderAction('viewMarks', scoreLogT('View Marks'))
                    ->setURL('/modules/Formal Assessment/internalAssessment_write.php')
                    ->addParam('gibbonCourseClassID', $gibbonCourseClassID)
                    ->setIcon('markbook')
                    ->displayLabel()
                    ->prepend(' | ');
            }

            $form->addRow()->addHeading('createAssessment', scoreLogT('Create New Internal Assessment'));

            $form->addHiddenValue('gibbonCourseClassID', $gibbonCourseClassID);
            $form->addHiddenValue('gibbonScaleIDAttainment', $gibbonScaleIDAttainment);

            $row = $form->addRow();
                $row->addLabel('gibbonCourseClassIDName', scoreLogT('Class Name'));
                $row->addTextField('gibbonCourseClassIDName')->setValue($class['name'])->readonly();

            $row = $form->addRow();
                $row->addLabel('gibbonScaleIDAttainmentName', scoreLogT('Assessment Scale'));
                $row->addTextField('gibbonScaleIDAttainmentName')->setValue($scale['name'])->readonly();

            $row = $form->addRow();
                $row->addLabel('name', scoreLogT('Name'))->description(scoreLogT('Maximum 30 characters.'));
                $row->addTextField('name')->required()->maxLength(30);

            $row = $form->addRow();
                $row->addLabel('description', scoreLogT('Description'))->description(scoreLogT('Maximum 1000 characters.'));
                $row->addTextField('description')->maxLength(1000);

            $settingGateway = $container->get(SettingGateway::class);
            $types = $settingGateway->getSettingByScope('Formal Assessment', 'internalAssessmentTypes');
            if (!empty($types)) {
                $row = $form->addRow();
                    $row->addLabel('type', scoreLogT('Type'));
                    $row->addSelect('type')->fromString($types)->required()->placeholder(scoreLogT('Please select...'));
            } else {
                $form->addHiddenValue('type', 'Assessment');
            }

            $form->addRow()->addHeading('Students', scoreLogT('Students'));

            if (empty($students)) {
                $form->addRow()->addAlert(scoreLogT('There are no records to display.'), 'error');
            } else {
                $table = $form->addRow()->addTable()->setClass('smallIntBorder w-full colorOddEven noMargin noPadding noBorder');
                $header = $table->addHeaderRow();
                    $header->addTableCell(scoreLogT('Student'))->rowSpan(2);
                    $header->addTableCell($scale['name'])
                        ->setTitle($scale['usage'])
                        ->setClass('textCenter')
                        ->colSpan(3);

                $header = $table->addHeaderRow();
                    $header->addContent(scoreLogT('Score'))
                        ->setTitle(scoreLogT('Score').' - '.$scale['name'].($scale['usage'] ? ': '.$scale['usage'] : ''))
                        ->setClass('textCenter');
                    $header->addContent(scoreLogT('Com'))->setTitle(scoreLogT('Comment'))->setClass('textCenter');

                $form->addHiddenValue('count', count($students));
                $defaultComment = scoreLogDefaultComment($scale['name']);
                $maxScore = scoreLogMaxScoreFromUsage($scale['usage']);

                foreach ($students as $index => $student) {
                    $number = $index + 1;
                    $row = $table->addRow();

                    $row->addWebLink(Format::name('', $student['preferredName'], $student['surname'], 'Student', true))
                        ->setURL($session->get('absoluteURL').'/index.php?q=/modules/Students/student_view_details.php')
                        ->addParam('gibbonPersonID', $student['gibbonPersonID'])
                        ->addParam('subpage', 'Internal Assessment')
                        ->wrap('<strong>', '</strong>')
                        ->prepend($number.') ');

                    if ($scale['numeric'] === 'N') {
                        $attainment = $row->addSelectGradeScaleGrade($number.'-attainmentValue', $gibbonScaleIDAttainment)
                            ->setClass('textCenter gradeSelect');
                        if ($defaultGrade !== '') {
                            $attainment->selected($defaultGrade);
                        }
                    } else {
                        $attainment = $row->addNumber($number.'-attainmentValue')
                            ->onlyInteger(false)
                            ->decimalPlaces(1)
                            ->step(0.5);
                        if ($maxScore !== null) {
                            $attainment->minimum(0)->maximum($maxScore);
                        }
                    }

                    $row->addTextArea('comment'.$number)
                        ->setRows(2)
                        ->maxLength(1000)
                        ->setValue($defaultComment);

                    $form->addHiddenValue($number.'-gibbonPersonID', $student['gibbonPersonID']);
                }
            }

            $form->addRow()->addHeading('Assessment Complete?', scoreLogT('Assessment Complete?'));

            $row = $form->addRow();
                $row->addLabel('viewableStudents', scoreLogT('Viewable to Students'));
                $row->addYesNo('viewableStudents')->required()->selected('Y');

            $row = $form->addRow();
                $row->addLabel('viewableParents', scoreLogT('Viewable to Parents'));
                $row->addYesNo('viewableParents')->required()->selected('Y');

            $row = $form->addRow();
                $row->addLabel('completeDate', scoreLogT('Assessment Date'))
                    ->description(scoreLogT('The assessment is hidden from students, parents and reports until this date.'));
                $row->addDate('completeDate')->required()->setValue(date('Y-m-d'));

            $row = $form->addRow();
                $row->addSubmit(scoreLogT('Create Assessment'));

            echo $form->getOutput();

            $recentAssessments = $pdo->select(
                "SELECT gibbonInternalAssessmentColumnID, name, type, complete, completeDate
                 FROM gibbonInternalAssessmentColumn
                 WHERE gibbonCourseClassID=:gibbonCourseClassID
                 ORDER BY completeDate DESC, gibbonInternalAssessmentColumnID DESC
                 LIMIT 10",
                ['gibbonCourseClassID' => $gibbonCourseClassID]
            )->fetchAll();

            if (!empty($recentAssessments)) {
                $canEnterData = isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_write_data.php');

                $table = DataTable::create('scoreLogRecentAssessments');
                $table->setTitle(scoreLogT('Recent Assessments'));
                $table->setDescription(scoreLogT('The 10 most recent internal assessments, ordered by completion date.'));

                $table->addColumn('name', scoreLogT('Name'));
                $table->addColumn('type', scoreLogT('Type'));
                $table->addColumn('completeDate', scoreLogT('Date'))
                    ->format(function ($assessment) {
                        return !empty($assessment['completeDate']) ? Format::date($assessment['completeDate']) : '-';
                    });
                $table->addColumn('complete', scoreLogT('Status'))
                    ->format(function ($assessment) {
                        return $assessment['complete'] === 'Y' ? scoreLogT('Completed') : scoreLogT('Draft');
                    });

                if ($canEnterData) {
                    $table->addActionColumn()
                        ->addParam('gibbonCourseClassID', $gibbonCourseClassID)
                        ->addParam('gibbonInternalAssessmentColumnID')
                        ->format(function ($assessment, $actions) {
                            $actions->addAction('viewMarks', scoreLogT('View / Enter Marks'))
                                ->setIcon('markbook')
                                ->setURL('/modules/Formal Assessment/internalAssessment_write_data.php');
                        });
                }

                echo $table->render($recentAssessments);
            }
        }
    }
}
