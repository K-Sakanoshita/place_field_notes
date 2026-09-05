<?php
/**
 * Simple SQLite wrapper using command line sqlite3.
 */

declare(strict_types=1);

class Database
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        // Ensure database file exists and tables are created
        $this->initialize();
    }

    private function initialize(): void
    {
        $createProjects =
            "CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                public_id TEXT UNIQUE NOT NULL,
                edit_token_hash TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                bbox TEXT NOT NULL,
                start_at TEXT NOT NULL,
                end_at TEXT NOT NULL,
                timezone TEXT NOT NULL,
                base_map TEXT NOT NULL,
                changes_file TEXT,
                summary_json TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );";
        $createDiffs =
            "CREATE TABLE IF NOT EXISTS diffs (
                diff_id TEXT PRIMARY KEY,
                data TEXT NOT NULL,
                created_at TEXT NOT NULL,
                ttl INTEGER NOT NULL
            );";
        $this->executeRaw($createProjects);
        $this->executeRaw($createDiffs);
    }

    public function prepare(string $sql): SimpleStatement
    {
        return new SimpleStatement($this->path, $sql);
    }

    public function lastInsertId(): int
    {
        $output = shell_exec("sqlite3 {$this->path} 'SELECT last_insert_rowid()' 2>&1");
        return (int)trim($output);
    }

    private function executeRaw(string $sql): void
    {
        $cmd = "sqlite3 {$this->path} \"$sql\" 2>&1";
        shell_exec($cmd);
    }
}

class SimpleStatement
{
    private string $path;
    private string $sql;
    private array $rows = [];
    private int $index = 0;
    private bool $executed = false;

    public function __construct(string $path, string $sql)
    {
        $this->path = $path;
        $this->sql = $sql;
    }

    public function execute(array $params = []): bool
    {
        $sql = $this->sql;
        foreach ($params as $key => $value) {
            $placeholder = ':' . $key;
            $replacement = $this->quote($value);
            $sql = str_replace($placeholder, $replacement, $sql);
        }
        // Determine if query is SELECT
        if (stripos(ltrim($sql), 'SELECT') === 0) {
            // Execute SELECT query and capture output.
            $cmd = "sqlite3 " . escapeshellarg($this->path) . " -separator '|' " . escapeshellarg($sql) . " 2>&1";
            exec($cmd, $outputLines, $returnVar);
            $output = implode("\n", $outputLines);

            // Parse the output into rows.
            $lines = preg_split('/\R/', trim($output));
            $this->rows = [];
            if ($lines[0] !== null && $lines[0] !== '') {
                // Get column names via PRAGMA.
                $table = $this->extractTable($sql);
                $infoCmd = "sqlite3 {$this->path} \"PRAGMA table_info(\"$table\")\" 2>&1";
                $infoOutput = shell_exec($infoCmd);
                $cols = [];
                foreach (preg_split('/\R/', trim($infoOutput)) as $infoLine) {
                    $infoParts = preg_split('/\|/', $infoLine);
                    $cols[] = $infoParts[1] ?? null;
                }
                foreach ($lines as $line) {
                    if ($line === '') continue;
                    $parts = explode('|', $line);
                    $row = [];
                    foreach ($cols as $i => $col) {
                        $row[$col] = $parts[$i] ?? null;
                    }
                    $this->rows[] = $row;
                }
            }
        } else {
            // Non-SELECT query.
            $cmd = "sqlite3 " . escapeshellarg($this->path) . " " . escapeshellarg($sql) . " 2>&1";
            exec($cmd, $outputLines, $returnVar);
            $output = implode("\n", $outputLines);
        }
        $this->executed = true;
        return true;
    }

    private function quote($value): string
    {
        if (is_numeric($value)) {
            return (string)$value;
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function extractTable(string $sql): string
    {
        if (preg_match('/FROM\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $sql, $m) || preg_match('/UPDATE\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $sql, $m)) {
            return $m[1];
        }
        return '';
    }

    public function fetch($mode = null): ?array
    {
        if (!$this->executed || $this->index >= count($this->rows)) {
            // No more rows; return null to satisfy ?array return type.
            return null;
        }
        return $this->rows[$this->index++];
    }
}
?>
