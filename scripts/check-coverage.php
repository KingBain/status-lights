<?php

declare(strict_types=1);

$coveragePath = $argv[1] ?? 'clover.xml';
$coverage = @simplexml_load_file($coveragePath);

if (!$coverage instanceof SimpleXMLElement || !isset($coverage->project->metrics)) {
    fwrite(STDERR, "Unable to read Clover coverage report: {$coveragePath}\n");
    exit(2);
}

$failures = [];
$metrics = $coverage->project->metrics;
foreach (
    [
        'methods' => 'coveredmethods',
        'statements' => 'coveredstatements',
        'elements' => 'coveredelements',
    ] as $totalName => $coveredName
) {
    $total = (int) $metrics[$totalName];
    $covered = (int) $metrics[$coveredName];
    if ($covered !== $total) {
        $failures[] = sprintf('%s: %d/%d', $totalName, $covered, $total);
    }
}

foreach ($coverage->project->file as $file) {
    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt' && (int) $line['count'] === 0) {
            $failures[] = sprintf('%s:%s is uncovered', (string) $file['name'], (string) $line['num']);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Coverage must remain at 100%.\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "Coverage requirement met: 100%.\n";
