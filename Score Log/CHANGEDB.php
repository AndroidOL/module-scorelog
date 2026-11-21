<?php
// USE ;end TO SEPARATE SQL STATEMENTS. DON'T USE ;end IN ANY OTHER PLACES!

$sql = [];
$count = 0;

// v1.1.0
$sql[$count][0] = "1.1.0";
$sql[$count][1] = "CREATE TABLE IF NOT EXISTS `score_log_draft` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;end";
$count++;

// v0.0.00
// $sql[$count][0] = "0.0.00";
// $sql[$count][1] = "-- First version, nothing to update";
