<?php

declare(strict_types=1);

final class ProjectController
{
    public function __construct(
        private Database $database,
        private OsmDiffController $diffs
    ) {
    }

    public function create(array $data): array
    {
        $title = requireString($data, 'title', 255);
        $description = optionalString($data, 'description', 20000);
        $activityType = $this->activityType((string)($data['activity_type'] ?? 'osm'));
        $bbox = normalizeBbox($data['bbox'] ?? null);
        $timezone = requireString($data, 'timezone', 64);
        $startAtInput = requireString($data, 'start_at', 64);
        $endAtInput = requireString($data, 'end_at', 64);
        $startUtc = toUtc($startAtInput, $timezone);
        $endUtc = toUtc($endAtInput, $timezone);
        validateActivityRange($startUtc, $endUtc);
        $baseMap = requireString($data, 'base_map', 128);
        $diffId = optionalString($data, 'diff_id', 64);

        if (in_array($activityType, ['osm', 'mixed'], true) && $diffId === null) {
            throw new InvalidArgumentException('diff_id is required for OSM activities');
        }

        $diffPayload = null;
        if ($diffId !== null) {
            $diffPayload = $this->diffs->assertMatches($diffId, [
                'bbox' => $bbox,
                'start_at' => $startAtInput,
                'end_at' => $endAtInput,
                'timezone' => $timezone,
            ]);
        }

        $editToken = generateEditToken();
        $editHash = hashEditToken($editToken);
        $now = utcNow();
        $publicId = '';
        $projectId = 0;

        $this->database->transaction(function (PDO $pdo) use (
            $title,
            $description,
            $activityType,
            $bbox,
            $startUtc,
            $endUtc,
            $timezone,
            $baseMap,
            $diffId,
            $diffPayload,
            $editHash,
            $now,
            $data,
            &$publicId,
            &$projectId
        ): void {
            $summary = is_array($diffPayload) ? ($diffPayload['summary'] ?? null) : null;

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $publicId = generatePublicId();
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO projects (
                            public_id, edit_token_hash, title, description, activity_type, bbox_json,
                            start_at, end_at, timezone, base_map, diff_id, summary_json, created_at, updated_at
                         ) VALUES (
                            :public_id, :edit_hash, :title, :description, :activity_type, :bbox_json,
                            :start_at, :end_at, :timezone, :base_map, :diff_id, :summary_json, :created_at, :updated_at
                         )'
                    );
                    $stmt->execute([
                        'public_id' => $publicId,
                        'edit_hash' => $editHash,
                        'title' => $title,
                        'description' => $description,
                        'activity_type' => $activityType,
                        'bbox_json' => json_encode($bbox, JSON_THROW_ON_ERROR),
                        'start_at' => $startUtc,
                        'end_at' => $endUtc,
                        'timezone' => $timezone,
                        'base_map' => $baseMap,
                        'diff_id' => $diffId,
                        'summary_json' => $summary === null
                            ? null
                            : json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $projectId = (int)$pdo->lastInsertId();
                    break;
                } catch (PDOException $e) {
                    if ((string)$e->getCode() !== '23000' || $attempt === 4) {
                        throw $e;
                    }
                }
            }

            if ($projectId <= 0) {
                throw new RuntimeException('Could not create project');
            }

            if ($diffId !== null) {
                $this->diffs->persist($diffId, $projectId);
                $this->insertDiffCandidates(
                    $pdo,
                    $projectId,
                    (array)($diffPayload['candidates'] ?? []),
                    (array)($data['featured_objects'] ?? [])
                );
            }

            $this->syncEntries($pdo, $projectId, (array)($data['entries'] ?? []), true);
            $this->syncPlaces($pdo, $projectId, (array)($data['place_results'] ?? []), true);
        });

