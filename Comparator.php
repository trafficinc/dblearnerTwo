<?php
// Comparator.php

require_once 'Logger.php';

class Comparator
{
    private string  $beforeDir;
    private string  $afterDir;
    private Logger  $logger;
    private array   $tables;
    private bool    $useCursor;
    private bool    $useHashing;
    private         $baseDir;
    private bool    $useAnsiColors;
    private bool    $disableTruncate;
    private ?string $outputFile;

    /**
     * @param string $beforeDir    Path to the “before” snapshot directory
     * @param string $afterDir     Path to the “after” snapshot directory
     * @param Logger $logger       A Logger instance
     * @param array  $filterTables If non-empty, only compare these tables
     * @param bool   $useCursor    If true, use cursor logic (unused here)
     * @param bool   $useHashing   If true, compare via hashes instead of full rows
     */
    
    /*     
    $baseDir,
    $beforeDir, 
    $afterDir, 
    $logger, 
    $tables, 
    $config['use_cursor'] ?? false,
    $config['use_hashing'] ?? false,
    $disableAnsi,
    $outputFile
    */
    public function __construct(
        $baseDir,
        string $beforeDir,
        string $afterDir,
        Logger $logger,
        array $filterTables = [],
        bool $useCursor     = false,
        bool $useHashing    = false,
        bool $disableAnsi = false,
        bool $disableTruncate = false,
        ?string $outputFile = null
    ) {
        $this->baseDir = $baseDir;
        $this->beforeDir  = rtrim($beforeDir, '/');
        $this->afterDir   = rtrim($afterDir, '/');
        $this->logger     = $logger;
        $this->tables     = $filterTables;
        $this->useCursor  = $useCursor;
        $this->useHashing = $useHashing;
        $this->useAnsiColors = ! $disableAnsi;
        $this->disableTruncate   = $disableTruncate;
        $this->outputFile    = $outputFile;
    }

    public function compare(string $label,
    array  $onlyTables    = [],
    bool   $useHashing    = false): void
    {
        $this->logger->info("Starting comparison…");

        // Grab all *_before.json.gz files
        //var_dump($this->beforeDir);
        //exit;

        $pattern      = $this->beforeDir . '/*_before.json.gz';
        $beforeFiles  = glob($pattern) ?: [];
        $this->logger->info("Found " . count($beforeFiles) . " before-snapshot files in {$this->beforeDir}");

        $diff = [];

        foreach ($beforeFiles as $beforeFile) {
            $table     = basename($beforeFile, '_before.json.gz');

            // If user passed a filter list, skip any not in it
            if (!empty($this->tables) && !in_array($table, $this->tables, true)) {
                $this->logger->info("Skipping table (not in filter): $table");
                continue;
            }

            $afterFile = "{$this->afterDir}/{$table}_after.json.gz";
            if (!file_exists($afterFile)) {
                $this->logger->warning("Missing after snapshot for table: $table");
                continue;
            }

            $this->logger->info("Comparing table: $table");

            // Load JSON (falling back to empty rows)
            $beforeData = json_decode(gzdecode(file_get_contents($beforeFile)), true) ?? [];
            $afterData  = json_decode(gzdecode(file_get_contents($afterFile)),  true) ?? [];
            $beforeRows = $beforeData['rows'] ?? [];
            $afterRows  = $afterData['rows']  ?? [];

            if ($this->useHashing) {
                $beforeHashes = array_column($beforeRows, 'hash', 'id');
                $afterHashes  = array_column($afterRows,  'hash', 'id');
                $inserts = array_diff_key($afterHashes, $beforeHashes);
                $deletes = array_diff_key($beforeHashes, $afterHashes);
                $updates = [];
                foreach ($beforeHashes as $id => $h) {
                    if (isset($afterHashes[$id]) && $h !== $afterHashes[$id]) {
                        $updates[] = [
                            'key'    => $id,
                            'before' => $beforeRows[$id] ?? null,
                            'after'  => $afterRows[$id]  ?? null,
                        ];
                    }
                }
            } else {
                $inserts = array_diff_key($afterRows, $beforeRows);
                $deletes = array_diff_key($beforeRows, $afterRows);
                $updates = [];
                foreach ($beforeRows as $id => $row) {
                    if (isset($afterRows[$id]) && $row !== $afterRows[$id]) {
                        $updates[] = [
                            'key'    => $id,
                            'before' => $row,
                            'after'  => $afterRows[$id],
                        ];
                    }
                }
            }

            if ($inserts || $deletes || $updates) {
                $diff[$table] = compact('inserts', 'deletes', 'updates');
            }
        }

        $this->logger->info("Comparison finished.");
        // Make sure you actually see the JSON on its own line:
        //echo json_encode($diff, JSON_PRETTY_PRINT) . PHP_EOL;
        $diff = $this->buildDiff($label, $onlyTables, $useHashing);

        if ($this->outputFile) {
            ob_start();
            $this->printDiffonly($diff);
            $out = ob_get_clean();
            if (! $this->useAnsiColors) {
                // strip ANSI escape codes
                $out = preg_replace('/\033\[[0-9;]*m/', '', $out);
            }
            file_put_contents($this->outputFile, $out);
        } else {
            $this->printDiffonly($diff);
        }

        //$this->outputConsole($diff);
        //$this->printDiffonly($diff);
    }

    // only print columns that changed

