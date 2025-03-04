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

// Import necessary services and classes
use Gibbon\Services\Format;
use Gibbon\Data\Validator;

// Include the main Gibbon initialization file
include '../../gibbon.php';

// Output the contents of the request parameters (for debugging purposes)
echo "<pre>";
print_r($_REQUEST);
echo "</pre>";

// Sanitize the POST data using the Validator service from the container
$_POST = $container->get(Validator::class)->sanitize($_POST);

// Retrieve necessary parameters from the request
$gibbonCourseClassID = $_REQUEST['gibbonCourseClassID'] ?? '';
$address = $_GET['address'] ?? '';

// Build the URL for redirection after processing, pointing to the score log view
$URL = $session->get('absoluteURL').'/index.php?q=/modules/' . 'Score Log' . "/scorelog_view.php&gibbonCourseClassID=$gibbonCourseClassID";

// Check if the current user action is allowed
if (isActionAccessible($guid, $connection2, '/modules/Formal Assessment/internalAssessment_manage_add.php') == false) {
    // Append error code for not accessible and redirect
    $URL .= '&return=error0';
    header("Location: {$URL}");
} else {
    // Check if POST data exists; if not, return an error
    if (empty($_POST)) {
        $URL .= '&return=error3';
        header("Location: {$URL}");
    } else {
        // Proceed with further processing since POST data is present
        
        // Validate and initialize input values
        
        // Create an array from the course class ID (to support multiple IDs, though only one is used later)
        $gibbonCourseClassIDMulti = [$_POST['gibbonCourseClassID']] ?? [];

        // Retrieve assessment name from POST data
        $name = $_POST['name'] ?? '';
        // Retrieve assessment description; default value translated to English: "From Quick Creation Tool"
        $description = $_POST['description'] ?? 'From Quick Creation Tool';
        // Retrieve the assessment type from POST data
        $type = $_POST['type'] ?? '';
        
        // Set up attainment details (hard-coded to 'Y', meaning attainment is enabled)
        $attainment = 'Y';
        // Retrieve the scale ID for attainment
        $gibbonScaleIDAttainment = $_POST['gibbonScaleIDAttainment'] ?? '';

        // Set comment flag to 'Y'
        $comment = 'Y';
        // Retrieve the complete date from POST data
        $completeDate = $_POST['completeDate'] ?? '';
        if ($completeDate == '') {
            // If complete date is not provided, set it to null and mark as incomplete
            $completeDate = null;
            $complete = 'N';
        } else {
            // Convert the date to the required format and mark as complete
            $completeDate = Format::dateConvert($completeDate);
            $complete = 'Y';
        }

        // Retrieve who can view the assessment results
        $viewableStudents = $_POST['viewableStudents'] ?? '';
        $viewableParents = $_POST['viewableParents'] ?? '';
        // Get the creator and last editor ID from the session
        $gibbonPersonIDCreator = $session->get('gibbonPersonID');
        $gibbonPersonIDLastEdit = $session->get('gibbonPersonID');

        // Lock the markbook column table to ensure data integrity during the transaction
        try {
            $sqlLock = 'LOCK TABLES gibbonInternalAssessmentColumn WRITE';
            $resultLock = $connection2->query($sqlLock);
        } catch (PDOException $e) {
            $URL .= '&return=error2';
            header("Location: {$URL}");
            exit();
        }

        // Get the next groupingID by retrieving the highest current groupingID from the table
        try {
            $sqlGrouping = 'SELECT DISTINCT groupingID FROM gibbonInternalAssessmentColumn WHERE NOT groupingID IS NULL ORDER BY groupingID DESC';
            $resultGrouping = $connection2->query($sqlGrouping);
        } catch (PDOException $e) {
            $URL .= '&return=error2';
            header("Location: {$URL}");
            exit();
        }

        // Fetch the first row from the query result to determine the current maximum groupingID
        $rowGrouping = $resultGrouping->fetch();
        if (is_null($rowGrouping['groupingID'])) {
            // If no groupingID exists, start with 1
            $groupingID = 1;
        } else {
            // Otherwise, increment the maximum groupingID by 1
            $groupingID = ($rowGrouping['groupingID'] + 1);
        }

        // Get the current timestamp (not used later but stored in $time)
        $time = time();
        // Prepare for file attachment (if any); currently set as an empty string
        $attachment = '';

        // Check if any of the required parameters are missing or invalid
        if (
            is_array($gibbonCourseClassIDMulti) == false or 
            is_numeric($groupingID) == false or 
            $groupingID < 1 or 
            $name == '' or 
            $description == '' or 
            $type == '' or 
            $viewableStudents == '' or 
            $viewableParents == ''
        ) {
            $URL .= '&return=error1';
            header("Location: {$URL}");
        } else {
            // To avoid duplicate classes when courses span multiple year groups,
            // take only the first unique course class ID from the array.
            $gibbonCourseClassID = array_unique($gibbonCourseClassIDMulti)[0];
            try {
                // Prepare data array for inserting the new assessment column
                $data = array(
                    'groupingID' => $groupingID, 
                    'gibbonCourseClassID' => $gibbonCourseClassID, 
                    'name' => $name, 
                    'description' => $description, 
                    'type' => $type, 
                    'attainment' => $attainment, 
                    'gibbonScaleIDAttainment' => $gibbonScaleIDAttainment, 
                    'effort' => 'N', 
                    'gibbonScaleIDEffort' => null, 
                    'comment' => $comment, 
                    'uploadedResponse' => 'N', 
                    'completeDate' => $completeDate, 
                    'complete' => $complete, 
                    'viewableStudents' => $viewableStudents, 
                    'viewableParents' => $viewableParents, 
                    'attachment' => '', 
                    'gibbonPersonIDCreator' => $gibbonPersonIDCreator, 
                    'gibbonPersonIDLastEdit' => $gibbonPersonIDLastEdit
                );
                // Prepare the SQL insert statement for the assessment column
                $sql = 'INSERT INTO gibbonInternalAssessmentColumn 
                        SET groupingID=:groupingID, 
                            gibbonCourseClassID=:gibbonCourseClassID, 
                            name=:name, 
                            description=:description, 
                            type=:type, 
                            attainment=:attainment, 
                            gibbonScaleIDAttainment=:gibbonScaleIDAttainment, 
                            effort=:effort, 
                            gibbonScaleIDEffort=:gibbonScaleIDEffort, 
                            comment=:comment, 
                            uploadedResponse=:uploadedResponse, 
                            completeDate=:completeDate, 
                            complete=:complete, 
                            viewableStudents=:viewableStudents, 
                            viewableParents=:viewableParents, 
                            attachment=:attachment, 
                            gibbonPersonIDCreator=:gibbonPersonIDCreator, 
                            gibbonPersonIDLastEdit=:gibbonPersonIDLastEdit';
                $result = $connection2->prepare($sql);
                $result->execute($data);
            } catch (PDOException $e) {
                // Terminate the script if insertion fails
                exit();
            }

            // Proceed to the next step: retrieve the last inserted ID
            $insertedId = $connection2->lastInsertId();

            // Unlock the tables after insertion
            $sql = 'UNLOCK TABLES';
            $result = $connection2->query($sql);
            try {
                // Verify the inserted record by selecting it from the database
                $data = array(
                    'gibbonInternalAssessmentColumnID' => $insertedId, 
                    'gibbonCourseClassID' => $gibbonCourseClassID
                );
                $sql = 'SELECT * FROM gibbonInternalAssessmentColumn 
                        WHERE gibbonInternalAssessmentColumnID=:gibbonInternalAssessmentColumnID 
                          AND gibbonCourseClassID=:gibbonCourseClassID';
                $result = $connection2->prepare($sql);
                $result->execute($data);
            } catch (PDOException $e) {
                $URL .= '&return=error2';
                header("Location: {$URL}");
                exit();
            }

            // Output the inserted ID (for debugging)
            echo "<pre>";
            print_r($insertedId);
            echo "</pre>";

            // If the record is not found or multiple records are found, return an error
            if ($result->rowCount() != 1) {
                $URL .= '&return=error2';
                header("Location: {$URL}");
            } else {
                // Fetch the inserted row data
                $row = $result->fetch();

                // Retrieve values from the inserted row
                $name = $row['name'];
                // Get the count of student entries from POST data
                $count = $_POST['count'] ?? '';
                $partialFail = false; // Flag to track partial failures during processing
                $attainment = $row['attainment'];
                $gibbonScaleIDAttainment = $row['gibbonScaleIDAttainment'];
                $effort = $row['effort'];
                $gibbonScaleIDEffort = $row['gibbonScaleIDEffort'];
                $comment = $row['comment'];
                $uploadedResponse = $row['uploadedResponse'];

                // Loop through each student entry (assumes student IDs are numbered sequentially)
                for ($i = 1; $i <= $count; ++$i) {
                    // Get the student's person ID from POST data
                    $gibbonPersonIDStudent = $_POST["$i-gibbonPersonID"] ?? '';
                    
                    // Process attainment (grade) value for the student
                    // Note: the expression "$attainment == 'Y';" is not an assignment; it is a comparison that has no effect.
                    $attainmentValue = $_POST["$i-attainmentValue"] ?? '';
                    
                    // Process effort values (currently not used, so default to null)
                    // Similarly, "$effort == 'N';" is a comparison without effect.
                    $effortValue = null;
                    $effortDescriptor = null;
                    
                    // Process comment value for the student; again, "$comment != 'Y';" is a comparison.
                    $commentValue = $_POST["comment$i"] ?? '';
                    
                    // Update the last editor for the student's record
                    $gibbonPersonIDLastEdit = $session->get('gibbonPersonID');
                    
                    // Debug output: print "Score Type" followed by the scale ID for attainment
                    echo "<pre>";
                    print_r("Score Type");
                    print_r($gibbonScaleIDAttainment);
                    echo "</pre>";

                    // SET AND CALCULATE FOR ATTAINMENT
                    if ($attainment == 'Y' and $gibbonScaleIDAttainment != '') {
                        // Initialize descriptor without personal warnings
                        $attainmentDescriptor = '';
                        if ($attainmentValue != '') {
                            // Retrieve the lowest acceptable attainment and scale from POST (if needed)
                            $lowestAcceptableAttainment = $_POST['lowestAcceptableAttainment'] ?? '';
                            $scaleAttainment = $_POST['gibbonScaleIDAttainment'] ?? '';
                            try {
                                // Prepare data for scale lookup query
                                $dataScale = array(
                                    'attainmentValue' => $attainmentValue, 
                                    'scaleAttainment' => $scaleAttainment
                                );
                                // Select the matching grade and its descriptor from the scale tables
                                $sqlScale = 'SELECT * FROM gibbonScaleGrade 
                                             JOIN gibbonScale ON (gibbonScaleGrade.gibbonScaleID=gibbonScale.gibbonScaleID) 
                                             WHERE value=:attainmentValue 
                                               AND gibbonScaleGrade.gibbonScaleID=:scaleAttainment';
                                $resultScale = $connection2->prepare($sqlScale);
                                $resultScale->execute($dataScale);
                            } catch (PDOException $e) {
                                $partialFail = true;
                            }
                            // If the query does not return exactly one row, flag a partial failure
                            if ($resultScale->rowCount() != 1) {
                                $partialFail = true;
                            } else {
                                // Fetch the scale details and set the attainment descriptor
                                $rowScale = $resultScale->fetch();
                                $sequence = $rowScale['sequenceNumber'];
                                $attainmentDescriptor = $rowScale['descriptor'];
                            }
                        }
                    }

                    // SET AND CALCULATE FOR EFFORT
                    // Again, "$effort == 'N';" is a comparison with no effect.
                    $time = time();
                    $selectFail = false;
                    try {
                        // Check if an entry for this student and assessment column already exists
                        $data = array(
                            'gibbonInternalAssessmentColumnID' => $insertedId, 
                            'gibbonPersonIDStudent' => $gibbonPersonIDStudent
                        );
                        $sql = 'SELECT * FROM gibbonInternalAssessmentEntry 
                                WHERE gibbonInternalAssessmentColumnID=:gibbonInternalAssessmentColumnID 
                                  AND gibbonPersonIDStudent=:gibbonPersonIDStudent';
                        $result = $connection2->prepare($sql);
                        $result->execute($data);
                    } catch (PDOException $e) {
                        $partialFail = true;
                        $selectFail = true;
                    }
                    if (!($selectFail)) {
                        // If an entry exists, fetch it; otherwise, set to an empty array
                        $entry = $result->rowCount() > 0 ? $result->fetch() : [];

                        // Retrieve any existing attachment (response) if available
                        $attachment = $entry['response'] ?? null;

                        // Process file attachment if one exists; here the flag is set to 'N' and attachment is null
                        $uploadedResponse == 'N';
                        $attachment = null;

                        if (empty($entry)) {
                            // If no previous entry exists, insert a new entry for this student
                            try {
                                $data = array(
                                    'gibbonInternalAssessmentColumnID' => $insertedId, 
                                    'gibbonPersonIDStudent' => $gibbonPersonIDStudent, 
                                    'attainmentValue' => $attainmentValue, 
                                    'attainmentDescriptor' => $attainmentDescriptor, 
                                    'effortValue' => $effortValue, 
                                    'effortDescriptor' => $effortDescriptor, 
                                    'comment' => $commentValue, 
                                    'attachment' => $attachment, 
                                    'gibbonPersonIDLastEdit' => $gibbonPersonIDLastEdit
                                );
                                $sql = 'INSERT INTO gibbonInternalAssessmentEntry 
                                        SET gibbonInternalAssessmentColumnID=:gibbonInternalAssessmentColumnID, 
                                            gibbonPersonIDStudent=:gibbonPersonIDStudent, 
                                            attainmentValue=:attainmentValue, 
                                            attainmentDescriptor=:attainmentDescriptor, 
                                            effortValue=:effortValue, 
                                            effortDescriptor=:effortDescriptor, 
                                            comment=:comment, 
                                            response=:attachment, 
                                            gibbonPersonIDLastEdit=:gibbonPersonIDLastEdit';
                                $result = $connection2->prepare($sql);
                                $result->execute($data);
                            } catch (PDOException $e) {
                                $partialFail = true;
                            }
                        } else {
                            // If an entry exists, update it with the new data
                            try {
                                $data = array(
                                    'gibbonInternalAssessmentColumnID' => $insertedId, 
                                    'gibbonPersonIDStudent' => $gibbonPersonIDStudent, 
                                    'attainmentValue' => $attainmentValue, 
                                    'attainmentDescriptor' => $attainmentDescriptor, 
                                    'comment' => $commentValue, 
                                    'attachment' => $attachment, 
                                    'effortValue' => $effortValue, 
                                    'effortDescriptor' => $effortDescriptor, 
                                    'gibbonPersonIDLastEdit' => $gibbonPersonIDLastEdit, 
                                    'gibbonInternalAssessmentEntryID' => $entry['gibbonInternalAssessmentEntryID']
                                );
                                $sql = 'UPDATE gibbonInternalAssessmentEntry 
                                        SET gibbonInternalAssessmentColumnID=:gibbonInternalAssessmentColumnID, 
                                            gibbonPersonIDStudent=:gibbonPersonIDStudent, 
                                            attainmentValue=:attainmentValue, 
                                            attainmentDescriptor=:attainmentDescriptor, 
                                            effortValue=:effortValue, 
                                            effortDescriptor=:effortDescriptor, 
                                            comment=:comment, 
                                            response=:attachment, 
                                            gibbonPersonIDLastEdit=:gibbonPersonIDLastEdit 
                                        WHERE gibbonInternalAssessmentEntryID=:gibbonInternalAssessmentEntryID';
                                $result = $connection2->prepare($sql);
                                $result->execute($data);
                            } catch (PDOException $e) {
                                $partialFail = true;
                            }
                        }
                    }
                } // End of student loop

                // UPDATE the assessment column information after processing all student entries

                // Retrieve (or re-retrieve) description from POST data
                $description = $_POST['description'] ?? '';
                $time = time();
                // Process any attached file (currently set to null)
                $attachment = null;

                // Process the complete date again
                $completeDate = $_POST['completeDate'] ?? '';
                if ($completeDate == '') {
                    $completeDate = null;
                    $complete = 'N';
                } else {
                    $completeDate = Format::dateConvert($completeDate);
                    $complete = 'Y';
                }
                try {
                    // Update the assessment column record with attachment, description, and completion info
                    $data = array(
                        'attachment' => $attachment, 
                        'description' => $description, 
                        'completeDate' => $completeDate, 
                        'complete' => $complete, 
                        'gibbonInternalAssessmentColumnID' => $gibbonInternalAssessmentColumnID
                    );
                    $sql = 'UPDATE gibbonInternalAssessmentColumn 
                            SET attachment=:attachment, 
                                description=:description, 
                                completeDate=:completeDate, 
                                complete=:complete 
                            WHERE gibbonInternalAssessmentColumnID=:gibbonInternalAssessmentColumnID';
                    $result = $connection2->prepare($sql);
                    $result->execute($data);
                } catch (PDOException $e) {
                    $partialFail = true;
                }

                // Redirect back to the score log view based on whether there were partial failures
                if ($partialFail == true) {
                    $URL .= '&return=warning1';
                    header("Location: {$URL}");
                } else {
                    $URL .= '&return=success0';
                    header("Location: {$URL}");
                }
            }
        }
    }
}