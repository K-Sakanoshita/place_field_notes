<?php

declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(array $config, bool $autoMigrate = true)
    {
        $host = (string)($config['host'] ?? '127.0.0.1');
        $port = (int)($config['port'] ?? 3306);
        $name = (string)($config['name'] ?? 'place_field_notes');
        $user = (string)($config['user'] ?? '');
        $password = (string)($config['password'] ?? '');
        if ($user === '') {
            throw new RuntimeException('Database user is not configured');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        if ($autoMigrate) {
            $this->migrate();
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function migrate(): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS projects (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(24) NOT NULL UNIQUE,
                edit_token_hash VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                activity_type VARCHAR(32) NOT NULL DEFAULT 'osm',
                bbox_json TEXT NOT NULL,
                start_at DATETIME NOT NULL,
                end_at DATETIME NOT NULL,
                timezone VARCHAR(64) NOT NULL,
                base_map VARCHAR(128) NOT NULL,
                diff_id CHAR(64) NULL,
                summary_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_projects_diff_id (diff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS diffs (
                diff_id CHAR(64) NOT NULL PRIMARY KEY,
                request_json TEXT NOT NULL,
                data_json LONGTEXT NOT NULL,
                project_id BIGINT UNSIGNED NULL,
                persistent TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                INDEX idx_diffs_expires (expires_at),
                INDEX idx_diffs_project (project_id),
                CONSTRAINT fk_diffs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS edit_sessions (
                session_hash CHAR(64) NOT NULL PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_edit_sessions_project (project_id),
                INDEX idx_edit_sessions_expires (expires_at),
                CONSTRAINT fk_edit_sessions_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS featured_objects (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                osm_type VARCHAR(16) NOT NULL,
                osm_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(255) NULL,
                wikipedia VARCHAR(1024) NULL,
                wikidata VARCHAR(128) NULL,
                wikimedia_commons VARCHAR(1024) NULL,
                include_in_results TINYINT(1) NOT NULL DEFAULT 0,
                comment TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                UNIQUE KEY uq_featured_osm (project_id, osm_type, osm_id),
                INDEX idx_featured_project (project_id, sort_order),
                CONSTRAINT fk_featured_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                body TEXT NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_entries_project (project_id, sort_order),
                CONSTRAINT fk_entries_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS place_results (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                lat DECIMAL(10,7) NULL,
                lon DECIMAL(10,7) NULL,
                comment TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_place_results_project (project_id, sort_order),
                CONSTRAINT fk_place_results_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS result_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                place_result_id BIGINT UNSIGNED NOT NULL,
                source_type VARCHAR(32) NOT NULL,
                source_key VARCHAR(512) NOT NULL,
                source_url VARCHAR(2048) NULL,
                result_type VARCHAR(32) NULL,
                metadata_json LONGTEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                INDEX idx_result_links_place (place_result_id, sort_order),
                CONSTRAINT fk_result_links_place FOREIGN KEY (place_result_id) REFERENCES place_results(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS photos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                entry_id BIGINT UNSIGNED NULL,
                place_result_id BIGINT UNSIGNED NULL,
                featured_object_id BIGINT UNSIGNED NULL,
                source_type VARCHAR(16) NOT NULL,
                filename VARCHAR(255) NULL,
                thumbnail_filename VARCHAR(255) NULL,
                commons_file VARCHAR(512) NULL,
                source_url VARCHAR(2048) NULL,
                caption TEXT NULL,
                creator VARCHAR(255) NULL,
                credit VARCHAR(512) NULL,
                lat DECIMAL(10,7) NULL,
                lon DECIMAL(10,7) NULL,
                license VARCHAR(32) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_photos_project (project_id, sort_order),
                CONSTRAINT fk_photos_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_photos_entry FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE SET NULL,
                CONSTRAINT fk_photos_place FOREIGN KEY (place_result_id) REFERENCES place_results(id) ON DELETE SET NULL,
                CONSTRAINT fk_photos_featured FOREIGN KEY (featured_object_id) REFERENCES featured_objects(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
        foreach ($statements as $sql) {
            $this->pdo->exec($sql);
        }

        // Older development schemas used CHAR(64), which is too short for future
        // PASSWORD_DEFAULT formats. Only alter when an old schema is detected.
        $stmt = $this->pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'edit_token_hash'");
        $length = (int)$stmt->fetchColumn();
        if ($length > 0 && $length < 255) {
            $this->pdo->exec('ALTER TABLE projects MODIFY edit_token_hash VARCHAR(255) NOT NULL');
        }
    }
}
