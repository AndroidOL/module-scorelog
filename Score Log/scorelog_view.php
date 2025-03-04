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
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

// Import required classes and services
use Gibbon\Domain\Timetable\CourseEnrolmentGateway;
use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Services\Format;
use Gibbon\Domain\System\SettingGateway;

// Include module-specific functions
require_once __DIR__ . '/moduleFunctions.php';

// Add breadcrumb for "Create Internal Assessment"
$page->breadcrumbs->add(__('Create Internal Assessment'));

// Check if the current action is accessible for the Score Log view
if (!isActionAccessible($guid, $connection2, '/modules/' . 'Score Log' . '/scorelog_view.php')) {
    // Access denied: add error message "You do not have permission to access this page."
    $page->addError(__('You do not have permission to access this page.'));
} 
// If no course class has been selected, show the course selection form
else if (!isset($_REQUEST['gibbonCourseClassID'])) {
    // Create a form for choosing a course class
    $form = Form::create('gibbonCourseClassChoose', $session->get('absoluteURL').'/index.php?q=/modules/' . $session->get('module') . '/scorelog_view.php');
    $form->setFactory(DatabaseFormFactory::create($pdo));
    $form->setTitle(__('Choose Course'));

    $classes = array();
    // Retrieve "My Classes"
    $gibbonPersonID = $session->get('gibbonPersonID');
    $data = array(
        'gibbonSchoolYearID' => $session->get('gibbonSchoolYearID'),
        'gibbonPersonID' => $gibbonPersonID
    );
    // Query to get the courses that the current user is enrolled in
    $sql = "SELECT gibbonCourseClass.gibbonCourseClassID as value, CONCAT(gibbonCourse.nameShort, '.', gibbonCourseClass.nameShort) AS name 
            FROM gibbonCourseClass 
            JOIN gibbonCourse ON (gibbonCourseClass.gibbonCourseID=gibbonCourse.gibbonCourseID) 
            JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID=gibbonCourseClass.gibbonCourseClassID) 
            WHERE gibbonSchoolYearID=:gibbonSchoolYearID AND gibbonPersonID=:gibbonPersonID 
            ORDER BY name";
    try {
        $results = $pdo->executeQuery($data, $sql);
        if ($results->rowCount() > 0) {
            // Label the group as "--My Courses--"
            $classes['--'.__('My Courses').'--'] = $results->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
    } catch (\PDOException $e) {
        // If there is a PDO exception, output the error message for debugging
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    } catch (\Exception $e) {
        // If any other exception occurs, output the error message
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    }

    // Retrieve "All Classes" if the user has access (specific user IDs are allowed)
    $data = array('gibbonSchoolYearID' => $session->get('gibbonSchoolYearID'));
    $sql = "SELECT gibbonCourseClass.gibbonCourseClassID as value, CONCAT(gibbonCourse.nameShort, '.', gibbonCourseClass.nameShort) AS name 
            FROM gibbonCourseClass 
            JOIN gibbonCourse ON (gibbonCourseClass.gibbonCourseID=gibbonCourse.gibbonCourseID) 
            WHERE gibbonSchoolYearID=:gibbonSchoolYearID 
            ORDER BY name";
    try {
        $results = $pdo->executeQuery($data, $sql);
        if ($results->rowCount() > 0) {
            // Label the group as "--All Courses--"
            $classes['--'.__('All Courses').'--'] = $results->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
    } catch (\PDOException $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    } catch (\Exception $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    }

    // Retrieve all active scales from the database for grading
    $scales = array();
    $sql = "SELECT gibbonScaleID as value, name FROM gibbonScale WHERE active='Y' ORDER BY name";
    try {
        $results = $pdo->executeQuery([], $sql);
        if ($results->rowCount() > 0) {
            $scales = $results->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
    } catch (\PDOException $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    } catch (\Exception $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    }

    // Add a row for course class selection
    $row = $form->addRow();
        $row->addLabel('gibbonCourseClassID', __('Course Name'));
        $row->addSelect('gibbonCourseClassID')->fromArray($classes)->required()->placeholder();

    // Add a row for selecting the scale for attainment (quiz score)
    $row = $form->addRow();
        $row->addLabel('gibbonScaleIDAttainment', __('Quiz Score'));
        // The default scale is set
        $row->addSelect('gibbonScaleIDAttainment')->fromArray($scales)->selected($session->get('defaultAssessmentScale'))->required()->placeholder();
    // Add a hidden value for attainment level (set to 'N' by default)
    $form->addHiddenValue('gibbonScaleIDAttainmentLevel', 'N');

    // Add submit button with label "Confirm Course"
    $row = $form->addRow();
        $row->addSubmit(__('Confirm Course'));

    // Output the form HTML
    echo $form->getOutput();

// If a course class and scale are set, proceed to create the internal assessment form
} else if (isset($_REQUEST['gibbonCourseClassID']) && isset($_REQUEST['gibbonScaleIDAttainment'])) {
    // Create the internal assessment form with action pointing to the processing script
    $form = Form::create('gibbonInternalAssessment', $session->get('absoluteURL').'/index.php?q=/modules/' . $session->get('module') . '/scorelog_createProccess.php');
    $form->setFactory(DatabaseFormFactory::create($pdo));
    $form->setTitle(__('Fill in Transcript'));

    // Retrieve the course class name based on the submitted course class ID
    $classes = "";
    $data = array('gibbonCourseClassID' => $_REQUEST['gibbonCourseClassID']);
    $sql = "SELECT gibbonCourseClass.gibbonCourseClassID as value, CONCAT(gibbonCourse.nameShort, '.', gibbonCourseClass.nameShort) AS name 
            FROM gibbonCourseClass 
            JOIN gibbonCourse ON (gibbonCourseClass.gibbonCourseID=gibbonCourse.gibbonCourseID) 
            WHERE gibbonCourseClass.gibbonCourseClassID=:gibbonCourseClassID";
    
    try {
        $results = $pdo->executeQuery($data, $sql);
        if ($results->rowCount() == 1) {
            $classes = $results->fetch(\PDO::FETCH_ASSOC)['name'];
        } else {
            // Throw exception if the submitted data does not exist
            throw new InvalidArgumentException("The submitted data does not exist");
        }
    } catch (\PDOException $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    } catch (\Exception $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    }

    // Retrieve scale information for the selected attainment scale
    $scales = "";
    $data = array('gibbonScaleIDAttainment' => $_REQUEST['gibbonScaleIDAttainment']);
    $sql = "SELECT `name` FROM `gibbonscale` WHERE `gibbonScaleID` = :gibbonScaleIDAttainment";
    
    try {
        $results = $pdo->executeQuery($data, $sql);
        if ($results->rowCount() == 1) {
            $scales = $results->fetch(\PDO::FETCH_ASSOC)['name'];
        } else {
            throw new InvalidArgumentException("The submitted data does not exist");
        }
    } catch (\PDOException $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    } catch (\Exception $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    }

    // Add a heading row for "Create New Internal Assessment"
    $form->addRow()->addHeading('Create New Internal Assessment', __('Create New Internal Assessment'));
    
    // Add a hidden field for the course class ID
    $form->addHiddenValue('gibbonCourseClassID', $_REQUEST['gibbonCourseClassID']);
    // Add a row displaying the course class name (readonly)
    $row = $form->addRow();
        $row->addLabel('gibbonCourseClassIDName', __('Course Name'));
        $row->addTextField('gibbonCourseClassIDName')->setValue($classes)->readonly()->placeholder();

    // Add a hidden field for the attainment scale ID
    $form->addHiddenValue('gibbonScaleIDAttainment', $_REQUEST['gibbonScaleIDAttainment']);
    // Add a row displaying the attainment scale name (readonly)
    $row = $form->addRow();
        $row->addLabel('gibbonScaleIDAttainmentName', __('Quiz Score'));
        $row->addTextField('gibbonScaleIDAttainmentName')->setValue($scales)->readonly()->placeholder();

    // Add a row for entering the internal assessment name
    $row = $form->addRow();
        $row->addLabel('name', __('Name'));
        $row->addTextField('name')->required()->maxLength(30);

    // Add a row for entering the internal assessment description
    $row = $form->addRow();
        $row->addLabel('description', __('Description'));
        $row->addTextField('description')->maxLength(1000);
    
    // Retrieve available internal assessment types from system settings
    $settingGateway = $container->get(SettingGateway::class);
    $types = $settingGateway->getSettingByScope('Formal Assessment', 'internalAssessmentTypes');
    if (!empty($types)) {
        $row = $form->addRow();
            $row->addLabel('type', __('Type'));
            $row->addSelect('type')->fromString($types)->required()->placeholder();
    }
    
    // ---------------------------------------------------------------------------
    // Retrieve the list of students for the selected course class and associated scale info
    // ---------------------------------------------------------------------------
    $data = array('gibbonCourseClassID' => $_REQUEST['gibbonCourseClassID']);
    $sql = "SELECT gibbonPerson.gibbonPersonID, gibbonPerson.surname, gibbonPerson.preferredName
        FROM gibbonCourseClassPerson
        JOIN gibbonPerson ON (gibbonCourseClassPerson.gibbonPersonID=gibbonPerson.gibbonPersonID)
        WHERE gibbonCourseClassPerson.gibbonCourseClassID=:gibbonCourseClassID
        AND gibbonCourseClassPerson.reportable='Y' AND gibbonCourseClassPerson.role='Student' AND gibbonPerson.status='Full'
        ORDER BY gibbonPerson.surname, gibbonPerson.preferredName";
    $result = $pdo->executeQuery($data, $sql);

    // Retrieve scale information for the selected attainment scale from the database
    $data2 = array('gibbonScaleID' => $_REQUEST['gibbonScaleIDAttainment']);
    $sql2 = "SELECT `gibbonScaleID`, `name`, `nameShort`, `usage`, `lowestAcceptable`, `active`, `numeric` 
             FROM gibbonScale WHERE `gibbonScaleID` = :gibbonScaleID";
    $result2 = $connection2->prepare($sql2);
    $result2->execute($data2);
    $scaleinfo = $result2->fetch();

    // Retrieve grade scale details for the attainment scale
    $data3 = array('gibbonScaleID' => $_REQUEST['gibbonScaleIDAttainment']);
    $sql3 = "SELECT s.gibbonScaleID, sg.gibbonScaleGradeID, sg.value, sg.descriptor, sg.sequenceNumber, sg.isDefault 
             FROM gibbonScale AS s 
             INNER JOIN gibbonScaleGrade AS sg ON s.gibbonScaleID = sg.gibbonScaleID 
             WHERE s.gibbonScaleID = :gibbonScaleID ORDER BY sg.sequenceNumber";
    $result3 = $connection2->prepare($sql3);
    $result3->execute($data3);
    $scalegradeinfo = $result3->fetchAll();

    // Fetch all student records; if none exist, show an alert message
    $students = ($result->rowCount() > 0) ? $result->fetchAll() : array();
    if (count($students) == 0) {
        $form->addRow()->addHeading('Students', __('Students'));
        $form->addRow()->addAlert(__('There are no records to display.'), 'error');
    } else {
        // Create a table to list students and input fields for their scores and comments
        $table = $form->addRow()->setHeading('table')->addTable()->setClass('smallIntBorder w-full colorOddEven noMargin noPadding noBorder');

        // Add a header row: first cell for "Student" spanning two rows
        $header = $table->addHeaderRow();
            $header->addTableCell(__('Student'))->rowSpan(2);
            // Second cell for the scale name, with tooltip showing scale description, centered text, and spanning three columns
            $header->addTableCell($scaleinfo['name'])
                ->setTitle($scaleinfo['description'])
                ->setClass('textCenter')
                ->colSpan(3);

        // Add hidden values for scale attainment and lowest acceptable attainment from the scale info
        $form->addHiddenValue('scaleAttainment', $_REQUEST['gibbonScaleID']);
        $form->addHiddenValue('lowestAcceptableAttainment', $scaleinfo['lowest']);
        // Build a string that includes the scale name and usage (if provided)
        $scale = ' - ' . $scaleinfo['name'] . ($scaleinfo['usage'] ? ': ' . $scaleinfo['usage'] : '');
        // Add a second header row for column titles: "Score" and "Comment"
        $header = $table->addHeaderRow();
            $header->addContent(__('Score'))
                ->setTitle(__('Score') . $scale)
                ->setClass('textCenter');

            $header->addContent(__('Com'))->setTitle(__('Comment'))->setClass('textCenter');
    }

    // Determine the default attainment value from the scale grade information if set
    $defaultValue = "";
    foreach ($scalegradeinfo as $item) {
        if (isset($item['isDefault']) && $item['isDefault'] === 'Y') {
            $defaultValue = $item['value'];
            break;
        }
    }

    // Iterate over each student to add input fields for attainment and comments
    foreach ($students as $index => $student) {
        $count = $index + 1;
        $row = $table->addRow();

        // Create a clickable link with the student's name that links to their detailed view
        $row->addWebLink(Format::name('', '', $student['surname'], 'Student', true))
            ->setURL($session->get('absoluteURL').'/index.php?q=/modules/Students/student_view_details.php')
            ->addParam('gibbonPersonID', $student['gibbonPersonID'])
            ->addParam('subpage', 'Internal Assessment')
            ->wrap('<strong>', '</strong>')
            ->prepend($count . ') ');

        // If the scale is not numeric, display a drop-down selection for grade
        if ($scaleinfo['numeric'] == 'N') {
            $attainment = $row->addSelectGradeScaleGrade($count.'-attainmentValue', $scaleinfo['gibbonScaleID'])->setClass('textCenter gradeSelect');
            if (!empty($student['attainmentValue'])) {
                $attainment->selected($student['attainmentValue']);
            }
            if (!empty($defaultValue)) {
                $attainment->selected($defaultValue);
            }
        } else {
            // If the scale is numeric, provide a text field limited to 3 characters
            $attainment = $row->addTextField($count.'-attainmentValue')->maxLength(3);
            if (!empty($student['attainmentValue'])) {
                $attainment->setValue($student['attainmentValue']);
            }
        }

        // Add a column with a text area for entering comments; default value includes "Grading Standard:" followed by the scale name
        $col = $row->addColumn()->addClass('stacked');
        $col->addTextArea('comment'.$count)->setRows(2)->setValue(__("Grading Standard:") . $scales);
        // Add a hidden field to store the student's person ID
        $form->addHiddenValue($count.'-gibbonPersonID', $student['gibbonPersonID']);
    }
    // Store the count of student rows for processing on submission
    $form->addHiddenValue('count', $count);

    // ---------------------------------------------------------------------------
    // Additional Options for the Assessment Completion Status
    // ---------------------------------------------------------------------------
    // Add a heading for assessment completion options
    $form->addRow()->addHeading('Assessment Complete?', __('Assessment Complete?'));

    // Add a row to determine if the assessment is viewable by students
    $row = $form->addRow();
        $row->addLabel('viewableStudents', __('Viewable to Students'));
        $row->addYesNo('viewableStudents')->required();

    // Add a row to determine if the assessment is viewable by parents
    $row = $form->addRow();
        $row->addLabel('viewableParents', __('Viewable to Parents'));
        $row->addYesNo('viewableParents')->required();

    // Add a row for the "Go Live Date" with instructions in the label:
    // "1. " is prepended and "2. Column is hidden until date is reached." is appended.
    $row = $form->addRow();
        $row->addLabel('completeDate', __('Go Live Date'))->prepend('1. ')->append('<br/>'.__('2. Column is hidden until date is reached.'));
        $row->addDate('completeDate');

    // Add a submit button with the label "Create Transcript"
    $row = $form->addRow();
        $row->addSubmit(__('Create Transcript'));

    // Output the final form
    echo $form->getOutput();
}