        return [
            'public_id' => $publicId,
            'public_url' => baseUrl() . '/view/' . rawurlencode($publicId),
            'edit_url' => baseUrl() . '/edit/' . rawurlencode($publicId) . '?token=' . rawurlencode($editToken),
        ];
    }

    public function get(string $publicId, bool $editor = false): ?array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT id, public_id, title, description, activity_type, bbox_json, start_at, end_at,
                    timezone, base_map, diff_id, summary_json, created_at, updated_at
             FROM projects WHERE public_id = :public_id'
        );
        $stmt->execute(['public_id' => $publicId]);
        $project = $stmt->fetch();
        if (!$project) {
            return null;
        }

        $projectId = (int)$project['id'];
        $timezone = (string)$project['timezone'];
        $result = [
            'public_id' => (string)$project['public_id'],
            'title' => (string)$project['title'],
            'description' => $project['description'],
            'activity_type' => (string)$project['activity_type'],
            'bbox' => safeJsonDecode((string)$project['bbox_json'], []),
            'start_at' => utcIso((string)$project['start_at']),
            'end_at' => utcIso((string)$project['end_at']),
            'start_at_local' => fromUtcForTimezone((string)$project['start_at'], $timezone),
            'end_at_local' => fromUtcForTimezone((string)$project['end_at'], $timezone),
            'timezone' => $timezone,
            'base_map' => (string)$project['base_map'],
            'summary' => safeJsonDecode($project['summary_json'], null),
            'created_at' => utcIso((string)$project['created_at']),
            'updated_at' => utcIso((string)$project['updated_at']),
        ];

        $diffId = $project['diff_id'];
        $diff = is_string($diffId) && $diffId !== '' ? $this->diffs->getSaved($diffId) : null;
        $result['geojson'] = $diff['geojson'] ?? ['type' => 'FeatureCollection', 'features' => []];
        $result['featured_objects'] = $this->featuredObjects($projectId, $editor);
        $result['entries'] = $this->entries($projectId);
        $result['place_results'] = $this->places($projectId);
        $result['photos'] = $this->photos($projectId, $publicId);
        $result['result_summary'] = $this->resultSummary($result['place_results']);

        return $result;
    }

    public function update(int $projectId, array $data): void
    {
        $title = requireString($data, 'title', 255);
        $description = optionalString($data, 'description', 20000);

        $stmt = $this->database->pdo()->prepare('SELECT activity_type FROM projects WHERE id = :id');
        $stmt->execute(['id' => $projectId]);
        $storedActivityType = $stmt->fetchColumn();
        if (!is_string($storedActivityType)) {
            throw new RuntimeException('Project not found');
        }
        $requestedActivityType = $this->activityType((string)($data['activity_type'] ?? $storedActivityType));
        if ($requestedActivityType !== $storedActivityType) {
            throw new InvalidArgumentException('activity_type cannot be changed after project creation');
        }

        $now = utcNow();
        $this->database->transaction(function (PDO $pdo) use (
            $projectId,
            $title,
            $description,
            $storedActivityType,
            $now,
            $data
        ): void {
            $stmt = $pdo->prepare(
                'UPDATE projects
                 SET title = :title, description = :description, activity_type = :activity_type, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'activity_type' => $storedActivityType,
                'updated_at' => $now,
                'id' => $projectId,
            ]);

            $this->syncFeatured($pdo, $projectId, (array)($data['featured_objects'] ?? []));
            $this->syncEntries($pdo, $projectId, (array)($data['entries'] ?? []), false);
            $this->syncPlaces($pdo, $projectId, (array)($data['place_results'] ?? []), false);
        });
    }

    private function activityType(string $value): string
    {
        $value = strtolower(trim($value));
        $aliases = [
            'wikipedia_town' => 'wikipedia',
            'osm_wikipedia' => 'mixed',
            'other_mixed' => 'other',
        ];
        $value = $aliases[$value] ?? $value;
        if (!in_array($value, ['osm', 'wikipedia', 'mixed', 'other'], true)) {
            throw new InvalidArgumentException('Unsupported activity_type');
        }
        return $value;
    }

    private function insertDiffCandidates(PDO $pdo, int $projectId, array $candidates, array $selected): void
    {
        $selection = [];
        foreach ($selected as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string)($item['id'] ?? (($item['osm_type'] ?? '') . '/' . ($item['osm_id'] ?? '')));
            if ($key !== '/') {
                $selection[$key] = $item;
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO featured_objects (
                project_id, osm_type, osm_id, name, wikipedia, wikidata, wikimedia_commons,
                include_in_results, comment, sort_order
             ) VALUES (
                :project_id, :osm_type, :osm_id, :name, :wikipedia, :wikidata, :commons,
                :include_result, :comment, :sort_order
             )'
        );

        foreach ($candidates as $index => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $osmType = (string)($candidate['osm_type'] ?? '');
            $osmId = (int)($candidate['osm_id'] ?? 0);
            if (!in_array($osmType, ['node', 'way', 'relation'], true) || $osmId <= 0) {
                continue;
            }
            $key = (string)($candidate['id'] ?? ($osmType . '/' . $osmId));
            $choice = $selection[$key] ?? [];
            $stmt->execute([
                'project_id' => $projectId,
                'osm_type' => $osmType,
                'osm_id' => $osmId,
                'name' => mb_substr((string)($candidate['name'] ?? $key), 0, 255),
                'wikipedia' => $candidate['wikipedia'] ?? null,
                'wikidata' => $candidate['wikidata'] ?? null,
                'commons' => $candidate['commons'] ?? null,
                'include_result' => !empty($choice['include_in_results']) ? 1 : 0,
                'comment' => isset($choice['comment'])
                    ? mb_substr((string)$choice['comment'], 0, 10000)
                    : null,
                'sort_order' => isset($choice['sort_order']) ? (int)$choice['sort_order'] : $index,
            ]);
        }
    }

    private function syncFeatured(PDO $pdo, int $projectId, array $items): void
    {
        $stmt = $pdo->prepare('SELECT id FROM featured_objects WHERE project_id = :project_id');
        $stmt->execute(['project_id' => $projectId]);
        $allowed = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

        $update = $pdo->prepare(
            'UPDATE featured_objects
             SET include_in_results = :include_result, comment = :comment, sort_order = :sort_order
             WHERE id = :id AND project_id = :project_id'
        );
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0 || !isset($allowed[$id])) {
                continue;
            }
            $update->execute([
                'include_result' => !empty($item['include_in_results']) ? 1 : 0,
                'comment' => optionalString($item, 'comment', 10000),
                'sort_order' => (int)($item['sort_order'] ?? $index),
                'id' => $id,
                'project_id' => $projectId,
            ]);
        }
    }

    private function syncEntries(PDO $pdo, int $projectId, array $items, bool $creating): void
    {
        $existing = [];
        if (!$creating) {
            $stmt = $pdo->prepare('SELECT id FROM entries WHERE project_id = :project_id');
            $stmt->execute(['project_id' => $projectId]);
            $existing = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        }

        $kept = [];
        $insert = $pdo->prepare(
            'INSERT INTO entries (project_id, body, sort_order, created_at, updated_at)
             VALUES (:project_id, :body, :sort_order, :created_at, :updated_at)'
        );
        $update = $pdo->prepare(
            'UPDATE entries
             SET body = :body, sort_order = :sort_order, updated_at = :updated_at
             WHERE id = :id AND project_id = :project_id'
        );

        foreach ($items as $index => $item) {
            if (is_string($item)) {
                $item = ['body' => $item];
            }
            if (!is_array($item)) {
                continue;
            }
            $body = trim((string)($item['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            if (mb_strlen($body) > 20000) {
                throw new InvalidArgumentException('Entry body is too long');
            }
            $now = utcNow();
            $id = (int)($item['id'] ?? 0);
            if ($id > 0 && isset($existing[$id])) {
                $update->execute([
                    'body' => $body,
                    'sort_order' => $index,
                    'updated_at' => $now,
                    'id' => $id,
                    'project_id' => $projectId,
                ]);
                $kept[$id] = true;
            } else {
                $insert->execute([
                    'project_id' => $projectId,
                    'body' => $body,
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $kept[(int)$pdo->lastInsertId()] = true;
            }
        }

        if (!$creating) {
            $delete = $pdo->prepare('DELETE FROM entries WHERE id = :id AND project_id = :project_id');
            foreach (array_keys($existing) as $id) {
                if (!isset($kept[$id])) {
                    $delete->execute(['id' => $id, 'project_id' => $projectId]);
                }
            }
        }
    }

    private function syncPlaces(PDO $pdo, int $projectId, array $items, bool $creating): void
    {
        $existing = [];
        if (!$creating) {
            $stmt = $pdo->prepare('SELECT id FROM place_results WHERE project_id = :project_id');
            $stmt->execute(['project_id' => $projectId]);
            $existing = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        }

        $kept = [];
        $insert = $pdo->prepare(
            'INSERT INTO place_results (
                project_id, title, lat, lon, comment, sort_order, created_at, updated_at
             ) VALUES (
                :project_id, :title, :lat, :lon, :comment, :sort_order, :created_at, :updated_at
             )'
        );
        $update = $pdo->prepare(
            'UPDATE place_results
             SET title = :title, lat = :lat, lon = :lon, comment = :comment,
                 sort_order = :sort_order, updated_at = :updated_at
             WHERE id = :id AND project_id = :project_id'
        );

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string)($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            if (mb_strlen($title) > 255) {
                throw new InvalidArgumentException('Place title is too long');
            }
            $lat = optionalFloat($item, 'lat', -90, 90);
            $lon = optionalFloat($item, 'lon', -180, 180);
            $comment = optionalString($item, 'comment', 10000);
            $now = utcNow();
            $id = (int)($item['id'] ?? 0);

            if ($id > 0 && isset($existing[$id])) {
                $update->execute([
                    'title' => $title,
                    'lat' => $lat,
                    'lon' => $lon,
                    'comment' => $comment,
                    'sort_order' => $index,
                    'updated_at' => $now,
                    'id' => $id,
                    'project_id' => $projectId,
                ]);
            } else {
                $insert->execute([
                    'project_id' => $projectId,
                    'title' => $title,
                    'lat' => $lat,
                    'lon' => $lon,
                    'comment' => $comment,
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            $kept[$id] = true;
            $this->replaceResultLinks($pdo, $id, (array)($item['links'] ?? []));
        }

        if (!$creating) {
            $delete = $pdo->prepare('DELETE FROM place_results WHERE id = :id AND project_id = :project_id');
            foreach (array_keys($existing) as $id) {
                if (!isset($kept[$id])) {
                    $delete->execute(['id' => $id, 'project_id' => $projectId]);
                }
            }
        }
    }

    private function replaceResultLinks(PDO $pdo, int $placeId, array $links): void
    {
        $pdo->prepare('DELETE FROM result_links WHERE place_result_id = :place_id')
            ->execute(['place_id' => $placeId]);

        $insert = $pdo->prepare(
            'INSERT INTO result_links (
                place_result_id, source_type, source_key, source_url, result_type, metadata_json, sort_order
             ) VALUES (
                :place_id, :source_type, :source_key, :source_url, :result_type, :metadata_json, :sort_order
             )'
        );

        foreach ($links as $index => $link) {
            if (!is_array($link)) {
                continue;
            }
            $sourceType = strtolower(trim((string)($link['source_type'] ?? '')));
            if (!in_array($sourceType, ['wikipedia', 'wikidata', 'commons', 'osm', 'url'], true)) {
                continue;
            }
            $sourceKey = trim((string)($link['source_key'] ?? ''));
            if ($sourceKey === '' || mb_strlen($sourceKey) > 512) {
                continue;
            }
            $sourceUrl = validatedHttpUrl(isset($link['source_url']) ? (string)$link['source_url'] : null);
            $resultType = optionalString($link, 'result_type', 32);
            $metadata = isset($link['metadata']) && is_array($link['metadata']) ? $link['metadata'] : null;
            $insert->execute([
                'place_id' => $placeId,
                'source_type' => $sourceType,
                'source_key' => $sourceKey,
                'source_url' => $sourceUrl,
                'result_type' => $resultType,
                'metadata_json' => $metadata === null
                    ? null
                    : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'sort_order' => (int)($link['sort_order'] ?? $index),
            ]);
        }
    }

    private function featuredObjects(int $projectId, bool $editor): array
    {
        $sql = 'SELECT id, osm_type, osm_id, name, wikipedia, wikidata, wikimedia_commons,
                       include_in_results, comment, sort_order
                FROM featured_objects WHERE project_id = :project_id';
        if (!$editor) {
            $sql .= ' AND include_in_results = 1';
        }
        $sql .= ' ORDER BY sort_order, id';
        $stmt = $this->database->pdo()->prepare($sql);
        $stmt->execute(['project_id' => $projectId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['osm_id'] = (int)$row['osm_id'];
            $row['include_in_results'] = (bool)$row['include_in_results'];
            $row['sort_order'] = (int)$row['sort_order'];
        }
        unset($row);
        return $rows;
    }

    private function entries(int $projectId): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT id, body, sort_order, created_at, updated_at
             FROM entries WHERE project_id = :project_id ORDER BY sort_order, id'
        );
        $stmt->execute(['project_id' => $projectId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['sort_order'] = (int)$row['sort_order'];
            $row['created_at'] = utcIso((string)$row['created_at']);
            $row['updated_at'] = utcIso((string)$row['updated_at']);
        }
        unset($row);
        return $rows;
    }

    private function places(int $projectId): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT id, title, lat, lon, comment, sort_order
             FROM place_results WHERE project_id = :project_id ORDER BY sort_order, id'
        );
        $stmt->execute(['project_id' => $projectId]);
        $places = $stmt->fetchAll();

        $linkStmt = $this->database->pdo()->prepare(
            'SELECT id, source_type, source_key, source_url, result_type, metadata_json, sort_order
             FROM result_links WHERE place_result_id = :place_id ORDER BY sort_order, id'
        );
        foreach ($places as &$place) {
            $place['id'] = (int)$place['id'];
            $place['lat'] = $place['lat'] === null ? null : (float)$place['lat'];
            $place['lon'] = $place['lon'] === null ? null : (float)$place['lon'];
            $place['sort_order'] = (int)$place['sort_order'];
            $linkStmt->execute(['place_id' => $place['id']]);
            $links = $linkStmt->fetchAll();
            foreach ($links as &$link) {
                $link['id'] = (int)$link['id'];
                $link['sort_order'] = (int)$link['sort_order'];
                $link['metadata'] = safeJsonDecode($link['metadata_json'], null);
                unset($link['metadata_json']);
            }
            unset($link);
            $place['links'] = $links;
        }
        unset($place);
        return $places;
    }

    private function photos(int $projectId, string $publicId): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT id, entry_id, place_result_id, featured_object_id, source_type,
                    commons_file, source_url, caption, creator, credit, lat, lon, license, sort_order
             FROM photos WHERE project_id = :project_id ORDER BY sort_order, id'
        );
        $stmt->execute(['project_id' => $projectId]);
        $photos = $stmt->fetchAll();

        foreach ($photos as &$photo) {
            $photo['id'] = (int)$photo['id'];
            foreach (['entry_id', 'place_result_id', 'featured_object_id'] as $key) {
                $photo[$key] = $photo[$key] === null ? null : (int)$photo[$key];
            }
            $photo['lat'] = $photo['lat'] === null ? null : (float)$photo['lat'];
            $photo['lon'] = $photo['lon'] === null ? null : (float)$photo['lon'];
            $photo['sort_order'] = (int)$photo['sort_order'];

            if ($photo['source_type'] === 'upload') {
                $photo['image_url'] = '/media/' . rawurlencode($publicId) . '/' . $photo['id'] . '/image';
                $photo['thumbnail_url'] = '/media/' . rawurlencode($publicId) . '/' . $photo['id'] . '/thumb';
            } elseif ($photo['source_type'] === 'commons' && is_string($photo['commons_file'])) {
                $photo['source_url'] = commonsPageUrl($photo['commons_file']);
                $photo['image_url'] = commonsImageRedirectUrl($photo['commons_file']);
                $photo['thumbnail_url'] = $photo['image_url'] . '?width=600';
            }
        }
        unset($photo);
        return $photos;
    }

    private function resultSummary(array $places): array
    {
        $summary = [
            'wikipedia' => ['new' => 0, 'expanded' => 0, 'other' => 0],
            'wikidata' => 0,
            'commons' => ['files' => 0, 'categories' => 0, 'other' => 0],
            'osm' => 0,
        ];

        foreach ($places as $place) {
            foreach (($place['links'] ?? []) as $link) {
                $type = $link['source_type'] ?? '';
                if ($type === 'wikipedia') {
                    $resultType = $link['result_type'] ?? 'other';
                    if ($resultType === 'new') {
                        $summary['wikipedia']['new']++;
                    } elseif ($resultType === 'expanded') {
                        $summary['wikipedia']['expanded']++;
                    } else {
                        $summary['wikipedia']['other']++;
                    }
                } elseif ($type === 'wikidata') {
                    $summary['wikidata']++;
                } elseif ($type === 'osm') {
                    $summary['osm']++;
                } elseif ($type === 'commons') {
                    $key = strtolower((string)($link['source_key'] ?? ''));
                    if (str_starts_with($key, 'file:')) {
                        $summary['commons']['files']++;
                    } elseif (str_starts_with($key, 'category:')) {
                        $summary['commons']['categories']++;
                    } else {
                        $summary['commons']['other']++;
                    }
                }
            }
        }

        return $summary;
    }
}
