<?php
declare(strict_types=1);

/* Loaded only by admin.php after its existing login and CSRF checks. */
function recordingHttp(string $url, bool $head = false): array
{
    $body = '';
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialise the recording request.');
    }
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_NOBODY => $head,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 8 * 1024 * 1024) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($ok === false) {
        throw new RuntimeException('The recording server could not be reached. Please retry.');
    }
    return ['status' => $status, 'body' => $body];
}

function recordingApi(array $environment, string $action, array $parameters): string
{
    $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    $url = rtrim(envRequired($environment, 'BBB_API_URL'), '/') . '/' . $action
        . '?' . $query . '&checksum='
        . sha1($action . $query . envRequired($environment, 'BBB_SECRET'));
    $response = recordingHttp($url);
    if ($response['status'] !== 200) {
        throw new RuntimeException('The recording server returned HTTP ' . $response['status'] . '. Please retry.');
    }
    return $response['body'];
}

/** Return every page, without treating a failed request as an empty recording list. */
function recordingList(array $environment, array $parameters): array
{
    $items = [];
    for ($offset = 0; $offset < 5000; $offset += 100) {
        $body = recordingApi($environment, 'getRecordings', $parameters + [
            'state' => 'any', 'limit' => 100, 'offset' => $offset,
        ]);
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new RuntimeException('The recording server returned an invalid recording list.');
        }
        if ((string) $xml->returncode !== 'SUCCESS') {
            if ((string) $xml->messageKey === 'noRecordings') {
                usort($items, static fn(array $a, array $b): int => $b['startTime'] <=> $a['startTime']);
                return $items;
            }
            throw new RuntimeException('The recording request failed. Check the BBB API configuration.');
        }
        $page = $xml->recordings->recording;
        foreach ($page as $recording) {
            if ((string) $recording->state === 'deleted') {
                continue;
            }
            $formats = [];
            foreach ($recording->playback->format ?? [] as $format) {
                $formats[] = [
                    'type' => strtolower(trim((string) $format->type)),
                    'url' => trim((string) $format->url),
                    'length' => (float) $format->length,
                    'size' => (float) $format->size,
                ];
            }
            $items[] = [
                'id' => (string) $recording->recordID,
                'meetingId' => (string) $recording->meetingID,
                'name' => (string) $recording->name,
                'startTime' => (float) $recording->startTime,
                'endTime' => (float) $recording->endTime,
                'participants' => (int) $recording->participants,
                'state' => (string) $recording->state,
                'published' => (string) $recording->published === 'true',
                'size' => (float) $recording->size,
                'formats' => $formats,
            ];
        }
        if (count($page) < 100) {
            usort($items, static fn(array $a, array $b): int => $b['startTime'] <=> $a['startTime']);
            return $items;
        }
    }
    throw new RuntimeException('Too many recordings to display. Please narrow the training selection.');
}

/** Only inspect media on the configured BBB host; never fetch a browser-supplied URL. */
function recordingMediaUrl(string $value, string $base, array $environment): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $baseParts = parse_url($base);
    if (!is_array($baseParts) || empty($baseParts['host'])) {
        return null;
    }
    if (str_starts_with($value, '//')) {
        $value = ($baseParts['scheme'] ?? 'https') . ':' . $value;
    } elseif (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $value)) {
        $origin = ($baseParts['scheme'] ?? 'https') . '://' . $baseParts['host']
            . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
        $basePath = (string) ($baseParts['path'] ?? '/');
        $directory = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';
        $value = $origin . (str_starts_with($value, '/') ? $value : $directory . $value);
    }
    $parts = parse_url($value);
    $api = parse_url(envRequired($environment, 'BBB_API_URL'));
    if (!is_array($parts) || !is_array($api)
        || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || strtolower($parts['host'] ?? '') !== strtolower($api['host'] ?? '')
        || ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80))
            !== ($api['port'] ?? (($api['scheme'] ?? '') === 'https' ? 443 : 80))
        || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    return $value;
}

