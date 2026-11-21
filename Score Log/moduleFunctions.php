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
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

function scoreLogT($text, array $params = []): string
{
    global $session;

    if (function_exists('__m') && is_object($session) && method_exists($session, 'has') && $session->has('module')) {
        $translated = __m($text, $params);

        return $translated !== $text ? $translated : __($text, $params);
    }

    return __($text, $params);
}

/**
 * Users who may target any class, in addition to their own teaching classes.
 */
function scoreLogCanViewAllClasses($gibbonPersonID): bool
{
    global $guid, $connection2;

    if (function_exists('canViewAllAssessmentClasses') && canViewAllAssessmentClasses($gibbonPersonID)) {
        return true;
    }

    if (empty($guid) || empty($connection2)) {
        return false;
    }

    $highest = getHighestGroupedAction(
        $guid,
        '/modules/Formal Assessment/internalAssessment_write_data.php',
        $connection2
    );

    return is_string($highest) && substr($highest, -4) === '_all';
}

/**
 * Default assessment scale, with the school's historical fallback.
 */
function scoreLogDefaultScaleID($session): string
{
    $scaleID = $session->get('defaultAssessmentScale');

    return !empty($scaleID) ? (string) $scaleID : '00026';
}

/**
 * Reads the maximum score from a scale usage such as "满分100分".
 */
function scoreLogMaxScoreFromUsage($usage): ?float
{
    $usage = trim((string) $usage);
    $patterns = [
        '/满分\s*[:：]?\s*(\d+(?:\.\d+)?)\s*分?/u',
        '/(\d+(?:\.\d+)?)\s*分制/u',
        '/\b(?:out\s+of|maximum(?:\s+score)?|max(?:imum)?)\s*[:=]?\s*(\d+(?:\.\d+)?)\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $usage, $matches)) {
            $max = (float) $matches[1];

            return is_finite($max) && $max > 0 ? $max : null;
        }
    }

    return null;
}

/**
 * Normalise a configured comma-separated assessment type list.
 */
function scoreLogAllowedTypes($types): array
{
    $types = is_scalar($types) ? (string) $types : '';
    $values = array_map('trim', explode(',', $types));

    return array_values(array_unique(array_filter($values, 'strlen')));
}

/**
 * Normalise a finite decimal score without accepting exponent notation.
 */
function scoreLogNormalizeNumericValue($value): ?string
{
    $value = is_scalar($value) ? trim((string) $value) : '';
    if (!preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $value)) {
        return null;
    }

    $number = (float) $value;
    if (!is_finite($number)) {
        return null;
    }

    $normalized = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    if ($normalized === '-0' || $normalized === '+0') {
        $normalized = '0';
    }

    return strlen($normalized) <= 10 ? $normalized : null;
}

/**
 * Default comment shown for each student row.
 */
function scoreLogDefaultComment(string $scaleName): string
{
    return scoreLogT('Grading scale:') . ' ' . $scaleName;
}
