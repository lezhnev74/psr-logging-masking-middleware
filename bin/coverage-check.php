<?php

declare(strict_types=1);

/**
 * coverage-check.php - fail the build when quality gates are not met.
 *
 * Parses a PHPUnit Clover report and enforces two thresholds:
 *   1. Minimum total line coverage (percent).
 *   2. Maximum CRAP score for any single method.
 *
 * Usage: php bin/coverage-check.php <clover.xml> <min-line-coverage> <max-crap>
 * Example: php bin/coverage-check.php coverage.xml 85 5
 *
 * Exits 0 when both gates pass, 1 otherwise. Standalone (no autoload) so it
 * runs in a bare CI step.
 */

$cloverPath = $argv[1] ?? 'coverage.xml';
$minCoverage = (float) ($argv[2] ?? 85);
$maxCrap = (float) ($argv[3] ?? 5);

if (!is_file($cloverPath)) {
    fwrite(STDERR, "coverage-check: clover report not found at {$cloverPath}\n");
    exit(1);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "coverage-check: could not parse {$cloverPath}\n");
    exit(1);
}

$failures = [];

// --- Gate 1: total line coverage -------------------------------------------
$metrics = $xml->project->metrics ?? null;
$statements = $metrics !== null ? (int) $metrics['statements'] : 0;
$covered = $metrics !== null ? (int) $metrics['coveredstatements'] : 0;
$coverage = $statements > 0 ? ($covered / $statements) * 100 : 100.0;

printf("Line coverage: %.2f%% (%d/%d statements), floor %.0f%%\n", $coverage, $covered, $statements, $minCoverage);
if ($coverage < $minCoverage) {
    $failures[] = sprintf('line coverage %.2f%% is below the %.0f%% floor', $coverage, $minCoverage);
}

// --- Gate 2: per-method CRAP -----------------------------------------------
// Clover records a `crap` attribute on each <line type="method">.
$offenders = [];
foreach ($xml->xpath('//file') as $file) {
    $fileName = (string) $file['name'];
    foreach ($file->xpath('.//line[@type="method"]') as $line) {
        $crap = (float) $line['crap'];
        if ($crap > $maxCrap) {
            $offenders[] = sprintf('  %s:%s %s() CRAP=%.2f', $fileName, (string) $line['num'], (string) $line['name'], $crap);
        }
    }
}

if ($offenders !== []) {
    $failures[] = sprintf("%d method(s) exceed CRAP %.0f:\n%s", count($offenders), $maxCrap, implode("\n", $offenders));
} else {
    printf("CRAP: every method is at or below %.0f\n", $maxCrap);
}

if ($failures !== []) {
    fwrite(STDERR, "\ncoverage-check FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "coverage-check passed\n";
exit(0);
