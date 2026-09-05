<?php

declare(strict_types=1);

final class OsmDiffController
{
    public function __construct(
        private PDO $pdo,
        private string $endpoint,
        private int $cacheTtl = 3600
    ) {
        $this->endpoint = rtrim($this->endpoint, '/');
    }

    public function preview(array $data): array
    {
        $request = $this->normalizeRequest($data);
        $diffId = hash('sha256', json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $stmt = $this->pdo->prepare(
            'SELECT data_json FROM diffs WHERE diff_id = :id AND (persistent = 1 OR expires_at > UTC_TIMESTAMP())'
        );
        $stmt->execute(['id' => $diffId]);
        $cached = $stmt->fetchColumn();
        if (is_string($cached) && $cached !== '') {
            $payload = safeJsonDecode($cached, []);
            if (is_array($payload)) {
                $payload['diff_id'] = $diffId;
                $payload['cached'] = true;
                return $payload;
            }
        }

        $xml = $this->callOverpass($this->buildQuery($request));
        $payload = $this->parseAugmentedDiff($xml);
        $payload['request'] = $request;

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expires = $now->modify('+' . $this->cacheTtl . ' seconds')->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO diffs (diff_id, request_json, data_json, persistent, created_at, expires_at)
             VALUES (:id, :request_json, :data_json, 0, :created_at, :expires_at)
             ON DUPLICATE KEY UPDATE request_json = VALUES(request_json), data_json = VALUES(data_json),
               created_at = VALUES(created_at), expires_at = VALUES(expires_at)'
        );
        $stmt->execute([
            'id' => $diffId,
            'request_json' => json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'data_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'expires_at' => $expires,
        ]);
        $payload['diff_id'] = $diffId;
        $payload['cached'] = false;
        return $payload;
    }

    public function getSaved(string $diffId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT data_json FROM diffs WHERE diff_id = :id AND persistent = 1');
        $stmt->execute(['id' => $diffId]);
        $json = $stmt->fetchColumn();
        if (!is_string($json)) {
            return null;
        }
        $data = safeJsonDecode($json, null);
        return is_array($data) ? $data : null;
    }

    public function assertMatches(string $diffId, array $data): array
    {
        $expected = $this->normalizeRequest($data);
        $stmt = $this->pdo->prepare(
            'SELECT request_json, data_json FROM diffs WHERE diff_id = :id AND (persistent = 1 OR expires_at > UTC_TIMESTAMP())'
        );
        $stmt->execute(['id' => $diffId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Diff preview not found or expired; preview the diff again');
        }
        $actual = safeJsonDecode((string)$row['request_json'], []);
        if ($actual !== $expected) {
            throw new RuntimeException('Diff preview does not match the project activity range');
        }
        $payload = safeJsonDecode((string)$row['data_json'], []);
        if (!is_array($payload)) {
            throw new RuntimeException('Stored diff preview is invalid');
        }
        return $payload;
    }

    public function persist(string $diffId, int $projectId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE diffs SET persistent = 1, project_id = :project_id WHERE diff_id = :diff_id'
        );
        $stmt->execute(['project_id' => $projectId, 'diff_id' => $diffId]);
        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare('SELECT 1 FROM diffs WHERE diff_id = :diff_id');
            $check->execute(['diff_id' => $diffId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Diff preview not found');
            }
        }
    }

    private function normalizeRequest(array $data): array
    {
        $bbox = normalizeBbox($data['bbox'] ?? null);
        $timezone = requireString($data, 'timezone', 64);
        $startUtc = toUtc(requireString($data, 'start_at', 64), $timezone);
        $endUtc = toUtc(requireString($data, 'end_at', 64), $timezone);
        validateActivityRange($startUtc, $endUtc);
        return [
            'bbox' => $bbox,
            'start_at' => utcIso($startUtc),
            'end_at' => utcIso($endUtc),
            'timezone' => $timezone,
        ];
    }

