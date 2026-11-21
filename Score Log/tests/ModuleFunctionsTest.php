<?php

namespace {
    if (!function_exists('__')) {
        function __($text, array $params = [])
        {
            foreach ($params as $key => $value) {
                $text = str_replace('{'.$key.'}', (string) $value, $text);
            }

            return $text;
        }
    }
}

namespace Gibbon\Module\ScoreLog\Tests {
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__).'/moduleFunctions.php';

    final class ModuleFunctionsTest extends TestCase
    {
    public function testMaximumScoreSupportsChineseAndEnglishUsageText()
    {
        $this->assertSame(100.0, \scoreLogMaxScoreFromUsage('满分100分'));
        $this->assertSame(20.0, \scoreLogMaxScoreFromUsage('Out of 20'));
        $this->assertSame(50.0, \scoreLogMaxScoreFromUsage('50分制'));
        $this->assertNull(\scoreLogMaxScoreFromUsage('No maximum stated'));
    }

    public function testAssessmentTypesAreTrimmedAndDeduplicated()
    {
        $this->assertSame(
            ['Quiz', 'Assessment'],
            \scoreLogAllowedTypes(' Quiz, Assessment,Quiz, ')
        );
    }

    public function testNumericScoresAreFiniteAndDoNotAcceptExponentNotation()
    {
        $this->assertSame('12.5', \scoreLogNormalizeNumericValue('+12.5000'));
        $this->assertSame('0', \scoreLogNormalizeNumericValue('-0'));
        $this->assertNull(\scoreLogNormalizeNumericValue('1e3'));
        $this->assertNull(\scoreLogNormalizeNumericValue('INF'));
        $this->assertNull(\scoreLogNormalizeNumericValue('12345678901'));
    }

    public function testNumericMarksRequireAndPreserveAnOfficialScaleGradeRow()
    {
        $numeric = \scoreLogValidateImportScore(
            '100',
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            ['100%' => 'Excellent']
        );
        $percent = \scoreLogValidateImportScore(
            '100%',
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            ['100%' => 'Excellent']
        );
        $decimal = \scoreLogValidateImportScore(
            '87.5%',
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            ['87.5%' => 'Very good']
        );
        $score = \scoreLogValidateImportScore(
            '88.0',
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            ['88%' => '88%']
        );
        $missing = \scoreLogValidateImportScore(
            '99%',
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            ['100%' => 'Excellent']
        );

        $this->assertTrue($numeric['valid']);
        $this->assertSame('100%', $numeric['normalized']);
        $this->assertSame('Excellent', $numeric['descriptor']);
        $this->assertTrue($percent['valid']);
        $this->assertSame('100%', $percent['normalized']);
        $this->assertTrue($decimal['valid']);
        $this->assertSame('87.5%', $decimal['normalized']);
        $this->assertSame('Very good', $decimal['descriptor']);
        $this->assertTrue($score['valid']);
        $this->assertSame('88%', $score['normalized']);
        $this->assertSame('88%', $score['descriptor']);
        $this->assertFalse($missing['valid']);
        $this->assertSame('Choose a score from the selected scale.', $missing['error']);
    }

    public function testImportPreviewUsesPercentValuedScaleGrades()
    {
        $preview = \scoreLogBuildImportPreview(
            [['Ada Lovelace', '100%']],
            [
                'headerRow' => null,
                'identifierColumn' => 0,
                'identifierType' => 'name',
                'scoreColumn' => 1,
                'commentColumn' => null,
            ],
            ['students' => []],
            $this->importRoster(),
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            ['100%' => 'Excellent']
        );

        $this->assertSame('wouldFill', $preview['rows'][0]['status']);
        $this->assertSame('100%', $preview['rows'][0]['normalizedScore']);
        $this->assertSame('100%', $preview['updates']['0000000001']['attainmentValue']);
    }

    public function testAssessmentDatesAreStrictlyValidated()
    {
        $this->assertTrue(\scoreLogIsValidDate('2026-02-28'));
        $this->assertFalse(\scoreLogIsValidDate('2026-02-30'));
        $this->assertFalse(\scoreLogIsValidDate('28/02/2026'));
    }

    public function testDraftPayloadUsesStableStudentIDsInsteadOfRowOrder()
    {
        $payload = \scoreLogBuildDraftPayload([
            'name' => 'Unit 1',
            'description' => 'Baseline',
            'type' => 'Quiz',
            'viewableStudents' => 'Y',
            'viewableParents' => 'N',
            'completeDate' => '2026-11-21',
            'score-0000000002' => '8',
            'comment-0000000002' => 'Good',
        ], [
            ['gibbonPersonID' => '0000000001', 'preferredName' => 'Ada', 'surname' => 'Lovelace'],
            ['gibbonPersonID' => '0000000002', 'preferredName' => 'Alan', 'surname' => 'Turing'],
        ]);

        $this->assertSame(['0000000001', '0000000002'], $payload['rosterIDs']);
        $this->assertSame('8', $payload['students']['0000000002']['attainmentValue']);
        $this->assertSame('', $payload['students']['0000000001']['attainmentValue']);
    }

    public function testDraftEvaluationSeparatesMissingAndInvalidScores()
    {
        $payload = [
            'meta' => [
                'name' => 'Unit 1',
                'description' => '',
                'type' => 'Quiz',
                'viewableStudents' => 'Y',
                'viewableParents' => 'Y',
                'completeDate' => '2026-11-21',
            ],
            'students' => [
                '1' => ['attainmentValue' => '80', 'comment' => ''],
                '2' => ['attainmentValue' => '', 'comment' => ''],
                '3' => ['attainmentValue' => '101', 'comment' => ''],
            ],
        ];

        $result = \scoreLogEvaluateDraftPayload(
            $payload,
            [
                ['gibbonPersonID' => '1', 'preferredName' => 'A', 'surname' => 'One'],
                ['gibbonPersonID' => '2', 'preferredName' => 'B', 'surname' => 'Two'],
                ['gibbonPersonID' => '3', 'preferredName' => 'C', 'surname' => 'Three'],
            ],
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            $this->numericGrades(),
            ['Quiz']
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(1, $result['entered']);
        $this->assertSame(1, $result['missing']);
        $this->assertSame(1, $result['invalid']);
        $this->assertArrayHasKey('score', $result['rows'][2]['errors']);
    }

    public function testNegativeNumericScoresAreRejectedWithoutAConfiguredMaximum()
    {
        $result = \scoreLogEvaluateDraftPayload([
            'meta' => [
                'name' => 'Unit 1',
                'description' => '',
                'type' => 'Quiz',
                'viewableStudents' => 'Y',
                'viewableParents' => 'Y',
                'completeDate' => '2026-11-21',
            ],
            'students' => [
                '1' => ['attainmentValue' => '-1', 'comment' => ''],
            ],
        ], [
            ['gibbonPersonID' => '1', 'preferredName' => 'A', 'surname' => 'One'],
        ], [
            'numeric' => 'Y',
            'usage' => '',
        ], $this->numericGrades(), ['Quiz']);

        $this->assertFalse($result['valid']);
        $this->assertSame(1, $result['invalid']);
    }

    public function testDraftEvaluationReportsRosterChanges()
    {
        $payload = [
            'rosterIDs' => ['1', '2'],
            'studentNames' => ['1' => 'A One', '2' => 'B Two'],
        ];
        $diff = \scoreLogRosterDiff($payload, [
            ['gibbonPersonID' => '2', 'preferredName' => 'B', 'surname' => 'Two'],
            ['gibbonPersonID' => '3', 'preferredName' => 'C', 'surname' => 'Three'],
        ]);

        $this->assertTrue($diff['changed']);
        $this->assertSame(['3' => 'C Three'], $diff['added']);
        $this->assertSame(['1' => 'A One'], $diff['removed']);
        $this->assertSame(\scoreLogRosterHash([
            ['gibbonPersonID' => '2'],
            ['gibbonPersonID' => '3'],
        ]), \scoreLogRosterHash([
            ['gibbonPersonID' => '3'],
            ['gibbonPersonID' => '2'],
        ]));
    }

    public function testSavingAfterARosterChangePreservesTheOriginalDiffBaseline()
    {
        $previous = [
            'rosterIDs' => ['1', '2'],
            'studentNames' => ['1' => 'A One', '2' => 'B Two'],
        ];
        $saved = [
            'rosterIDs' => ['2', '3'],
            'studentNames' => ['2' => 'B Two', '3' => 'C Three'],
        ];
        $payload = \scoreLogPreserveRosterBaseline($saved, $previous);
        $diff = \scoreLogRosterDiff($payload, [
            ['gibbonPersonID' => '2', 'preferredName' => 'B', 'surname' => 'Two'],
            ['gibbonPersonID' => '3', 'preferredName' => 'C', 'surname' => 'Three'],
        ]);

        $this->assertSame(['1', '2'], $payload['rosterIDs']);
        $this->assertTrue($diff['changed']);
        $this->assertSame(['3' => 'C Three'], $diff['added']);
        $this->assertSame(['1' => 'A One'], $diff['removed']);
    }

    public function testDraftListQuerySelectsClassForPermissionChecks()
    {
        $source = file_get_contents(dirname(__DIR__).'/scorelog_view.php');

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'SELECT d.scoreLogDraftID, d.gibbonCourseClassID',
            $source
        );
    }

    public function testChangedbMigrationUsesInstallerTerminator()
    {
        $source = file_get_contents(dirname(__DIR__).'/CHANGEDB.php');

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression('/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;end";/', $source);
    }

    public function testDraftSchemaDoesNotCreateACoreAssessmentColumn()
    {
        $manifest = file_get_contents(dirname(__DIR__).'/manifest.php');
        $changedb = file_get_contents(dirname(__DIR__).'/CHANGEDB.php');

        $this->assertIsString($manifest);
        $this->assertIsString($changedb);
        $this->assertStringNotContainsString('gibbonInternalAssessmentColumnID', $manifest);
        $this->assertStringNotContainsString('gibbonInternalAssessmentColumnID', $changedb);
    }

    public function testLegacyCreateEndpointIsFailClosedAndDiscardIsCreatorBound()
    {
        $legacy = file_get_contents(dirname(__DIR__).'/scorelog_createProcess.php');
        $discard = file_get_contents(dirname(__DIR__).'/scorelog_discardProcess.php');

        $this->assertIsString($legacy);
        $this->assertIsString($discard);
        $this->assertStringContainsString('return=error10', $legacy);
        $this->assertStringNotContainsString('INSERT INTO', $legacy);
        $this->assertStringContainsString('validateCsrfToken', $discard);
        $this->assertStringContainsString('gibbonPersonIDCreator=:gibbonPersonID', $discard);
        $this->assertStringContainsString('revision=:revision', $discard);
    }

    public function testHeaderlessImportAutoDetectsNameScoreAndSwappedColumns()
    {
        $roster = $this->importRoster();
        $scale = ['numeric' => 'Y', 'usage' => 'Out of 100'];

        $nameScore = \scoreLogDetectImportMapping([
            ['Ada Lovelace', '88'],
            ['Alan Turing', '92'],
        ], $roster, $scale, $this->numericGrades());
        $swapped = \scoreLogDetectImportMapping([
            ['88', 'Ada Lovelace'],
            ['92', 'Alan Turing'],
        ], $roster, $scale, $this->numericGrades());

        $this->assertSame(0, $nameScore['identifierColumn']);
        $this->assertSame('name', $nameScore['identifierType']);
        $this->assertSame(1, $nameScore['scoreColumn']);
        $this->assertSame(0, $swapped['scoreColumn']);
        $this->assertSame(1, $swapped['identifierColumn']);
    }

    public function testHeaderlessImportAutoDetectsZeroPaddedStudentIDs()
    {
        $mapping = \scoreLogDetectImportMapping([
            ['0000000001', '88'],
            ['2', '92'],
        ], $this->importRoster(), ['numeric' => 'Y', 'usage' => 'Out of 100'], $this->numericGrades());
        $username = \scoreLogDetectImportMapping([
            ['ADA', '88'],
            ['Alan', '92'],
        ], $this->importRoster(), ['numeric' => 'Y', 'usage' => 'Out of 100'], $this->numericGrades());

        $this->assertSame(0, $mapping['identifierColumn']);
        $this->assertSame('id', $mapping['identifierType']);
        $this->assertSame(1, $mapping['scoreColumn']);
        $this->assertSame('username', $username['identifierType']);
    }

    public function testHeaderDetectionSupportsCommentAndLeavesUnclearMappingEmpty()
    {
        $roster = $this->importRoster();
        $scale = ['numeric' => 'Y', 'usage' => 'Out of 100'];
        $header = \scoreLogDetectImportMapping([
            ['Student ID', 'Score', 'Comment'],
            ['0000000001', '88', 'Good'],
        ], $roster, $scale, $this->numericGrades());
        $unclear = \scoreLogDetectImportMapping([
            ['Ada Lovelace', 'Ada Lovelace'],
            ['Alan Turing', 'Alan Turing'],
        ], $roster, $scale, $this->numericGrades());
        $threeColumns = \scoreLogDetectImportMapping([
            ['Ada Lovelace', '88', 'Good'],
            ['Alan Turing', '92', 'Strong'],
        ], $roster, $scale, $this->numericGrades());

        $this->assertSame(0, $header['headerRow']);
        $this->assertSame(2, $header['commentColumn']);
        $this->assertSame(2, $threeColumns['commentColumn']);
        $this->assertNull($unclear['identifierColumn']);
        $this->assertNull($unclear['scoreColumn']);
    }

    public function testImportPreviewPreservesManualValuesUnlessOverwriteIsEnabled()
    {
        $mapping = [
            'headerRow' => null,
            'identifierColumn' => 0,
            'identifierType' => 'name',
            'scoreColumn' => 1,
            'commentColumn' => 2,
        ];
        $matrix = [['Ada Lovelace', '88', 'Imported']];
        $payload = [
            'students' => [
                '0000000001' => ['attainmentValue' => '70', 'comment' => 'Manual'],
            ],
        ];

        $preview = \scoreLogBuildImportPreview(
            $matrix,
            $mapping,
            $payload,
            $this->importRoster(),
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            $this->numericGrades(),
            [],
            false
        );
        $overwrite = \scoreLogBuildImportPreview(
            $matrix,
            $mapping,
            $payload,
            $this->importRoster(),
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            $this->numericGrades(),
            [],
            true
        );

        $this->assertSame('preserved', $preview['rows'][0]['status']);
        $this->assertEmpty($preview['updates']);
        $this->assertSame('wouldOverwrite', $overwrite['rows'][0]['status']);
        $this->assertSame('88', $overwrite['updates']['0000000001']['attainmentValue']);
    }

    public function testOverwriteModeTreatsBlankMappedCellsAsExplicitClears()
    {
        $mapping = [
            'headerRow' => null,
            'identifierColumn' => 0,
            'identifierType' => 'name',
            'scoreColumn' => 1,
            'commentColumn' => 2,
        ];
        $preview = \scoreLogBuildImportPreview(
            [['Ada Lovelace', '', '']],
            $mapping,
            [
                'students' => [
                    '0000000001' => ['attainmentValue' => '70', 'comment' => 'Manual'],
                ],
            ],
            $this->importRoster(),
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            $this->numericGrades(),
            [],
            true
        );

        $this->assertSame('wouldClear', $preview['rows'][0]['status']);
        $this->assertSame(['Score', 'Comment'], $preview['rows'][0]['clears']);
        $this->assertSame('', $preview['updates']['0000000001']['attainmentValue']);
        $this->assertSame('', $preview['updates']['0000000001']['comment']);
    }

    public function testPreviewHashChangesWhenSkippedRowOutcomeChanges()
    {
        $mapping = [
            'headerRow' => null,
            'identifierColumn' => 0,
            'identifierType' => 'id',
            'scoreColumn' => 1,
            'commentColumn' => null,
        ];
        $first = \scoreLogBuildImportPreview(
            [['999', '80']],
            $mapping,
            ['students' => []],
            $this->importRoster(),
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            $this->numericGrades()
        );
        $second = \scoreLogBuildImportPreview(
            [['998', '80']],
            $mapping,
            ['students' => []],
            $this->importRoster(),
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            $this->numericGrades()
        );

        $this->assertSame($first['updates'], $second['updates']);
        $this->assertNotSame(
            \scoreLogImportPreviewHash($mapping, $first, 3),
            \scoreLogImportPreviewHash($mapping, $second, 3)
        );
    }

    public function testMultilineCommentsSurviveImportRoundTripUnchanged()
    {
        $mapping = [
            'headerRow' => null,
            'identifierColumn' => 0,
            'identifierType' => 'name',
            'scoreColumn' => 1,
            'commentColumn' => 2,
        ];
        $comment = "First line\nSecond line";
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Ada Lovelace', '88', $comment], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'scorelog-roundtrip-');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $parsed = \scoreLogParseImportFile($path, 'xlsx');
        unlink($path);
        $preview = \scoreLogBuildImportPreview(
            $parsed['matrix'],
            $mapping,
            [
                'students' => [
                    '0000000001' => ['attainmentValue' => '88', 'comment' => $comment],
                ],
            ],
            $this->importRoster(),
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            $this->numericGrades(),
            [],
            true
        );

        $this->assertSame($comment, $preview['rows'][0]['importComment']);
        $this->assertSame('preserved', $preview['rows'][0]['status']);
        $this->assertEmpty($preview['updates']);
        $this->assertSame("AB\tC\nD", \scoreLogNormalizeImportComment(" A\x00B\tC\r\nD "));
    }

    public function testImportPreviewRejectsFormulaUnknownDuplicateAndAmbiguousRows()
    {
        $roster = $this->importRoster();
        $roster[] = [
            'gibbonPersonID' => '0000000003', 'studentID' => '0000000003',
            'username' => 'ada2', 'preferredName' => 'Ada', 'surname' => 'Lovelace',
        ];
        $mapping = [
            'headerRow' => null,
            'identifierColumn' => 0,
            'identifierType' => 'name',
            'scoreColumn' => 1,
            'commentColumn' => null,
        ];
        $preview = \scoreLogBuildImportPreview([
            ['Ada Lovelace', '=1+1'],
            ['Unknown Student', '80'],
            ['Ada Lovelace', '85'],
        ], $mapping, ['students' => []], $roster, ['numeric' => 'Y', 'usage' => 'Out of 100'], $this->numericGrades(), ['0:1' => true]);

        $this->assertSame('ambiguous', $preview['rows'][0]['status']);
        $this->assertSame('unknown', $preview['rows'][1]['status']);
        $this->assertSame('ambiguous', $preview['rows'][2]['status']);

        $unique = \scoreLogBuildImportPreview([
            ['Ada Lovelace', '=1+1'],
            ['Unknown Student', '80'],
            ['Ada Lovelace', '85'],
        ], $mapping, ['students' => []], $this->importRoster(), ['numeric' => 'Y', 'usage' => 'Out of 100'], $this->numericGrades(), ['0:1' => true]);

        $this->assertSame('invalid', $unique['rows'][0]['status']);
        $this->assertSame('unknown', $unique['rows'][1]['status']);
        $this->assertSame('duplicate', $unique['rows'][2]['status']);
    }

    public function testPastedAndXlsxFormulaCellsAreFlaggedWithoutCalculation()
    {
        $paste = \scoreLogParseImportPaste("Ada Lovelace\t=1+1");
        $this->assertTrue($paste['formulaCells']['0:1']);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Ada Lovelace');
        $sheet->setCellValue('B1', '=1+1');
        $path = tempnam(sys_get_temp_dir(), 'scorelog-import-');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $parsed = \scoreLogParseImportFile($path, 'xlsx');
        unlink($path);

        $this->assertTrue($parsed['formulaCells']['0:1']);
        $this->assertSame('=1+1', $parsed['matrix'][0][1]);
    }

    public function testUtf8BomCsvParsesHeaderlessChineseData()
    {
        $path = tempnam(sys_get_temp_dir(), 'scorelog-csv-');
        file_put_contents($path, "\xEF\xBB\xBF张三\t88\n李四\t92\n");
        $parsed = \scoreLogParseImportFile($path, 'csv');
        unlink($path);

        $this->assertSame('张三', $parsed['matrix'][0][0]);
        $this->assertSame('88', $parsed['matrix'][0][1]);
    }

    public function testChineseFullNamesMatchWithoutSpacesAndKeepDuplicatesAmbiguous()
    {
        $roster = [[
            'gibbonPersonID' => '0000000010', 'studentID' => '0000000010',
            'username' => 'zhangsan', 'preferredName' => '三', 'surname' => '张',
        ]];
        $mapping = [
            'headerRow' => null,
            'identifierColumn' => 0,
            'identifierType' => 'name',
            'scoreColumn' => 1,
            'commentColumn' => null,
        ];
        $matched = \scoreLogBuildImportPreview(
            [['张三', '88']],
            $mapping,
            ['students' => []],
            $roster,
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            $this->numericGrades()
        );

        $duplicateRoster = array_merge($roster, [[
            'gibbonPersonID' => '0000000011', 'studentID' => '0000000011',
            'username' => 'zhangsan2', 'preferredName' => '三', 'surname' => '张',
        ]]);
        $ambiguous = \scoreLogBuildImportPreview(
            [['张三', '88']],
            $mapping,
            ['students' => []],
            $duplicateRoster,
            ['numeric' => 'Y', 'usage' => 'Out of 100'],
            $this->numericGrades()
        );

        $this->assertSame('wouldFill', $matched['rows'][0]['status']);
        $this->assertSame('ambiguous', $ambiguous['rows'][0]['status']);
    }

    public function testSavedDraftProgressCountsEnteredStudents()
    {
        $progress = \scoreLogDraftProgress([
            'students' => [
                '1' => ['attainmentValue' => '88', 'comment' => ''],
                '2' => ['attainmentValue' => '', 'comment' => 'Imported'],
                '3' => ['attainmentValue' => '0', 'comment' => ''],
            ],
        ]);

        $this->assertSame(2, $progress['entered']);
        $this->assertSame(3, $progress['total']);
    }

    public function testSavedDraftsRoutesAreRegisteredAndDiscardReturnsToTheList()
    {
        $manifest = file_get_contents(dirname(__DIR__).'/manifest.php');
        $discard = file_get_contents(dirname(__DIR__).'/scorelog_discardProcess.php');

        $this->assertIsString($manifest);
        $this->assertIsString($discard);
        $this->assertStringContainsString('scorelog_drafts.php', $manifest);
        $this->assertStringContainsString('scorelog_draftDelete.php', $manifest);
        $this->assertStringContainsString("'returnPage'", $discard);
        $this->assertStringContainsString('draftsURL', $discard);
    }

    public function testVersionOneDraftIsAdaptedToAStableLegacyColumn()
    {
        $payload = \scoreLogUpgradeDraftPayload([
            'version' => 1,
            'meta' => [
                'name' => 'Legacy assessment',
                'description' => 'Old draft',
                'type' => 'Quiz',
                'viewableStudents' => 'Y',
                'viewableParents' => 'N',
                'completeDate' => '2026-11-21',
            ],
            'students' => [
                '0000000001' => ['attainmentValue' => '88', 'comment' => 'Good'],
            ],
            'rosterIDs' => ['0000000001'],
        ], '00026');

        $this->assertSame(2, $payload['version']);
        $this->assertSame(['legacy'], $payload['columnOrder']);
        $this->assertSame('00026', $payload['columns']['legacy']['scaleID']);
        $this->assertSame('88', $payload['students']['0000000001']['scores']['legacy']['attainmentValue']);
    }

    public function testMultiAssessmentBuilderPreservesColumnOrderAndIsolatesScores()
    {
        $payload = \scoreLogBuildMultiAssessmentPayload([
            'columnOrder' => 'c2,c1',
            'columns' => [
                'c1' => [
                    'name' => 'Quiz', 'description' => '', 'type' => 'Quiz',
                    'scaleID' => '26', 'completeDate' => '2026-11-21',
                    'viewableStudents' => 'Y', 'viewableParents' => 'N',
                ],
                'c2' => [
                    'name' => 'Exam', 'description' => '', 'type' => 'Exam',
                    'scaleID' => '27', 'completeDate' => '2026-11-22',
                    'viewableStudents' => 'N', 'viewableParents' => 'Y',
                ],
            ],
            'scores' => [
                '0000000001' => [
                    'c1' => ['attainmentValue' => '88', 'comment' => 'Quiz note'],
                    'c2' => ['attainmentValue' => 'A', 'comment' => 'Exam note'],
                ],
            ],
        ], [[
            'gibbonPersonID' => '0000000001',
            'preferredName' => 'Ada',
            'surname' => 'Lovelace',
        ]]);

        $this->assertSame(['c2', 'c1'], $payload['columnOrder']);
        $this->assertSame('A', $payload['students']['0000000001']['scores']['c2']['attainmentValue']);
        $this->assertSame('88', $payload['students']['0000000001']['scores']['c1']['attainmentValue']);

        $removed = \scoreLogBuildMultiAssessmentPayload([
            'columnOrder' => 'c1',
            'columns' => [
                'c1' => [
                    'name' => 'Quiz', 'description' => '', 'type' => 'Quiz',
                    'scaleID' => '26', 'completeDate' => '2026-11-21',
                    'viewableStudents' => 'Y', 'viewableParents' => 'N',
                ],
                'c2' => [
                    'name' => 'Exam', 'description' => '', 'type' => 'Exam',
                    'scaleID' => '27', 'completeDate' => '2026-11-22',
                    'viewableStudents' => 'N', 'viewableParents' => 'Y',
                ],
            ],
            'scores' => [
                '0000000001' => [
                    'c1' => ['attainmentValue' => '88', 'comment' => ''],
                    'c2' => ['attainmentValue' => 'A', 'comment' => ''],
                ],
            ],
        ], [[
            'gibbonPersonID' => '0000000001',
            'preferredName' => 'Ada',
            'surname' => 'Lovelace',
        ]]);

        $this->assertSame(['c1'], $removed['columnOrder']);
        $this->assertArrayNotHasKey('c2', $removed['columns']);
        $this->assertArrayNotHasKey('c2', $removed['students']['0000000001']['scores']);
    }

    public function testMultiAssessmentEvaluationUsesPerColumnScalesAndRejectsDuplicatesAndEmptyColumns()
    {
        $roster = [[
            'gibbonPersonID' => '0000000001',
            'preferredName' => 'Ada',
            'surname' => 'Lovelace',
        ]];
        $payload = [
            'version' => 2,
            'columnOrder' => ['c1', 'c2', 'c3'],
            'columns' => [
                'c1' => [
                    'name' => 'Quiz', 'description' => '', 'type' => 'Quiz',
                    'scaleID' => '26', 'completeDate' => '2026-11-21',
                    'viewableStudents' => 'Y', 'viewableParents' => 'Y',
                ],
                'c2' => [
                    'name' => 'Quiz', 'description' => '', 'type' => 'Exam',
                    'scaleID' => '27', 'completeDate' => '2026-11-22',
                    'viewableStudents' => 'Y', 'viewableParents' => 'Y',
                ],
                'c3' => [
                    'name' => 'Empty', 'description' => '', 'type' => 'Exam',
                    'scaleID' => '26', 'completeDate' => '2026-11-23',
                    'viewableStudents' => 'Y', 'viewableParents' => 'Y',
                ],
            ],
            'students' => [
                '0000000001' => ['scores' => [
                    'c1' => ['attainmentValue' => '88', 'comment' => ''],
                    'c2' => ['attainmentValue' => 'A', 'comment' => ''],
                    'c3' => ['attainmentValue' => '', 'comment' => ''],
                ]],
            ],
        ];
        $result = \scoreLogEvaluateMultiAssessmentPayload(
            $payload,
            $roster,
            [
                '26' => ['name' => 'Numeric', 'usage' => 'Out of 100', 'numeric' => 'Y', 'active' => 'Y'],
                '27' => ['name' => 'Grades', 'usage' => '', 'numeric' => 'N', 'active' => 'Y'],
            ],
            ['26' => ['88' => '88'], '27' => ['A' => 'Excellent']],
            ['Quiz', 'Exam']
        );

        $this->assertFalse($result['valid']);
        $this->assertSame(1, $result['columns']['c1']['entered']);
        $this->assertSame(1, $result['columns']['c2']['entered']);
        $this->assertContains('Assessment column names must be unique within the draft.', $result['columns']['c2']['errors']);
        $this->assertContains('This assessment column has no entered scores.', $result['columns']['c3']['errors']);
    }

    public function testMultiAssessmentProgressCountsAllColumnSlots()
    {
        $progress = \scoreLogDraftProgress([
            'version' => 2,
            'columnOrder' => ['c1', 'c2'],
            'columns' => ['c1' => [], 'c2' => []],
            'students' => [
                '1' => ['scores' => [
                    'c1' => ['attainmentValue' => '88'],
                    'c2' => ['attainmentValue' => ''],
                ]],
                '2' => ['scores' => [
                    'c1' => ['attainmentValue' => ''],
                    'c2' => ['attainmentValue' => 'A'],
                ]],
            ],
        ]);

        $this->assertSame(2, $progress['entered']);
        $this->assertSame(4, $progress['total']);
    }

    public function testMultiAssessmentPublishUsesUniqueGroupingIDsAndOneTransaction()
    {
        $source = file_get_contents(dirname(__DIR__).'/scorelog_publishProcess.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("SELECT COALESCE(MAX(groupingID), 0) + 1", $source);
        $this->assertStringContainsString('$insertColumn($firstColumn, 0)', $source);
        $this->assertStringContainsString('$insertColumn($column, $nextGroupingID++)', $source);
        $this->assertStringContainsString('scoreLogCoreAssessmentValues', $source);
        $this->assertStringContainsString('scoreLogCoreEntryValues', $source);
        $this->assertSame(1, substr_count($source, '->beginTransaction()'));
        $this->assertStringContainsString('->rollBack()', $source);
        $this->assertStringContainsString('DELETE FROM score_log_draft', $source);
        $this->assertStringContainsString('->commit()', $source);
    }

    public function testCoreAssessmentMappingMatchesFormalAssessmentColumnDefaults()
    {
        $values = \scoreLogCoreAssessmentValues([
            'name' => 'Unit 1',
            'description' => 'Baseline assessment',
            'type' => 'Quiz',
            'scaleID' => '00026',
            'completeDate' => '2026-11-21',
            'viewableStudents' => 'Y',
            'viewableParents' => 'N',
        ], '00000042', '00000007', 81);

        $this->assertSame([
            'groupingID'                  => 81,
            'gibbonCourseClassID'         => '00000042',
            'name'                        => 'Unit 1',
            'description'                 => 'Baseline assessment',
            'type'                        => 'Quiz',
            'attainment'                  => 'Y',
            'gibbonScaleIDAttainment'     => '00026',
            'effort'                      => 'N',
            'gibbonScaleIDEffort'         => null,
            'comment'                     => 'Y',
            'uploadedResponse'            => 'N',
            'complete'                    => 'Y',
            'completeDate'                => '2026-11-21',
            'viewableStudents'            => 'Y',
            'viewableParents'             => 'N',
            'attachment'                  => '',
            'gibbonPersonIDCreator'       => '00000007',
            'gibbonPersonIDLastEdit'      => '00000007',
        ], $values);

        $incomplete = \scoreLogCoreAssessmentValues([
            'completeDate' => '',
        ], '42', '7', 82);
        $this->assertSame('N', $incomplete['complete']);
        $this->assertNull($incomplete['completeDate']);
    }

    public function testCoreEntryMappingMatchesFormalAssessmentEntryDefaults()
    {
        $values = \scoreLogCoreEntryValues(93, [
            'gibbonPersonID' => '00000005',
            'attainmentValue' => '12.5',
            'descriptor' => '12.5',
            'comment' => 'Clear improvement',
        ], '00000007');

        $this->assertSame([
            'gibbonInternalAssessmentColumnID' => 93,
            'gibbonPersonIDStudent'            => '00000005',
            'attainmentValue'                  => '12.5',
            'attainmentDescriptor'             => '12.5',
            'effortValue'                      => null,
            'effortDescriptor'                 => null,
            'comment'                          => 'Clear improvement',
            'response'                         => null,
            'gibbonPersonIDLastEdit'           => '00000007',
        ], $values);
    }

    public function testMultiAssessmentValidationEnforcesOfficialDescriptionAndTypeLimits()
    {
        $result = \scoreLogEvaluateMultiAssessmentPayload([
            'version' => 2,
            'columnOrder' => ['c1'],
            'columns' => [
                'c1' => [
                    'name' => 'Quiz',
                    'description' => '',
                    'type' => str_repeat('T', 51),
                    'scaleID' => '26',
                    'completeDate' => '2026-11-21',
                    'viewableStudents' => 'Y',
                    'viewableParents' => 'Y',
                ],
            ],
            'students' => [
                '0000000001' => ['scores' => [
                    'c1' => ['attainmentValue' => '88', 'comment' => ''],
                ]],
            ],
        ], [[
            'gibbonPersonID' => '0000000001',
            'preferredName' => 'Ada',
            'surname' => 'Lovelace',
        ]], [
            '26' => ['name' => 'Numeric', 'usage' => 'Out of 100', 'numeric' => 'Y', 'active' => 'Y'],
        ], ['26' => ['88' => '88']], [str_repeat('T', 51)]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Enter an assessment description.', $result['columns']['c1']['errors']);
        $this->assertContains('The assessment type must be 50 characters or fewer.', $result['columns']['c1']['errors']);
    }

    public function testFormalAssessmentTypeSettingIsRequired()
    {
        $result = \scoreLogEvaluateMultiAssessmentPayload([
            'version' => 2,
            'columnOrder' => ['c1'],
            'columns' => [
                'c1' => [
                    'name' => 'Quiz',
                    'description' => 'Baseline',
                    'type' => 'Quiz',
                    'scaleID' => '26',
                    'completeDate' => '2026-11-21',
                    'viewableStudents' => 'Y',
                    'viewableParents' => 'Y',
                ],
            ],
            'students' => [
                '0000000001' => ['scores' => [
                    'c1' => ['attainmentValue' => '88', 'comment' => ''],
                ]],
            ],
        ], [[
            'gibbonPersonID' => '0000000001',
            'preferredName' => 'Ada',
            'surname' => 'Lovelace',
        ]], [
            '26' => ['name' => 'Numeric', 'usage' => 'Out of 100', 'numeric' => 'Y', 'active' => 'Y'],
        ], ['26' => ['88' => '88']], []);

        $this->assertFalse($result['valid']);
        $this->assertContains('Choose a valid assessment type.', $result['columns']['c1']['errors']);
    }

    public function testScoreEntryRoutesRequireFormalAssessmentWritePermission()
    {
        $routes = [
            'scorelog_view.php',
            'scorelog_draftProcess.php',
            'scorelog_review.php',
            'scorelog_drafts.php',
            'scorelog_import.php',
            'scorelog_importProcess.php',
            'scorelog_importApplyProcess.php',
            'scorelog_importTemplate.php',
            'scorelog_publishProcess.php',
            'scorelog_draftDelete.php',
            'scorelog_discardProcess.php',
        ];

        foreach ($routes as $route) {
            $source = file_get_contents(dirname(__DIR__).'/'.$route);
            $this->assertIsString($source);
            $this->assertStringContainsString(
                '/modules/Formal Assessment/internalAssessment_write_data.php',
                $source
            );
        }

        $functions = file_get_contents(dirname(__DIR__).'/moduleFunctions.php');
        $this->assertIsString($functions);
        $this->assertStringContainsString("AND role='Teacher'", $functions);
        $this->assertStringNotContainsString("role IN ('Teacher', 'Assistant', 'Technician')", $functions);
    }

    public function testTeacherQuickEntryUsesCheckedStudentsPreviewAndRevisionBoundUndo()
    {
        $view = file_get_contents(dirname(__DIR__).'/scorelog_view.php');
        $script = file_get_contents(dirname(__DIR__).'/js/module.js');

        $this->assertIsString($view);
        $this->assertIsString($script);
        foreach ([
            'scoreLogOnlyMissing',
            'scoreLogNextMissing',
            'scoreLogBulkValue',
            'scoreLogBulkOverwrite',
            'scoreLogBulkFill',
            'scoreLogBulkClear',
            'scoreLogBulkPreview',
            'scoreLogBulkUndo',
            'scoreLogStudentSelect',
        ] as $controlID) {
            $this->assertStringContainsString($controlID, $view);
        }
        $this->assertStringContainsString("addHiddenValue('revision'", $view);
        $this->assertStringContainsString('focusNextMissing', $script);
        $this->assertStringContainsString('checkedScoreRows', $script);
        $this->assertStringContainsString('!row.hidden && checkbox && checkbox.checked', $script);
        $this->assertStringContainsString('bulkOverwriteInput', $script);
        $this->assertStringContainsString("labels.confirmBulkClear", $script);
        $this->assertStringContainsString('revisionInput.value', $script);
        $this->assertStringContainsString("event.key.toLowerCase() === 'n'", $script);
        $this->assertStringContainsString('invalidateUndoForRow', $script);
    }

    public function testPrivateCommentLibraryAndStructureReuseRemainDraftOnly()
    {
        $manifest = file_get_contents(dirname(__DIR__).'/manifest.php');
        $changedb = file_get_contents(dirname(__DIR__).'/CHANGEDB.php');
        $process = file_get_contents(dirname(__DIR__).'/scorelog_commentProcess.php');
        $view = file_get_contents(dirname(__DIR__).'/scorelog_view.php');
        $script = file_get_contents(dirname(__DIR__).'/js/module.js');
        $version = file_get_contents(dirname(__DIR__).'/version.php');

        foreach ([$manifest, $changedb, $process, $view, $script, $version] as $source) {
            $this->assertIsString($source);
        }
        $this->assertStringContainsString("\$version     = '1.4.1'", $manifest);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `score_log_draft`', $changedb);
        $this->assertStringContainsString("\$moduleVersion = '1.4.1'", $version);
        $this->assertStringContainsString('CREATE TABLE `score_log_comment`', $manifest);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `score_log_comment`', $changedb);
        $this->assertStringContainsString('scorelog_comments.php', $manifest);
        $this->assertStringContainsString('scorelog_commentManage.php', $manifest);
        $this->assertStringContainsString('scorelog_commentProcess.php', $manifest);
        $this->assertStringContainsString('validateCsrfToken', $process);
        $this->assertStringContainsString('gibbonPersonIDCreator=:gibbonPersonID', $process);
        $this->assertStringContainsString('window.scoreLogCommentLibrary', $view);
        $this->assertStringContainsString('scoreLogCommentLibraryInsert', $view);
        $this->assertStringContainsString('Append comment', $view);
        $this->assertStringContainsString('existing + separator + comment.comment', $script);
        $this->assertStringContainsString('scoreLogStructureSource', $view);
        $this->assertStringContainsString('window.scoreLogReusableAssessments', $view);
        $this->assertStringContainsString('copyStructure', $script);
        $this->assertStringContainsString('Assessment details copied without scores or comments.', $view);

        $this->assertStringContainsString("addSelectGradeScaleGrade(", $view);
        $this->assertStringContainsString("addTable()", $view);
        $this->assertStringNotContainsString('Add Assessment Column', $view);
        $this->assertStringContainsString('count($savedColumnOrder) > 1', $view);
        $this->assertStringContainsString('count($columnOrder) !== 1', file_get_contents(dirname(__DIR__).'/scorelog_draftProcess.php'));
    }

    public function testPrivateCommentValidationRequiresLabelAndCommentLimits()
    {
        $valid = \scoreLogValidateComment([
            'label' => ' Effort ',
            'comment' => ' Consistent effort this term. ',
        ]);
        $missingLabel = \scoreLogValidateComment([
            'label' => '',
            'comment' => 'Comment',
        ]);
        $longLabel = \scoreLogValidateComment([
            'label' => str_repeat('L', 81),
            'comment' => 'Comment',
        ]);
        $missingComment = \scoreLogValidateComment([
            'label' => 'Label',
            'comment' => ' ',
        ]);
        $longComment = \scoreLogValidateComment([
            'label' => 'Label',
            'comment' => str_repeat('C', 1001),
        ]);

        $this->assertTrue($valid['valid']);
        $this->assertSame('Effort', $valid['label']);
        $this->assertSame('Consistent effort this term.', $valid['comment']);
        $this->assertFalse($missingLabel['valid']);
        $this->assertFalse($longLabel['valid']);
        $this->assertFalse($missingComment['valid']);
        $this->assertFalse($longComment['valid']);
    }

    public function testImportAndTemplateAreBoundToOneExplicitAssessmentColumn()
    {
        $import = file_get_contents(dirname(__DIR__).'/scorelog_importProcess.php');
        $apply = file_get_contents(dirname(__DIR__).'/scorelog_importApplyProcess.php');
        $template = file_get_contents(dirname(__DIR__).'/scorelog_importTemplate.php');

        foreach ([$import, $apply, $template] as $source) {
            $this->assertIsString($source);
            $this->assertStringContainsString("'columnID'", $source);
            $this->assertStringContainsString("['columns'][\$columnID]", $source);
        }
        $this->assertStringContainsString("['scores'][\$columnID]", $apply);
    }

    private function importRoster(): array
    {
        return [
            [
                'gibbonPersonID' => '0000000001', 'studentID' => '0000000001',
                'username' => 'ada', 'preferredName' => 'Ada', 'surname' => 'Lovelace',
            ],
            [
                'gibbonPersonID' => '0000000002', 'studentID' => '0000000002',
                'username' => 'alan', 'preferredName' => 'Alan', 'surname' => 'Turing',
            ],
        ];
    }

    private function numericGrades(): array
    {
        $grades = [];
        for ($value = 0; $value <= 100; $value++) {
            $grades[(string) $value] = (string) $value;
        }

        return $grades;
    }
    }
}
