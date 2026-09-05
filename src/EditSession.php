<?php

declare(strict_types=1);

final class EditSession
{
    public const COOKIE_NAME = 'pfn_edit_session';

    public function __construct(private PDO $pdo, private int $ttlSeconds = 604800)
    {
    }

    public function establish(string $publicId, string $editToken): void
    {
        $stmt = $this->pdo->prepare('SELECT id, edit_token_hash FROM projects WHERE public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);
        $project = $stmt->fetch();
        if (!$project || !verifyEditToken($editToken, (string)$project['edit_token_hash'])) {
            throw new RuntimeException('Invalid edit token');
        }

        $rawToken = generateSessionToken();
        $hash = hashSessionToken($rawToken);
        $created = utcNow();
        $expires = (new DateTimeImmutable($created, new DateTimeZone('UTC')))
            ->modify('+' . $this->ttlSeconds . ' seconds')->format('Y-m-d H:i:s');
        $this->pdo->prepare('DELETE FROM edit_sessions WHERE expires_at <= UTC_TIMESTAMP()')->execute();
        $stmt = $this->pdo->prepare(
            'INSERT INTO edit_sessions (session_hash, project_id, expires_at, created_at) VALUES (:hash, :project_id, :expires, :created)'
        );
        $stmt->execute([
            'hash' => $hash,
            'project_id' => (int)$project['id'],
            'expires' => $expires,
            'created' => $created,
        ]);
        setcookie(self::COOKIE_NAME, $rawToken, cookieOptions($this->ttlSeconds));
    }

    public function requireProject(string $publicId): array
    {
        $rawToken = (string)($_COOKIE[self::COOKIE_NAME] ?? '');
        if ($rawToken === '') {
            throw new RuntimeException('Editor session required');
        }
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.public_id FROM edit_sessions s JOIN projects p ON p.id = s.project_id
             WHERE s.session_hash = :hash AND s.expires_at > UTC_TIMESTAMP() AND p.public_id = :public_id'
        );
        $stmt->execute(['hash' => hashSessionToken($rawToken), 'public_id' => $publicId]);
        $project = $stmt->fetch();
        if (!$project) {
            throw new RuntimeException('Editor session expired or invalid');
        }
        return $project;
    }

    public function clear(): void
    {
        $rawToken = (string)($_COOKIE[self::COOKIE_NAME] ?? '');
        if ($rawToken !== '') {
            $stmt = $this->pdo->prepare('DELETE FROM edit_sessions WHERE session_hash = :hash');
            $stmt->execute(['hash' => hashSessionToken($rawToken)]);
        }
        setcookie(self::COOKIE_NAME, '', cookieOptions(-3600));
    }
}
