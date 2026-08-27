#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/assert-coverage.php <clover.xml>\n");
    exit(2);
}

$report = @simplexml_load_file($argv[1]);
if (!$report instanceof SimpleXMLElement) {
    fwrite(STDERR, sprintf("Unable to read Clover report: %s\n", $argv[1]));
    exit(2);
}

$metrics = $report->project->metrics;
$checks = [
    'lines' => [(int) $metrics['statements'], (int) $metrics['coveredstatements']],
];
$failed = false;

foreach ($checks as $name => [$total, $covered]) {
    $percentage = $total === 0 ? 100.0 : ($covered / $total) * 100;
    fwrite(STDOUT, sprintf(
        "%-22s %d/%d (%0.2f%%)\n",
        ucfirst($name) . ':',
        $covered,
        $total,
        $percentage,
    ));

    if ($covered !== $total) {
        $failed = true;
    }
}

if ($failed) {
    fwrite(STDERR, "Coverage must be exactly 100%.\n");
    exit(1);
}

fwrite(STDOUT, "Coverage is exactly 100%.\n");
