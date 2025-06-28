<?php

require_once 'Logger.php';
require_once 'Comparator.php';

$options = getopt('', [
    'tables:',      // --tables=users,orders
    'label:',       // --label=before|after
    'hashing',      // --hashing  (no value, just a toggle)
    'output:',      // --output=filename.txt
    'no-color',     // --no-color to disable ANSI colors
    'no-truncate',  // --no-truncate to disable value truncation
]);

$tables   = isset($options['tables'])
          ? array_map('trim', explode(',', $options['tables']))
          : [];

     
$label    = $options['label']   ?? 'before';
$useHash  = isset($options['hashing']);
$outputFile  = $options['output']  ?? null;
$disableAnsi = isset($options['no-color']);
$disableTrunc  = isset($options['no-truncate']);


// Load config
$config = json_decode(file_get_contents('config.json'), true);
$beforeDir = $config['snapshot_dir'] . '/before';
$afterDir  = $config['snapshot_dir'] . '/after';
$baseDir = $config['snapshot_dir'];

// Get configuration options
$useCursor = $config['use_cursor'] ?? false;
$useHashing = $config['use_hashing'] ?? false;
$tables = $config['tables'] ?? [];

// Create Logger and Comparator objects
$logger = new Logger();
$comparator = new Comparator(
    $baseDir,
    $beforeDir, 
    $afterDir, 
    $logger, 
    $tables, 
    $config['use_cursor'] ?? false,
    $config['use_hashing'] ?? false,
    $disableAnsi,
    $disableTrunc,
    $outputFile
);

// Compare snapshots
$comparator->compare($label, $tables, $useHash);