#!/usr/bin/env php
<?php

declare(strict_types=1);

define('STATUS_LIGHTS_APP_TESTING', true);
require dirname(__DIR__) . '/generator/app.php';

$runRetentionDays = status_lights_environment_integer(
    'STATUS_LIGHTS_RUN_RETENTION_DAYS',
    7,
    1,
    365,
);
$deliveryRetentionDays = status_lights_environment_integer(
    'STATUS_LIGHTS_DELIVERY_RETENTION_DAYS',
    7,
    1,
    365,
);
$intervalSeconds = status_lights_environment_integer(
    'STATUS_LIGHTS_RUN_PRUNE_INTERVAL_SECONDS',
    86400,
    300,
    604800,
);
$storeDirectory = status_lights_app_store_dir();

if (!is_dir($storeDirectory) && !@mkdir($storeDirectory, 0755, true) && !is_dir($storeDirectory)) {
    throw new RuntimeException('Unable to create the app data directory for run pruning.');
}

$lock = @fopen($storeDirectory . '/.run-prune.lock', 'c+');
if (!is_resource($lock)) {
    throw new RuntimeException('Unable to open the run pruning lock.');
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    exit(0);
}

$lastRun = trim((string) stream_get_contents($lock));
$now = time();
if (ctype_digit($lastRun) && ($now - (int) $lastRun) < $intervalSeconds) {
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

$runCutoff = $now - ($runRetentionDays * 86400);
$deliveryCutoff = $now - ($deliveryRetentionDays * 86400);
$deletedRuns = status_lights_app_prune_runs_older_than($runCutoff);
$deletedDeliveries = status_lights_app_prune_deliveries_older_than($deliveryCutoff);

rewind($lock);
ftruncate($lock, 0);
fwrite($lock, (string) $now);
fflush($lock);
flock($lock, LOCK_UN);
fclose($lock);

if ($deletedRuns > 0 || $deletedDeliveries > 0) {
    fwrite(STDOUT, sprintf(
        "Pruned %d expired run record(s) and %d expired delivery record(s).\n",
        $deletedRuns,
        $deletedDeliveries,
    ));
}