    private function buildQuery(array $request): string
    {
        [$west, $south, $east, $north] = $request['bbox'];
        $bbox = implode(',', [$south, $west, $north, $east]);
        return sprintf(
            '[out:xml][timeout:60][adiff:"%s","%s"];(node(%s);way(%s);relation(%s););out meta geom;',
            $request['start_at'],
            $request['end_at'],
            $bbox,
            $bbox,
            $bbox
        );
    }

    private function callOverpass(string $query): string
    {
        if ($this->endpoint === '') {
            throw new RuntimeException('Overpass history endpoint is not configured');
        }
        $post = http_build_query(['data' => $query]);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/xml,text/xml\r\nUser-Agent: PlaceFieldNotes/1.0\r\n",
                'content' => $post,
                'timeout' => 70,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($this->endpoint, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
        if ($body === false || $status >= 400) {
            $detail = is_string($body) ? trim(strip_tags($body)) : '';
            $detail = mb_substr(preg_replace('/\s+/', ' ', $detail) ?? '', 0, 300);
            throw new RuntimeException(
                'Overpass augmented diff failed. Confirm that OVERPASS_HISTORY_ENDPOINT supports attic/history data.' .
                ($detail !== '' ? ' ' . $detail : '')
            );
        }
        if (!str_contains(ltrim($body), '<')) {
            throw new RuntimeException('Overpass returned an unexpected response');
        }
        return $body;
    }

    private function parseAugmentedDiff(string $xml): array
    {
        libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($root === false) {
            throw new RuntimeException('Could not parse Overpass augmented diff XML');
        }

        $features = [];
        $candidates = [];
        $summary = $this->emptySummary();
        foreach ($root->action as $action) {
            $type = strtolower((string)$action['type']);
            if (!in_array($type, ['create', 'modify', 'delete'], true)) {
                continue;
            }
            $element = $this->elementForAction($action, $type);
            if ($element === null) {
                continue;
            }
            $osmType = $element->getName();
            if (!in_array($osmType, ['node', 'way', 'relation'], true)) {
                continue;
            }
            $osmId = (int)$element['id'];
            $tags = $this->tags($element);
            $actionName = ['create' => 'added', 'modify' => 'modified', 'delete' => 'deleted'][$type];
            $category = $this->categoryForTags($tags);
            $summary['total']++;
            $summary['actions'][$actionName]++;
            $summary['categories'][$category]++;

            $features[] = [
                'type' => 'Feature',
                'id' => $osmType . '/' . $osmId,
                'geometry' => $this->geometry($element),
                'properties' => [
                    'action' => $actionName,
                    'osm_type' => $osmType,
                    'osm_id' => $osmId,
                    'name' => $tags['name'] ?? null,
                    'category' => $category,
                    'tags' => $tags,
                ],
            ];

            if (isset($tags['wikipedia']) || isset($tags['wikidata']) || isset($tags['wikimedia_commons'])) {
                $key = $osmType . '/' . $osmId;
                $candidates[$key] = [
                    'id' => $key,
                    'osm_type' => $osmType,
                    'osm_id' => $osmId,
                    'name' => $tags['name'] ?? $key,
                    'wikipedia' => isset($tags['wikipedia']) ? $this->wikipediaUrl($tags['wikipedia']) : null,
                    'wikipedia_tag' => $tags['wikipedia'] ?? null,
                    'wikidata' => isset($tags['wikidata']) ? $this->wikidataUrl($tags['wikidata']) : null,
                    'wikidata_tag' => $tags['wikidata'] ?? null,
                    'commons' => isset($tags['wikimedia_commons']) ? $this->commonsUrl($tags['wikimedia_commons']) : null,
                    'commons_tag' => $tags['wikimedia_commons'] ?? null,
                ];
            }
        }

        return [
            'geojson' => ['type' => 'FeatureCollection', 'features' => $features],
            'summary' => $summary,
            'candidates' => array_values($candidates),
        ];
    }

    private function elementForAction(SimpleXMLElement $action, string $type): ?SimpleXMLElement
    {
        if ($type === 'create') {
            return $this->firstOsmElement($action);
        }
        if ($type === 'delete') {
            return isset($action->old) ? $this->firstOsmElement($action->old) : null;
        }
        return isset($action->new) ? $this->firstOsmElement($action->new) : null;
    }

    private function firstOsmElement(SimpleXMLElement $parent): ?SimpleXMLElement
    {
        foreach (['node', 'way', 'relation'] as $name) {
            if (isset($parent->{$name}[0])) {
                return $parent->{$name}[0];
            }
        }
        return null;
    }

    private function tags(SimpleXMLElement $element): array
    {
        $tags = [];
        foreach ($element->tag as $tag) {
            $key = (string)$tag['k'];
            if ($key !== '') {
                $tags[$key] = (string)$tag['v'];
            }
        }
        return $tags;
    }

    private function geometry(SimpleXMLElement $element): ?array
    {
        if ($element->getName() === 'node') {
            if ((string)$element['lat'] === '' || (string)$element['lon'] === '') {
                return null;
            }
            return ['type' => 'Point', 'coordinates' => [(float)$element['lon'], (float)$element['lat']]];
        }
        if ($element->getName() === 'way') {
            $coords = $this->ndCoordinates($element->nd);
            if (count($coords) < 2) {
                return null;
            }
            if (count($coords) >= 4 && $coords[0] === $coords[count($coords) - 1]) {
                return ['type' => 'Polygon', 'coordinates' => [$coords]];
            }
            return ['type' => 'LineString', 'coordinates' => $coords];
        }
        if ($element->getName() === 'relation') {
            $lines = [];
            $points = [];
            foreach ($element->member as $member) {
                $coords = $this->ndCoordinates($member->nd);
                if (count($coords) >= 2) {
                    $lines[] = $coords;
                } elseif ((string)$member['lat'] !== '' && (string)$member['lon'] !== '') {
                    $points[] = [(float)$member['lon'], (float)$member['lat']];
                }
            }
            if ($lines !== []) {
                return ['type' => 'MultiLineString', 'coordinates' => $lines];
            }
            if ($points !== []) {
                return count($points) === 1
                    ? ['type' => 'Point', 'coordinates' => $points[0]]
                    : ['type' => 'MultiPoint', 'coordinates' => $points];
            }
        }
        return null;
    }

    private function ndCoordinates(SimpleXMLElement $nodes): array
    {
        $coords = [];
        foreach ($nodes as $nd) {
            if ((string)$nd['lat'] === '' || (string)$nd['lon'] === '') {
                continue;
            }
            $coords[] = [(float)$nd['lon'], (float)$nd['lat']];
        }
        return $coords;
    }

    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'actions' => ['added' => 0, 'modified' => 0, 'deleted' => 0],
            'categories' => [
                'facilities' => 0,
                'buildings' => 0,
                'entrances' => 0,
                'roads_paths' => 0,
                'accessibility' => 0,
                'other' => 0,
            ],
        ];
    }

    private function categoryForTags(array $tags): string
    {
        if (isset($tags['wheelchair']) || isset($tags['kerb']) || isset($tags['tactile_paving']) || isset($tags['incline'])) {
            return 'accessibility';
        }
        if (isset($tags['entrance'])) {
            return 'entrances';
        }
        if (isset($tags['building'])) {
            return 'buildings';
        }
        if (isset($tags['highway'])) {
            return 'roads_paths';
        }
        foreach (['amenity', 'shop', 'tourism', 'leisure', 'office', 'craft', 'healthcare'] as $key) {
            if (isset($tags[$key])) {
                return 'facilities';
            }
        }
        return 'other';
    }

    private function wikipediaUrl(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
        if (preg_match('/^([a-z0-9-]+):(.+)$/iu', $value, $m)) {
            return 'https://' . strtolower($m[1]) . '.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $m[2]));
        }
        return 'https://www.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $value));
    }

    private function wikidataUrl(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
        return 'https://www.wikidata.org/wiki/' . rawurlencode($value);
    }

    private function commonsUrl(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
        return 'https://commons.wikimedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $value));
    }
}
