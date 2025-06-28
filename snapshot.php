<?php

require_once 'Logger.php';
require_once 'Snapshotter.php';

// Load config
$config       = json_decode(file_get_contents('config.json'), true);
$useCursor    = $config['use_cursor']  ?? false;
$useHashing   = $config['use_hashing'] ?? false;
$tables       = $config['tables']      ?? [];
$snapshotDir  = $config['snapshot_dir'] ?? (__DIR__ . '/snapshots');

// Parse CLI options
$options = getopt('', [
    'tables:',   // e.g. --tables=users,orders
    'label:',    // e.g. --label=before|after
    'clear'      // --clear (no value)
]);

// Override tables if passed
if (isset($options['tables'])) {
    $tables = array_map('trim', explode(',', $options['tables']));
}

// Pick up label (default to "before")
$label = $options['label'] ?? 'before';

$logger      = new Logger();
$snapshotter = new Snapshotter(__DIR__.'/snapshots', $logger);

// If --clear was passed, delete everything under $snapshotDir
if (isset($options['clear'])) {
    if (!is_dir($snapshotDir)) {
        $logger->warning("Snapshot directory doesn’t exist: {$snapshotDir}");
    } else {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($snapshotDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = $fileinfo->isDir() ? 'rmdir' : 'unlink';
            $todo($fileinfo->getRealPath());
        }
        $logger->info("Cleared out snapshot directory: {$snapshotDir}");
    }

    // If no other action, exit
    if (!isset($options['label'])) {
        exit(0);
    }
}

// Finally take snapshot
$snapshotter->takeSnapshot($label, $tables, $useCursor, $useHashing);
