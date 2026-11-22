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
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http:// www.gnu.org/licenses/>.
*/

// This file describes the module, including database tables

// Basic variables
$name        = 'Score Log';            // The name of the module as it appears to users. Needs to be unique to installation. Also the name of the folder that holds the unit.
$description = 'Quickly create internal assessments and enter student scores.';            // Short text description
$entryURL    = "scorelog_view.php";   // The landing page for the unit, used in the main menu
$type        = "Additional";  // Do not change.
$category    = 'Other';            // The main menu area to place the module in
$version     = '1.5.6';            // Version number
$author      = 'Tianhao Wu';            // Your name
$url         = 'https://gibbonedu.org';            // Your URL

// Module tables & gibbonSettings entries
$moduleTables[] = 'CREATE TABLE `score_log_draft` (
    `scoreLogDraftID` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `gibbonPersonIDCreator` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonCourseClassID` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonScaleIDAttainment` INT(3) UNSIGNED ZEROFILL NOT NULL,
    `revision` INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `payload` MEDIUMTEXT NOT NULL,
    `createTimestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updateTimestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`scoreLogDraftID`),
    KEY `creator` (`gibbonPersonIDCreator`),
    KEY `class` (`gibbonCourseClassID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
$moduleTables[] = 'CREATE TABLE `score_log_comment` (
    `scoreLogCommentID` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `gibbonPersonIDCreator` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `label` VARCHAR(80) NOT NULL,
    `comment` TEXT NOT NULL,
    `createTimestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updateTimestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`scoreLogCommentID`),
    UNIQUE KEY `creatorLabel` (`gibbonPersonIDCreator`, `label`),
    KEY `creator` (`gibbonPersonIDCreator`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

// Add gibbonSettings entries
// $gibbonSetting[] = "";

// Action rows 
$actionRows[] = [
    'name'                      => 'Create Assessment', // The name of the action (appears to user in the right hand side module menu)
    'precedence'                => '0',// If it is a grouped action, the precedence controls which is highest action in group
    'category'                  => 'Assessment', // Optional: subgroups for the right hand side module menu
    'description'               => 'Create an internal assessment and enter scores for a class.', // Text description
    'URLList'                   => 'scorelog_view.php, scorelog_createProcess.php, scorelog_draftProcess.php, scorelog_autosaveProcess.php, scorelog_discardProcess.php, scorelog_review.php, scorelog_publishProcess.php, scorelog_import.php, scorelog_importProcess.php, scorelog_importApplyProcess.php, scorelog_importTemplate.php, scorelog_drafts.php, scorelog_draftDelete.php, scorelog_comments.php, scorelog_commentManage.php, scorelog_commentProcess.php', // List of pages included in this action
    'entryURL'                  => 'scorelog_view.php', // The landing action for the page.
    'entrySidebar'              => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow'                  => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin'    => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher'  => 'Y', // Default permission for built in role Teacher
    'defaultPermissionStudent'  => 'N', // Default permission for built in role Student
    'defaultPermissionParent'   => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport'  => 'Y', // Default permission for built in role Support
    'categoryPermissionStaff'   => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent'  => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther'   => 'N', // Should this action be available to user roles in the Other category?
];

// Hooks
// $hooks[] = ''; // Serialised array to create hook and set options. See Hooks documentation online.
