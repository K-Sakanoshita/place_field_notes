<?php
/**
 * Handles OSM diff preview and attaching diff to a project.
 */

declare(strict_types=1);

class OsmDiffController
{
    private $db;
    private string $overpassHistoryEndpoint;
    private int $diffCacheTtl; // seconds

    public function __construct($db, string $overpassHistoryEndpoint, int $diffCacheTtl = 3600)
    {
        $this->db = $pdo;
        $this->overpassHistoryEndpoint = rtrim($overpassHistoryEndpoint, '/');
        $this->diffCacheTtl = $diffCacheTtl;
    }

    public function preview(array $data): array
    {
        // Validate and normalize input
        $bbox = $data['bbox'];
        if (!is_array($bbox) || count($bbox) !== 4) {
            throw new InvalidArgumentException('bbox must be an array of 4 floats');
        }
        $startAtUtc = toUtc($data['start_at'], $data['timezone']);
        $endAtUtc = toUtc($data['end_at'], $data['timezone']);
        $key = md5(json_encode([$bbox, $startAtUtc, $endAtUtc]));

        // Check cache
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare('SELECT * FROM diffs WHERE diff_id = :id AND created_at > datetime("now", "-'.(int)$this->diffCacheTtl.' seconds")');
            $stmt->execute([':id' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM diffs WHERE diff_id = :id AND created_at > datetime("now", "-'.(int)$this->diffCacheTtl.' seconds")');
            $stmt->bindValue(':id', $key, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
        }
        if ($row) {
            return [
                'diff_id' => $key,
                'preview_url' => null, // front-end can use diff_id to fetch
            ];
        }

        // Build Overpass adiff query
        $query = "[out:json];(node(adiff($startAtUtc,$endAtUtc)[$bbox]);way(adiff($startAtUtc,$endAtUtc)[$bbox]);relation(adiff($startAtUtc,$endAtUtc)[$bbox]););out meta;";
        $response = $this->callOverpass($query);
        if ($response === null) {
            throw new RuntimeException('Overpass adiff failed');
        }
        // Store in cache
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare('INSERT INTO diffs (diff_id, data, created_at, ttl) VALUES (:id, :data, :created_at, :ttl)');
            $stmt->execute([
                ':id' => $key,
                ':data' => $response,
                ':created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                ':ttl' => $this->diffCacheTtl,
            ]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO diffs (diff_id, data, created_at, ttl) VALUES (:id, :data, :created_at, :ttl)');
            $stmt->bindValue(':id', $key, SQLITE3_TEXT);
            $stmt->bindValue(':data', $response, SQLITE3_TEXT);
            $stmt->bindValue(':created_at', (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), SQLITE3_TEXT);
            $stmt->bindValue(':ttl', $this->diffCacheTtl, SQLITE3_INTEGER);
            $stmt->execute();
        }
        return [
            'diff_id' => $key,
            'preview_url' => null,
        ];
    }

    private function callOverpass(string $query): ?string
    {
        $postData = http_build_query(['data' => $query]);
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n".
                            "Content-Length: " . strlen($postData) . "\r\n",
                'content' => $postData,
                'timeout' => 30,
            ],
        ];
        $context = stream_context_create($opts);
        $result = @file_get_contents($this->overpassHistoryEndpoint, false, $context);
        if ($result === false) {
            return null;
        }
        return $result;
    }

    public function attachDiffToProject(string $publicId, string $diffId): void
    {
        // Validate diff exists and not expired
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare('SELECT * FROM diffs WHERE diff_id = :id AND created_at > datetime("now", "-'.(int)$this->diffCacheTtl.' seconds")');
            $stmt->execute([':id' => $diffId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM diffs WHERE diff_id = :id AND created_at > datetime("now", "-'.(int)$this->diffCacheTtl.' seconds")');
            $stmt->bindValue(':id', $diffId, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
        }
        if (!$row) {
            throw new RuntimeException('Diff not found or expired');
        }
        // Attach to project
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare('UPDATE projects SET changes_file = :changes_file, updated_at = :updated_at WHERE public_id = :public_id');
            $stmt->execute([
                ':changes_file' => $diffId,
                ':updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                ':public_id' => $publicId,
            ]);
        } else {
            $stmt = $this->pdo->prepare('UPDATE projects SET changes_file = :changes_file, updated_at = :updated_at WHERE public_id = :public_id');
            $stmt->bindValue(':changes_file', $diffId, SQLITE3_TEXT);
            $stmt->bindValue(':updated_at', (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), SQLITE3_TEXT);
            $stmt->bindValue(':public_id', $publicId, SQLITE3_TEXT);
            $stmt->execute();
        }
    }
}
