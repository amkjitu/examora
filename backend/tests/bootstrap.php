<?php

/**
 * Test bootstrap.
 *
 * Wave resolves the active theme, settings and Folio page mounts from the
 * database while its service provider boots. That happens *before* a test's
 * setUp runs, so `RefreshDatabase` — which migrates after the app is already
 * booted — leaves Wave's own page routes unregistered and its web tests 404.
 *
 * Instead we build a migrated and seeded SQLite file once, before PHPUnit
 * starts, and isolate individual tests with transactions (see tests/Pest.php).
 * Wave boots against a populated database exactly as it does in development.
 *
 * The file is rebuilt automatically whenever a migration or seeder is newer
 * than it, so there is nothing to remember to run by hand.
 */

// Filament and Livewire boot a large object graph per test; the stock 128M is
// not enough for the full suite in one process.
ini_set('memory_limit', '512M');

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$database = $root.'/database/testing.sqlite';

$newestSource = 0;

foreach (['/database/migrations/*.php', '/database/seeders/*.php', '/wave/database/migrations/*.php'] as $pattern) {
    foreach (glob($root.$pattern) ?: [] as $file) {
        $newestSource = max($newestSource, filemtime($file));
    }
}

$needsBuild = ! file_exists($database) || filemtime($database) < $newestSource;

if ($needsBuild) {
    @unlink($database);
    touch($database);

    $env = [
        'APP_ENV=testing',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE='.escapeshellarg($database),
        'DB_FOREIGN_KEYS=true',
        'CACHE_STORE=array',
        'QUEUE_CONNECTION=sync',
        'SESSION_DRIVER=array',
        'AI_DEFAULT_PROVIDER=mock',
    ];

    $command = implode(' ', $env).' php '.escapeshellarg($root.'/artisan').' migrate --seed --force 2>&1';

    exec($command, $output, $status);

    if ($status !== 0) {
        fwrite(STDERR, "Failed to build the testing database:\n".implode("\n", $output)."\n");
        exit(1);
    }

    touch($database);
}
