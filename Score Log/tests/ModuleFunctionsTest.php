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
            [],
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
        ], [], ['Quiz']);

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
            'SELECT d.scoreLogDraftID, d.gibbonCourseClassID, d.revision, d.payload, d.updateTimestamp',
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
    }
}
