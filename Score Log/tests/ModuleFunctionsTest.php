<?php

namespace {
    if (!function_exists('__')) {
        function __($text)
        {
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
    }
}
