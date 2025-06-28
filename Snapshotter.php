<?php

class Snapshotter {
    private $pdo;
    private $logger;
    private $snapshotDir = 'snapshots';
    private string $baseDir;

    public function __construct(string $baseSnapshotDir, Logger $logger) {
        // Set up database connection here (example using PDO)
        $config = include 'db_config.php';
        $this->pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']}", $config['user'], $config['password']);
        $this->logger = $logger;
        $this->baseDir = rtrim($baseSnapshotDir, '/');

        // ensure base dir exists
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0777, true);
            $this->logger->info("Created base snapshot directory: {$this->baseDir}");
        }

    }

    /**
     * Completely wipe out the base snapshot directory (all labels).
     */
    public function clearBaseDir(): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            $path = $file->getRealPath();

            if ($file->isFile()) {
                // Skip .gitignore files
                if ($file->getFilename() === '.gitignore') {
                    continue;
                }
                unlink($path);
            } elseif ($file->isDir()) {
                // Only remove the directory if it's now empty
                $inner = new FilesystemIterator($path);
                if (!$inner->valid()) {
                    rmdir($path);
                }
            }
        }

        $this->logger->info("Cleared base snapshot directory: {$this->baseDir}");
    }


    public function takeSnapshot($label, array $onlyTables = [], $useCursor = false, $useHashing = false) {
        $this->logger->log("info", "Starting snapshot: $label");

        // make the label directory
        $dir = "{$this->baseDir}/{$label}";

        // Clear any existing snapshots in this label folder
        if (is_dir($dir)) {
            $this->logger->info("Clearing old '$label' snapshots in {$dir}");
            $this->clearDirectory($dir);
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            $this->logger->info(" Created snapshot subdir: $dir");
        }

        // pick tables
        $tables = $onlyTables
            ? $onlyTables
            : $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $this->logger->info("  → snapshot table: $table");

            // fetch rows
            $rows = $useCursor
                ? $this->streamTableWithCursor($table, $useHashing)
                : $this->streamTable($table, $useHashing);

            // encode & compress
            $payload = gzencode(json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE), 9);

            // write to: snapshots/{label}/{table}_{label}.json.gz
            $file = "{$dir}/{$table}_{$label}.json.gz";
            file_put_contents($file, $payload);

            $this->logger->info("    ✔ wrote $file");
        }

        $this->logger->info("Completed '$label' snapshot.");
    }

    /**
     * Recursively delete all files & subdirs under $path, but leave $path itself.
     */
    private function clearDirectory(string $path): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $item) {
            $realPath = $item->getRealPath();

            if ($item->isFile()) {
                // 1) Skip .gitignore files
                if ($item->getFilename() === '.gitignore') {
                    continue;
                }
                unlink($realPath);
            } elseif ($item->isDir()) {
                // 2) Only rmdir if the directory is now completely empty
                $inner = new FilesystemIterator($realPath);
                if (!$inner->valid()) {
                    rmdir($realPath);
                }
            }
        }
    }


    private function streamTableWithCursor($table, $useHashing) {
        $rows = [];
        $lastId = 0;  // Start from the first row (or last processed row)

        while (true) {
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE id > :last_id ORDER BY id ASC LIMIT 1000");
            $stmt->execute([':last_id' => $lastId]);
            $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $row) {
                // If using hashing, hash the row
                if ($useHashing) {
                    $rowHash = md5(json_encode($row));  // Hash the row data (using MD5 for example)
                    $rows[] = ['hash' => $rowHash, 'id' => $row['id']];
                } else {
                    $rows[] = $row;
                }
            }

            // Update last processed id for pagination
            $lastId = end($batch)['id'];
        }

        return $rows;
    }

    private function streamTable($table, $useHashing) {
        $stmt = $this->pdo->prepare("SELECT * FROM $table");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($useHashing) {
            return array_map(function($row) {
                return ['hash' => md5(json_encode($row)), 'id' => $row['id']];
            }, $rows);
        }

        return $rows;
    }
}