function recordingDetails(array $environment, array $recording): array
{
    $result = ['presentationUrl' => null, 'videos' => [], 'captions' => [], 'warnings' => []];
    $videoBase = null;
    foreach ($recording['formats'] as $format) {
        $url = recordingMediaUrl($format['url'], $format['url'], $environment);
        $type = strtolower(trim($format['type']));
        if ($type === 'presentation') {
            $result['presentationUrl'] = $url;
        }
        if ($type === 'video') {
            $videoBase = $url;
        }
    }
    // This installation publishes video assets under /playback/video/<recordID>/.
    // Do not make caption translation depend on the API advertising that format.
    if ($videoBase === null && preg_match('/^[a-zA-Z0-9_-]+$/', $recording['id'])) {
        $origin = parse_url($result['presentationUrl'] ?? envRequired($environment, 'BBB_API_URL'));
        if (is_array($origin) && isset($origin['host'])) {
            $candidate = ($origin['scheme'] ?? 'https') . '://' . $origin['host']
                . (isset($origin['port']) ? ':' . $origin['port'] : '')
                . '/playback/video/' . rawurlencode($recording['id']) . '/';
            $videoBase = recordingMediaUrl($candidate, $candidate, $environment);
        }
    }
    if ($videoBase !== null) {
        // The video format URL denotes a directory, even if its final slash was omitted.
        $videoBase = rtrim($videoBase, '/') . '/';
    }
    // The published player declares the actual video file and accessible caption files.
    if ($videoBase !== null) {
        try {
            $response = recordingHttp($videoBase);
            if ($response['status'] !== 200) {
                throw new RuntimeException('Video playback files could not be loaded (HTTP '
                    . $response['status'] . ').');
            }
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $loaded = $document->loadHTML($response['body'], LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (!$loaded) {
                throw new RuntimeException('Video file links could not be read.');
            }
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//source[@src] | //video[@src]') as $source) {
                $url = recordingMediaUrl($source->getAttribute('src'), $videoBase, $environment);
                if ($url !== null) {
                    $result['videos'][$url] = ['url' => $url,
                        'type' => $source->getAttribute('type')];
                }
            }
            foreach ($xpath->query('//track[@src]') as $track) {
                if (!in_array(strtolower(trim($track->getAttribute('kind'))), ['captions', 'subtitles'], true)) {
                    continue;
                }
                $url = recordingMediaUrl($track->getAttribute('src'), $videoBase, $environment);
                if ($url !== null) {
                    $result['captions'][$url] = ['url' => $url,
                        'lang' => $track->getAttribute('srclang'),
                        'label' => $track->getAttribute('label') ?: $track->getAttribute('srclang')];
                }
            }
        } catch (Throwable $error) {
            $result['warnings'][] = $error->getMessage();
        }
    }
    if (!$result['videos'] && $videoBase !== null) {
        // Known file layout on this server; only expose a video after checking it.
        $candidate = $videoBase . 'video-0.m4v';
        try {
            if (recordingHttp($candidate, true)['status'] === 200) {
                $result['videos'][$candidate] = ['url' => $candidate, 'type' => 'video/mp4'];
            }
        } catch (Throwable $error) {
            // The popup keeps presentation and caption actions available independently.
        }
    }
    // Include tracks published after the player was generated, and presentation-only tracks.
    try {
        $tracksResponse = json_decode(recordingApi($environment, 'getRecordingTextTracks', [
            'recordID' => $recording['id'],
        ]), true, 512, JSON_THROW_ON_ERROR);
        if (($tracksResponse['response']['returncode'] ?? '') !== 'SUCCESS') {
            throw new RuntimeException('Caption availability could not be checked.');
        }
        foreach ($tracksResponse['response']['tracks'] ?? [] as $track) {
            $lang = (string) ($track['lang'] ?? '');
            if (array_filter($result['captions'], static fn(array $item): bool => $item['lang'] === $lang)) {
                continue;
            }
            $candidates = [];
            if ($videoBase !== null && preg_match('/^[a-zA-Z0-9-]+$/', $lang)) {
                $candidates[] = rtrim($videoBase, '/') . '/caption_' . rawurlencode($lang) . '.vtt';
            }
            $candidates[] = (string) ($track['href'] ?? '');
            $found = false;
            foreach ($candidates as $candidate) {
                $url = recordingMediaUrl($candidate, $videoBase ?? $candidate, $environment);
                if ($url === null) {
                    continue;
                }
                $probe = recordingHttp($url, true);
                // Some hosting rules reject HEAD while allowing the player's GET request.
                if ($probe['status'] !== 200) {
                    $probe = recordingHttp($url);
                }
                if ($probe['status'] === 200) {
                    $result['captions'][$url] = ['url' => $url, 'lang' => $lang,
                        'label' => (string) ($track['label'] ?? $lang)];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $result['warnings'][] = 'The ' . $lang . ' captions exist but their download link could not be verified'
                    . ($videoBase !== null ? ': ' . $videoBase . 'caption_' . rawurlencode($lang) . '.vtt'
                        : '. No trusted video playback URL was found.');
            }
        }
    } catch (Throwable $error) {
        $result['warnings'][] = 'Some caption links could not be checked. Please retry.';
    }
    $result['videos'] = array_values($result['videos']);
    $result['captions'] = array_values($result['captions']);
    return $result;
}

/** The caller has already verified the admin session and CSRF token. */
function recordingAdminRequest(PDO $pdo, array $environment, string $action): array
{
    if (!in_array($action, ['recordings_list_ajax', 'recording_details_ajax', 'delete_recording_ajax'], true)) {
        throw new RuntimeException('Unknown recording action.');
    }
    if ($action === 'recordings_list_ajax') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])),
            static fn(int $id): bool => $id > 0)));
        if (!$ids || count($ids) > 20) {
            throw new RuntimeException('Select between one and twenty trainings.');
        }
        $statement = $pdo->prepare('SELECT ID, meeting_id FROM trainings WHERE ID IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $statement->execute($ids);
        $trainings = $statement->fetchAll(PDO::FETCH_ASSOC);
        $byTraining = [];
        foreach ($trainings as $training) {
            $byTraining[(string) $training['ID']] = [];
        }
        if ($trainings) {
            $meetingIds = array_values(array_unique(array_column($trainings, 'meeting_id')));
            $recordings = recordingList($environment, ['meetingID' => implode(',', $meetingIds)]);
            foreach ($trainings as $training) {
                $byTraining[(string) $training['ID']] = array_values(array_filter($recordings,
                    static fn(array $recording): bool => $recording['meetingId'] === $training['meeting_id']));
            }
        }
        return ['trainings' => (object) $byTraining];
    }
    $id = (int) ($_POST['ID'] ?? 0);
    $recordId = (string) ($_POST['record_id'] ?? '');
    if ($id < 1 || !preg_match('/^[a-zA-Z0-9_-]{1,255}$/', $recordId)) {
        throw new RuntimeException('Invalid recording selection.');
    }
    $statement = $pdo->prepare('SELECT meeting_id FROM trainings WHERE ID = ?');
    $statement->execute([$id]);
    $meetingId = $statement->fetchColumn();
    if ($meetingId === false) {
        throw new RuntimeException('This training no longer exists.');
    }
    $recordings = recordingList($environment, ['recordID' => $recordId]);
    foreach ($recordings as $recording) {
        if ($recording['id'] === $recordId && $recording['meetingId'] === $meetingId) {
            if ($action === 'delete_recording_ajax') {
                if (($_POST['confirmed'] ?? '') !== '1') {
                    throw new RuntimeException('Please confirm the recording deletion.');
                }
                $body = recordingApi($environment, 'deleteRecordings', ['recordID' => $recordId]);
                $previous = libxml_use_internal_errors(true);
                $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
                if ($xml === false || (string) $xml->returncode !== 'SUCCESS'
                    || (string) $xml->deleted !== 'true') {
                    throw new RuntimeException('The recording server did not confirm deletion. Refresh the list before retrying.');
                }
                return ['deletedRecordId' => $recordId];
            }
            return ['details' => recordingDetails($environment, $recording)];
        }
    }
    throw new RuntimeException('This recording is no longer available for the selected training.');
}
