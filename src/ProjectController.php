<?php
/**
 * Handles project creation and retrieval.
 */

declare(strict_types=1);

class ProjectController
{
    // Accept Database instance
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function create(array $data): array
    {
        $editToken = generateEditToken();
        $publicId = generatePublicId();
        $hash = hashEditToken($editToken);

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        // Accept bbox as JSON string for backward compatibility
        $bbox = $data['bbox'];
        if (is_string($bbox)) {
            $bbox = json_decode($bbox, true);
        }
        $stmt = $this->db->prepare(
            "INSERT INTO projects (public_id, edit_token_hash, title, description, bbox, start_at, end_at, timezone, base_map, created_at, updated_at) VALUES (:public_id, :hash, :title, :description, :bbox, :start_at, :end_at, :timezone, :base_map, :created_at, :updated_at)"
        );
        $stmt->execute([
            ':public_id' => $publicId,
            ':hash' => $hash,
            ':title' => $data['title'],
            ':description' => $data['description'] ?? null,
            ':bbox' => json_encode($bbox),
            ':start_at' => $data['start_at'],
            ':end_at' => $data['end_at'],
            ':timezone' => $data['timezone'],
            ':base_map' => $data['base_map'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $id = $this->db->lastInsertId();
        $project = $this->getById($id);
        $project['edit_token'] = $editToken;
        return $project;
    }

    public function getByPublicId(string $publicId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM projects WHERE public_id = :public_id');
        $stmt->execute([':public_id' => $publicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['bbox'] = json_decode($row['bbox'], true);
        return $row;
    }

    public function getById(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM projects WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException("Project not found");
        }
        $row['bbox'] = json_decode($row['bbox'], true);
        return $row;
    }
}