    /**
     * Pretty-print the $diff array to the console with colors and indentation,
     * and for updates only show the columns that changed.
     *
     * @param array $diff  The diff as built by your Comparator.
     */
    function printDiffonly(array $diff): void
    {
        // ANSI color codes
        $C_RESET = $this->useAnsiColors ? "\033[0m"     : '';
        $C_BOLD  = $this->useAnsiColors ? "\033[1m"     : '';
        $C_TABLE = $this->useAnsiColors ? "\033[1;34m" : '';
        $C_INS   = $this->useAnsiColors ? "\033[32m"   : '';
        $C_DEL   = $this->useAnsiColors ? "\033[31m"   : '';
        $C_UPD   = $this->useAnsiColors ? "\033[33m"   : '';

        // Helper to truncate long values
        $disableTrunc = $this->disableTruncate;
        $truncate = function(string $v, int $max = 40) use ($disableTrunc): string {
            if ($disableTrunc) {
                return is_scalar($v) ? (string)$v : json_encode($v);
            }
            return strlen($v) > $max
                ? substr($v, 0, $max - 3) . '…'
                : $v;
        };

        // Format a single column change: key=before → after
        $formatChange = function(string $col, $before, $after) use ($truncate) {
            $b = is_scalar($before) ? (string)$before : json_encode($before);
            $a = is_scalar($after)  ? (string)$after  : json_encode($after);
            return "{$col}: {$truncate($b)} → {$truncate($a)}";
        };

        if (empty($diff)) {
            echo "{$C_BOLD}No changes detected.{$C_RESET}\n";
            return;
        }

        foreach ($diff as $table => $chg) {
            // Table header
            echo "\n{$C_TABLE}► Table: {$table}{$C_RESET}\n";

            // Inserts
            if (!empty($chg['inserts'])) {
                echo "  {$C_INS}+ Inserts (".count($chg['inserts'])."){$C_RESET}\n";
                foreach ($chg['inserts'] as $id => $row) {
                    echo "    {$C_INS}→ [$id]{$C_RESET} ";
                    $pairs = [];
                    foreach ($row as $c => $v) {
                        $pairs[] = "{$c}=".$truncate(is_scalar($v)? (string)$v: json_encode($v));
                    }
                    echo implode(', ', $pairs) . "\n";
                }
            }

            // Deletes
            if (!empty($chg['deletes'])) {
                echo "  {$C_DEL}- Deletes (".count($chg['deletes'])."){$C_RESET}\n";
                foreach ($chg['deletes'] as $id => $row) {
                    echo "    {$C_DEL}→ [$id]{$C_RESET} ";
                    $pairs = [];
                    foreach ($row as $c => $v) {
                        $pairs[] = "{$c}=".$truncate(is_scalar($v)? (string)$v: json_encode($v));
                    }
                    echo implode(', ', $pairs) . "\n";
                }
            }

            // Updates (only changed columns)
            if (!empty($chg['updates'])) {
                echo "  {$C_UPD}* Updates (".count($chg['updates'])."){$C_RESET}\n";
                foreach ($chg['updates'] as $u) {
                    $id     = $u['key'];
                    $before = (array)$u['before'];
                    $after  = (array)$u['after'];

                    // Determine which columns changed
                    $colsChanged = [];
                    foreach ($before as $col => $valBefore) {
                        $valAfter = $after[$col] ?? null;
                        if ($valBefore !== $valAfter) {
                            $colsChanged[$col] = [$valBefore, $valAfter];
                        }
                    }

                    echo "    {$C_UPD}→ [{$id}]{$C_RESET}\n";
                    foreach ($colsChanged as $col => [$b, $a]) {
                        echo "       ".$formatChange($col, $b, $a)."\n";
                    }
                }
            }
        }

        echo "\n";
    }


    private function buildDiff(string $label, array $onlyTables, bool $useHashing): array
    {
        $beforeDir = "{$this->baseDir}/{$label}";
        $afterLabel = $label === 'before' ? 'after' : 'before';
        $afterDir  = "{$this->baseDir}/{$afterLabel}";

        $this->logger->info("Starting comparison ($label vs $afterLabel)");
        $files = glob("$beforeDir/*_{$label}.json.gz") ?: [];
        $this->logger->info("Found ".count($files)." files in $beforeDir");

        $diff = [];
        foreach ($files as $beforeFile) {
            $table = basename($beforeFile, "_{$label}.json.gz");
            if ($onlyTables && ! in_array($table, $onlyTables, true)) {
                continue;
            }
            $afterFile = "$afterDir/{$table}_{$afterLabel}.json.gz";
            if (! file_exists($afterFile)) {
                $this->logger->warning("Missing after-snapshot for $table");
                continue;
            }

            $bdata = json_decode(gzdecode(file_get_contents($beforeFile)), true)  ?? [];
            $adata = json_decode(gzdecode(file_get_contents($afterFile)),  true)  ?? [];
            $brows = $bdata['rows'] ?? [];
            $arows = $adata['rows'] ?? [];

            // diff logic (hashing or full compare)
            if ($useHashing) {
                $bhash = array_column($brows, 'hash', 'id');
                $ahash = array_column($arows, 'hash', 'id');
                $ins = array_diff_key($ahash, $bhash);
                $del = array_diff_key($bhash, $ahash);
                $upd = [];
                foreach ($bhash as $id => $h) {
                    if (isset($ahash[$id]) && $h !== $ahash[$id]) {
                        $upd[] = ['key'=>$id, 'before'=>$brows[$id]??null, 'after'=>$arows[$id]??null];
                    }
                }
            } else {
                $ins = array_diff_key($arows, $brows);
                $del = array_diff_key($brows, $arows);
                $upd = [];
                foreach ($brows as $id => $r) {
                    if (isset($arows[$id]) && $r !== $arows[$id]) {
                        $upd[] = ['key'=>$id, 'before'=>$r, 'after'=>$arows[$id]];
                    }
                }
            }

            if ($ins || $del || $upd) {
                $diff[$table] = ['inserts'=>$ins,'deletes'=>$del,'updates'=>$upd];
            }
        }

        $this->logger->info("Comparison build complete.");
        return $diff;
    }



}
