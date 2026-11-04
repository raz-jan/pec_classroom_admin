<?php
/**
 * PEC / eFront - course-first group test matrix report.
 *
 * Intended placement: alongside admin.php and training.css.
 * Requirements: PHP 8+, curl extension, PHP sessions.
 *
 * Access: requires the existing admin.php login session.
 * Configuration from system environment or root/parent/local .env:
 *   EFRONT_API_BASE=https://your-efront.example/API
 *   EFRONT_API_KEY=your-api-key
 */
declare(strict_types=1);

// Latest GET versions for these operations in the official 3.0.0 specification.
// The specification version is not a common version prefix for every endpoint.
// https://app.swaggerhub.com/apis/Epignosis/Efront-API/3.0.0
const PEC_EFRONT_ENDPOINTS = [
    'courses' => '/v2.2/Courses',
    'course_users' => '/v3.0/Course/',
    'groups' => '/v1.0/Groups',
    'group' => '/v1.0/Group/',
    'attempts' => '/v1.0/CourseUserTestAttempts/',
];

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-store, max-age=0');

// Share the administration login; reject access before loading configuration or results.
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();
if (empty($_SESSION['training_admin_authorized'])) {
    header('Location: admin.php?return=test-results.php', true, 302);
    exit;
}

function pecLoadDotEnv(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = substr($line, 7);
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && substr($value, -1) === '"') ||
            ($value[0] === "'" && substr($value, -1) === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

pecLoadDotEnv(dirname(__DIR__, 2) . '/.env');
pecLoadDotEnv(dirname(__DIR__) . '/.env');
pecLoadDotEnv(__DIR__ . '/.env');

function pecEnv(array $keys): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && trim($value) !== '') {
            return trim($value);
        }
    }
    return '';
}

$apiBase = rtrim(pecEnv(['EFRONT_API_BASE', 'EFRONT_API_URL']), '/');
$apiKey = pecEnv(['EFRONT_API_KEY']);
if ($apiBase === '' || $apiKey === '') {
    http_response_code(503);
    exit('eFront API is not configured. Set EFRONT_API_BASE and EFRONT_API_KEY.');
}
if (!filter_var($apiBase, FILTER_VALIDATE_URL)
    || parse_url($apiBase, PHP_URL_SCHEME) !== 'https'
    || parse_url($apiBase, PHP_URL_QUERY) !== null
    || parse_url($apiBase, PHP_URL_FRAGMENT) !== null
    || parse_url($apiBase, PHP_URL_USER) !== null
    || !preg_match('#/API(?:/v[0-9]+(?:\.[0-9]+)*)?$#i', $apiBase)) {
    http_response_code(503);
    exit('EFRONT_API_BASE must be an HTTPS URL ending in /API (an existing /API/v1.0-style setting is also supported).');
}
// Accept existing versioned settings, then choose the version per operation.
$apiBase = (string) preg_replace('#/API(?:/v[0-9]+(?:\.[0-9]+)*)?$#i', '/API', $apiBase);
if (!extension_loaded('curl')) {
    http_response_code(503);
    exit('The PHP curl extension is required.');
}

date_default_timezone_set('Europe/Brussels');

function pecH($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pecApiErrorMessage(array $response, int $httpStatus): string
{
    $error = $response['error'] ?? null;
    $message = is_array($error) ? ($error['message'] ?? $error['reason'] ?? null) : $error;
    if (!is_scalar($message) || trim((string) $message) === '') {
        $message = 'Unexpected eFront API response';
    }
    return 'HTTP ' . $httpStatus . ': ' . (string) $message;
}

function pecApiBatch(array $endpoints, string $base, string $key, int $parallel = 5): array
{
    $queue = [];
    foreach ($endpoints as $id => $path) {
        $queue[] = ['id' => (string) $id, 'path' => $path];
    }
    $results = [];
    $active = [];
    $multi = curl_multi_init();
    $offset = 0;
    $total = count($queue);

    while ($offset < $total || count($active) > 0) {
        while (count($active) < $parallel && $offset < $total) {
            $job = $queue[$offset++];
            $ch = curl_init($base . $job['path']);
            if ($ch === false) {
                $results[$job['id']] = ['error' => 'Could not initialize curl'];
                continue;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $key . ':',
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: en_US'],
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $handleId = spl_object_id($ch);
            $active[$handleId] = ['id' => $job['id'], 'handle' => $ch];
            $added = curl_multi_add_handle($multi, $ch);
            if ($added !== CURLM_OK) {
                unset($active[$handleId]);
                $results[$job['id']] = ['error' => 'Could not queue curl request'];
                curl_close($ch);
            }
        }

        do {
            $code = curl_multi_exec($multi, $running);
        } while ($code === CURLM_CALL_MULTI_PERFORM);

        if ($code !== CURLM_OK) {
            foreach ($active as $item) {
                $results[$item['id']] = ['error' => 'HTTP batch could not complete'];
                curl_multi_remove_handle($multi, $item['handle']);
                curl_close($item['handle']);
            }
            $active = [];
            break;
        }

        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $handleId = spl_object_id($ch);
            if (!isset($active[$handleId])) {
                continue;
            }
            $id = $active[$handleId]['id'];
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($ch);

            if ($info['result'] !== CURLE_OK) {
                $results[$id] = ['error' => 'Connection problem: ' . curl_error($ch)];
            } else {
                $decoded = json_decode((string) $body, true);
                if (!is_array($decoded)) {
                    $results[$id] = ['error' => 'Invalid JSON (HTTP ' . $status . ')'];
                } elseif ($status < 200 || $status >= 300 || ($decoded['success'] ?? true) === false) {
                    $results[$id] = ['error' => pecApiErrorMessage($decoded, $status)];
                } elseif (!array_key_exists('data', $decoded)) {
                    $results[$id] = ['error' => 'Missing data in API response'];
                } else {
                    $results[$id] = ['data' => $decoded['data']];
                }
            }

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            unset($active[$handleId]);
        }

        if ($running > 0 && count($active) > 0) {
            if (curl_multi_select($multi, 0.5) === -1) {
                usleep(100000);
            }
        }
    }

    curl_multi_close($multi);
    return $results;
}

function pecApiGet(string $path, string $base, string $key): array
{
    $response = pecApiBatch(['only' => $path], $base, $key, 1)['only'] ?? null;
    if (!is_array($response) || isset($response['error'])) {
        throw new RuntimeException((string) ($response['error'] ?? 'eFront request failed'));
    }
    if (!is_array($response['data'])) {
        throw new RuntimeException('Unexpected response data type');
    }
    return $response['data'];
}

function pecCourseList(string $base, string $key, bool $refresh): array
{
    $cache = $_SESSION['efront_course_list'] ?? null;
    $source = $base . PEC_EFRONT_ENDPOINTS['courses'];
    if (!$refresh && is_array($cache) && ($cache['source'] ?? '') === $source
        && ($cache['expires'] ?? 0) > time()) {
        return $cache['items'];
    }
    $data = pecApiGet(PEC_EFRONT_ENDPOINTS['courses'], $base, $key);
    $courses = [];
    foreach ($data as $course) {
        if (is_array($course) && (int) ($course['id'] ?? 0) > 0) {
            $courses[] = $course;
        }
    }
    usort($courses, fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    $_SESSION['efront_course_list'] = ['items' => $courses, 'source' => $source, 'expires' => time() + 600];
    return $courses;
}

function pecGroupList(string $base, string $key, bool $refresh): array
{
    $cache = $_SESSION['efront_group_list'] ?? null;
    $source = $base . PEC_EFRONT_ENDPOINTS['groups'];
    if (!$refresh && is_array($cache) && ($cache['source'] ?? '') === $source
        && ($cache['expires'] ?? 0) > time()) {
        return $cache['items'];
    }
    $data = pecApiGet(PEC_EFRONT_ENDPOINTS['groups'], $base, $key);
    $groups = array_values(array_filter($data, static fn($group) =>
        is_array($group) && (int) ($group['id'] ?? 0) > 0));
    usort($groups, static fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    $_SESSION['efront_group_list'] = ['items' => $groups, 'source' => $source, 'expires' => time() + 600];
    return $groups;
}

/** Keep the course roster authoritative; a group only narrows it by user ID. */
function pecFilterCourseMembers(array $courseUsers, ?array $groupUsers): array
{
    $groupIds = null;
    if ($groupUsers !== null) {
        $groupIds = [];
        foreach ($groupUsers as $user) {
            if (is_array($user) && (int) ($user['id'] ?? 0) > 0) {
                $groupIds[(int) $user['id']] = true;
            }
        }
    }
    $members = [];
    foreach ($courseUsers as $user) {
        if (!is_array($user)) {
            continue;
        }
        $id = (int) ($user['id'] ?? 0);
        if ($id > 0 && ($groupIds === null || isset($groupIds[$id]))) {
            $members[$id] = $user;
        }
    }
    return array_values($members);
}

function pecUserName(array $user): string
{
    $name = trim((string) ($user['name'] ?? '') . ' ' . (string) ($user['surname'] ?? ''));
    // eFront can return HTML entities in names. Decode to plain text here so
    // sorting and exports use the real characters; pecH still escapes HTML output.
    return html_entity_decode(
        $name !== '' ? $name : (string) ($user['login'] ?? 'Unknown user'),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
}

function pecIsOpinionTest(string $name): bool
{
    $value = mb_strtolower(trim($name), 'UTF-8');
    if ($value === '') {
        return false;
    }
    $patterns = [
        'votre avis',
        'uw mening',
        'your opinion',
        'your feedback',
        'feedback',
        'opinion',
        'avis',
        'enquête',
        'survey',
    ];
    foreach ($patterns as $pattern) {
        if (mb_strpos($value, $pattern, 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

function pecScoreNumeric($score): ?float
{
    if ($score === null || $score === '') {
        return null;
    }
    if (is_numeric($score)) {
        return (float) $score;
    }
    $value = trim((string) $score);
    $value = str_replace(',', '.', $value);
    $value = rtrim($value, "% \t\n\r\0\x0B");
    return is_numeric($value) ? (float) $value : null;
}

function pecDisplayScore($score): string
{
    if ($score === null || $score === '') {
        return '---';
    }
    if (is_numeric($score)) {
        $number = (float) $score;
        $rounded = abs($number - round($number)) < 0.001 ? (string) (int) round($number) : number_format($number, 1, ',', '');
        return $rounded . '%';
    }
    $value = trim((string) $score);
    return $value === '' ? '---' : $value;
}

function pecCellClass(?array $attempt): string
{
    $score = pecScoreNumeric($attempt['score'] ?? null);
    if ($score === null) {
        return 'empty';
    }
    return $score >= 60 ? 'high' : 'low';
}

/** Normalize first/retake labels without losing the test number or subject. */
function pecTestSortKey(string $name): array
{
    $value = strtr(mb_strtolower(trim($name), 'UTF-8'), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e']);
    $retake = '/\b(?:deuxieme|2eme|2e|2de|tweede|seconde?|2nd|herkansing(?:s)?[\s-]*test|herkansing|herexamen|retake|resit|bis)\b/u';
    $isRetake = preg_match($retake, $value) === 1;
    $value = (string) preg_replace('/\bherkansing(?:s)?[\s-]*test\b/u', 'test', $value);
    $value = (string) preg_replace($retake, '', $value);
    $value = (string) preg_replace('/\b(?:eerste|premier|premiere|first|1er|1ere|1e|1ste)\b/u', '', $value);
    $base = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    return [$base, $isRetake];
}

function pecCompareTests(string $a, string $b): int
{
    [$baseA, $retakeA] = pecTestSortKey($a);
    [$baseB, $retakeB] = pecTestSortKey($b);
    $byBase = strnatcasecmp($baseA, $baseB);
    if ($byBase !== 0) {
        return $byBase;
    }
    if ($retakeA !== $retakeB) {
        return $retakeA ? 1 : -1;
    }
    return strnatcasecmp($a, $b);
}

function pecTooltip(array $attempt): string
{
    $parts = [];
    $parts[] = (string) ($attempt['test_name'] ?? 'Test');
    $score = pecDisplayScore($attempt['score'] ?? null);
    if ($score !== '---') {
        $parts[] = 'Score: ' . $score;
    }
    $status = trim((string) ($attempt['status'] ?? ''));
    if ($status !== '') {
        $parts[] = 'Status: ' . $status;
    }
    $started = $attempt['time_start'] ?? null;
    if (is_numeric($started) && (int) $started > 0) {
        $parts[] = 'Started: ' . date('d/m/Y H:i', (int) $started);
    }
    $finished = $attempt['time_end'] ?? null;
    if (is_numeric($finished) && (int) $finished > 0) {
        $parts[] = 'Finished: ' . date('d/m/Y H:i', (int) $finished);
    }
    return implode("\n", $parts);
}

/** Export the visible matrix using the same PhpSpreadsheet dependency as admin.php. */
function pecExportExcel(array $users, array $tests, array $matrix, array $userErrors, array $totalResults, string $course, string $group, string $loadedAt, bool $showOpinion): never
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_readable($autoload)) {
        throw new RuntimeException('Excel export requires the existing vendor/PhpSpreadsheet installation.');
    }
    require_once $autoload;
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('Test results');
    // Explicit strings prevent course names, emails or API scores becoming formulas.
    $text = static function (string $cell, string $value) use ($sheet): void {
        $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    };
    $text('A1', 'PEC - eFront testresultaten');
    $text('A2', $course . ' / ' . $group);
    $text('A3', 'Laatste update: ' . $loadedAt . ' | Mening/avis: ' . ($showOpinion ? 'zichtbaar' : 'verborgen'));
    $text('A4', 'Laatste poging per test. --- = geen score. Zie celnotities voor status, tijdstip en API-fouten.');
    $text('A5', 'Total = geslaagde tests (minstens 60% bij een poging) / maximaal aantal unieke tests per deelnemer in deze selectie. Herkansingen tellen niet dubbel.');
    $headers = array_merge(['Gebruiker', 'E-mail', 'Total'], $tests);
    foreach ($headers as $index => $header) {
        $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $text($column . '6', $header);
        $sheet->getColumnDimension($column)->setWidth($index < 2 ? 32 : 18);
    }
    $sheet->getColumnDimension('C')->setWidth(12);
    $colors = ['high' => 'C9F0C5', 'low' => 'F6D0D6', 'empty' => 'FFFFFF'];
    foreach ($users as $index => $user) {
        $row = $index + 7;
        $uid = (int) ($user['id'] ?? 0);
        $text('A' . $row, pecUserName($user));
        $text('B' . $row, (string) ($user['email'] ?? ($user['login'] ?? '')));
        $text('C' . $row, $totalResults[$uid]);
        if (isset($userErrors[$uid])) {
            $sheet->getComment('A' . $row)->getText()->createTextRun('API-fout: ' . $userErrors[$uid]);
        }
        foreach ($tests as $testIndex => $test) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($testIndex + 4) . $row;
            $attempt = $matrix[$uid][$test] ?? null;
            $text($cell, pecDisplayScore($attempt['score'] ?? null));
            $sheet->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setRGB($colors[pecCellClass($attempt)]);
            if ($attempt !== null) {
                $sheet->getComment($cell)->getText()->createTextRun(pecTooltip($attempt));
            }
        }
    }
    $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A6:' . $lastColumn . '6')->getFont()->setBold(true)->getColor()->setRGB('004E70');
    $sheet->getStyle('A6:' . $lastColumn . '6')->getAlignment()->setWrapText(true);
    $sheet->getRowDimension(6)->setRowHeight(60);
    $sheet->setAutoFilter('A6:' . $lastColumn . (count($users) + 6));
    $sheet->freezePane('D7');
    $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 6);
    // Build the file before sending headers so failures still produce a readable page.
    $temporary = tempnam(sys_get_temp_dir(), 'pec-results-');
    if ($temporary === false) {
        throw new RuntimeException('Could not create the Excel export.');
    }
    try {
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($temporary);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="efront-test-results-' . date('Y-m-d') . '.xlsx"');
        readfile($temporary);
    } finally {
        unlink($temporary);
        $book->disconnectWorksheets();
    }
    exit;
}

$refresh = ($_GET['refresh'] ?? '') === '1';
$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$groupId = filter_input(INPUT_GET, 'group_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$filter = is_string($_GET['filter'] ?? null) ? substr((string) $_GET['filter'], 0, 200) : '';
$groupFilter = is_string($_GET['group_filter'] ?? null) ? substr((string) $_GET['group_filter'], 0, 200) : '';
$showOpinion = ($_GET['show_opinion'] ?? '') === '1';
$userOrderOptions = [
    'surname_asc' => 'Achternaam A–Z',
    'surname_desc' => 'Achternaam Z–A',
    'asc' => 'Voornaam A–Z',
    'desc' => 'Voornaam Z–A',
    'api' => 'API-volgorde',
];
$requestedOrder = $_GET['user_order'] ?? '';
$userOrder = is_string($requestedOrder) && isset($userOrderOptions[$requestedOrder])
    ? $requestedOrder : 'surname_asc';
$nextUserOrder = match ($userOrder) {
    'surname_asc' => 'surname_desc',
    'surname_desc' => 'asc',
    'asc' => 'desc',
    default => 'surname_asc',
};
$descending = in_array($userOrder, ['surname_desc', 'desc'], true);
$reportQuery = [
    'course_id' => $courseId,
    'group_id' => $groupId,
    'filter' => $filter,
    'group_filter' => $groupFilter,
    'show_opinion' => $showOpinion ? '1' : '0',
    'user_order' => $userOrder,
];

$courses = [];
$groups = [];
$groupListError = null;
$courseName = '';
$groupName = 'Alle cursusdeelnemers';
$users = [];
$tests = [];
$matrix = [];
$testOutcomes = [];
$userErrors = [];
$visibleAttemptCount = 0;
$hiddenOpinionCount = 0;
$apiErrorCount = 0;
$mainError = null;
$loadedAt = date('d/m/Y H:i:s');

try {
    $courses = pecCourseList($apiBase, $apiKey, $refresh);
    try {
        $groups = pecGroupList($apiBase, $apiKey, $refresh);
    } catch (Throwable $error) {
        // A failed group list must not prevent an unfiltered course report.
        $groupListError = $error->getMessage();
    }
    if ($courseId > 0) {
        $selectedCourse = null;
        foreach ($courses as $course) {
            if ((int) $course['id'] === $courseId) {
                $selectedCourse = $course;
                break;
            }
        }
        if ($selectedCourse === null) {
            throw new RuntimeException('The selected course is not in /Courses.');
        }
        $courseName = (string) ($selectedCourse['name'] ?? 'Course ' . $courseId);

        $courseDetails = pecApiGet(PEC_EFRONT_ENDPOINTS['course_users'] . $courseId . '/Users', $apiBase, $apiKey);
        $courseUsers = $courseDetails['list'] ?? null;
        if (!is_array($courseUsers)) {
            throw new RuntimeException('Course users are not a list.');
        }
        $groupUsers = null;
        if ($groupId > 0) {
            $selectedGroup = null;
            foreach ($groups as $group) {
                if ((int) $group['id'] === $groupId) {
                    $selectedGroup = $group;
                    break;
                }
            }
            if ($selectedGroup === null) {
                throw new RuntimeException($groupListError !== null
                    ? 'De geselecteerde groep kon niet worden geladen. Kies geen groep om alle cursusdeelnemers te zien.'
                    : 'De geselecteerde groep bestaat niet meer of is niet toegankelijk. Kies een andere groep of alle cursusdeelnemers.');
            }
            $groupName = (string) ($selectedGroup['name'] ?? ('Group ' . $groupId));
            $details = pecApiGet(PEC_EFRONT_ENDPOINTS['group'] . $groupId, $apiBase, $apiKey);
            $groupUsers = $details['users']['list'] ?? null;
            if (!is_array($groupUsers)) {
                throw new RuntimeException('Group users are not a list.');
            }
        }
        $users = pecFilterCourseMembers($courseUsers, $groupUsers);
        if ($userOrder !== 'api') {
            $nameCollator = class_exists(Collator::class) ? new Collator('nl_BE') : null;
            $nameCollator?->setStrength(Collator::PRIMARY);
            $bySurname = str_starts_with($userOrder, 'surname_');
            $sortName = static function (array $user) use ($bySurname): string {
                $surname = trim(html_entity_decode((string) ($user['surname'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                return mb_strtolower($bySurname && $surname !== '' ? $surname : pecUserName($user), 'UTF-8');
            };
            usort($users, static function ($a, $b) use ($sortName, $descending, $nameCollator) {
                $nameA = $sortName($a);
                $nameB = $sortName($b);
                $byName = $nameCollator !== null
                    ? $nameCollator->compare($nameA, $nameB)
                    : strnatcasecmp($nameA, $nameB);
                $byFullName = $nameCollator !== null
                    ? $nameCollator->compare(pecUserName($a), pecUserName($b))
                    : strnatcasecmp(mb_strtolower(pecUserName($a), 'UTF-8'), mb_strtolower(pecUserName($b), 'UTF-8'));
                $comparison = $byName ?: $byFullName ?: ((int) $a['id'] <=> (int) $b['id']);
                return $descending ? -$comparison : $comparison;
            });
        }

        $endpoints = [];
        foreach ($users as $user) {
            $uid = (int) ($user['id'] ?? 0);
            if ($uid > 0) {
                $endpoints['u' . $uid] = PEC_EFRONT_ENDPOINTS['attempts'] . $courseId . ',' . $uid;
            }
        }
        $userResponses = pecApiBatch($endpoints, $apiBase, $apiKey, 5);

        foreach ($users as $user) {
            $uid = (int) ($user['id'] ?? 0);
            $matrix[$uid] = [];
            $testOutcomes[$uid] = [];
            $response = $uid > 0
                ? ($userResponses['u' . $uid] ?? ['error' => 'Missing response'])
                : ['error' => 'Missing user ID'];

            if (isset($response['error'])) {
                $apiErrorCount++;
                $userErrors[$uid] = (string) $response['error'];
                continue;
            }

            $attempts = $response['data'] ?? null;
            if (!is_array($attempts)) {
                $apiErrorCount++;
                $userErrors[$uid] = 'Invalid attempts list';
                continue;
            }

            foreach ($attempts as $attempt) {
                if (!is_array($attempt)) {
                    continue;
                }
                $testName = trim((string) ($attempt['test_name'] ?? ''));
                if ($testName === '') {
                    $testName = 'Unnamed test';
                }
                if (!$showOpinion && pecIsOpinionTest($testName)) {
                    $hiddenOpinionCount++;
                    continue;
                }
                // A first attempt and its retake share one test. Keep any pass,
                // even when a later attempt replaces the displayed score.
                [$testKey] = pecTestSortKey($testName);
                $score = pecScoreNumeric($attempt['score'] ?? null);
                $testOutcomes[$uid][$testKey] = ($testOutcomes[$uid][$testKey] ?? false)
                    || ($score !== null && $score >= 60);
                $tests[$testName] = true;
                $current = $matrix[$uid][$testName] ?? null;
                $currentTime = is_array($current) ? (int) ($current['time_start'] ?? 0) : -1;
                $newTime = (int) ($attempt['time_start'] ?? 0);
                if ($current === null || $newTime >= $currentTime) {
                    $matrix[$uid][$testName] = $attempt;
                }
            }
        }

        $tests = array_keys($tests);
        usort($tests, 'pecCompareTests');

        foreach ($matrix as $row) {
            $visibleAttemptCount += count($row);
        }

    }
} catch (Throwable $error) {
    $mainError = $error->getMessage();
}

$totalTests = 0;
foreach ($testOutcomes as $outcomes) {
    $totalTests = max($totalTests, count($outcomes));
}
$totalResults = [];
foreach ($users as $user) {
    $uid = (int) ($user['id'] ?? 0);
    $passed = isset($userErrors[$uid]) ? '---' : (string) count(array_filter($testOutcomes[$uid] ?? []));
    $totalResults[$uid] = $passed . ' / ' . $totalTests;
}

$exportError = null;
$canExport = $mainError === null && $courseId > 0 && $users !== [] && $tests !== [];
if (($_GET['export'] ?? '') === 'xlsx') {
    try {
        if (!$canExport) {
            throw new RuntimeException('Selecteer een cursus met zichtbare resultaten om te exporteren.');
        }
        pecExportExcel($users, $tests, $matrix, $userErrors, $totalResults, $courseName, $groupName, $loadedAt, $showOpinion);
    } catch (Throwable $error) {
        $exportError = $error->getMessage();
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>PEC - eFront Test Results</title>
  <link rel="stylesheet" href="training.css?v=22">
</head>
<body class="admin-page report-page"><main>
  <div class="bar">
    <div>
      <h1>eFront testresultaten</h1>
      <div class="small">Weergave per cursus en groep, met tests naast elkaar en gebruikers onder elkaar.</div>
    </div>
    <nav class="actions" aria-label="Administration">
      <a class="button secondary" href="admin.php">Training administration</a>
      <a href="admin.php?logout=1">Sign out</a>
    </nav>
  </div>

  <p class="small">Laatste update: <?= pecH($loadedAt) ?></p>

  <?php if ($exportError !== null): ?>
    <div class="panel notice error"><?= pecH($exportError) ?></div>
  <?php endif; ?>
  <?php if ($canExport): ?>
    <div class="actions report-exports">
      <a class="button" href="?<?= pecH(http_build_query(array_merge($reportQuery, ['export' => 'xlsx']))) ?>">Export Excel</a>
      <button type="button" id="export-pdf">Export PDF</button>
      <span class="hint">PDF: kies 'Opslaan als PDF' in het afdrukvenster.</span>
    </div>
  <?php endif; ?>

  <div class="panel">
    <form method="get" id="report-form" class="form-layout">
      <div class="field report-filter-column">
        <label for="course-filter">Filter cursussen (bevat)</label>
        <input type="search" id="course-filter" name="filter" value="<?= pecH($filter) ?>" placeholder="Typ een deel van de cursusnaam..." autocomplete="off">
        <div class="hint" id="match-count" aria-live="polite"></div>
        <label for="course-select">Selecteer cursus</label>
        <select id="course-select" name="course_id">
          <option value="">Selecteer een cursus...</option>
          <?php foreach ($courses as $course): ?>
            <option value="<?= (int) $course['id'] ?>" <?= (int) $course['id'] === $courseId ? 'selected' : '' ?>><?= pecH($course['name'] ?? ('Course ' . $course['id'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field report-filter-column">
        <label for="group-filter">Filter groepen (bevat)</label>
        <input type="search" id="group-filter" name="group_filter" value="<?= pecH($groupFilter) ?>" placeholder="Typ een deel van de groepsnaam..." autocomplete="off">
        <div class="hint" id="group-match-count" aria-live="polite"></div>
        <label for="group-select">Filter op groep (optioneel)</label>
        <select id="group-select" name="group_id" <?= $groups === [] ? 'disabled' : '' ?>>
          <option value="">Alle cursusdeelnemers (geen groepsfilter)</option>
          <?php foreach ($groups as $group): ?>
            <option value="<?= (int) $group['id'] ?>" <?= (int) $group['id'] === $groupId ? 'selected' : '' ?>><?= pecH($group['name'] ?? ('Group ' . $group['id'])) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Een groep beperkt de lijst tot deelnemers die ook in de gekozen cursus zitten.</div>
      </div>

      <div class="field">
        <label for="user-order">Volgorde gebruikers</label>
        <select id="user-order" name="user_order">
          <?php foreach ($userOrderOptions as $value => $label): ?>
            <option value="<?= pecH($value) ?>" <?= $userOrder === $value ? 'selected' : '' ?>><?= pecH($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label class="inline-check"><input type="checkbox" name="show_opinion" value="1" <?= $showOpinion ? 'checked' : '' ?>> Toon ook “uw mening / votre avis”</label>
        <button type="submit" name="refresh" value="1">Vernieuwen</button>
      </div>
    </form>
  </div>

  <?php if ($groupListError !== null): ?>
    <div class="panel notice warning">De groepenlijst kon niet worden geladen. Zonder groepsfilter blijven alle cursusdeelnemers zichtbaar.
      <details><summary>Toon API-fout</summary><?= pecH($groupListError) ?></details>
    </div>
  <?php endif; ?>
  <?php if ($mainError !== null): ?>
    <div class="panel notice error"><?= pecH($mainError) ?></div>
  <?php elseif ($courseId === 0): ?>
    <div class="panel notice">Kies een cursus om alle cursusdeelnemers en hun testresultaten te tonen. Kies eventueel een groep om de deelnemers te filteren.</div>
  <?php else: ?>

      <div class="panel">
        <div><strong><?= pecH($groupName) ?></strong> / <?= pecH($courseName) ?></div>
        <div class="meta">
          <div><strong><?= count($users) ?></strong> gebruikers</div>
          <div><strong><?= count($tests) ?></strong> zichtbare tests</div>
          <div><strong><?= $visibleAttemptCount ?></strong> ingevulde cellen</div>
          <div><strong><?= $hiddenOpinionCount ?></strong> verborgen “mening/avis” resultaten</div>
          <div><strong><?= $apiErrorCount ?></strong> API-fouten</div>
        </div>
        <div class="legend">
          <span><i class="dot high"></i> 60% of hoger</span>
          <span><i class="dot low"></i> lager dan 60%</span>
          <span><i class="dot empty"></i> geen resultaat</span>
        </div>
        <div class="hint">Total = geslaagde tests (minstens 60% bij een poging) / maximaal aantal unieke tests per deelnemer in deze selectie. Herkansingen tellen niet dubbel.</div>
      </div>

      <?php if ($users === []): ?>
        <div class="panel notice">
          <?php if ($groupId > 0): ?>
            Geen resultaten: geen leden van <strong><?= pecH($groupName) ?></strong> zijn ingeschreven in <strong><?= pecH($courseName) ?></strong>. Kies een andere groep of 'Alle cursusdeelnemers' om de cursusleden te zien.
          <?php else: ?>
            Geen gebruikers ingeschreven in deze cursus.
          <?php endif; ?>
        </div>
      <?php elseif ($tests === []): ?>
        <div class="panel notice">Geen zichtbare testresultaten gevonden voor deze selectie. Mogelijk zijn alle resultaten weggefilterd als “uw mening / votre avis”.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="matrix">
            <thead>
              <tr>
                <th class="row-head" scope="col" aria-sort="<?= $userOrder === 'api' ? 'none' : ($descending ? 'descending' : 'ascending') ?>">
                  <a href="?<?= pecH(http_build_query(array_merge($reportQuery, ['user_order' => $nextUserOrder]))) ?>" title="Volgende sortering: <?= pecH($userOrderOptions[$nextUserOrder]) ?>">Gebruiker · <?= pecH($userOrderOptions[$userOrder]) ?></a>
                </th>
                <th class="total-cell" scope="col" title="Geslaagde unieke tests / maximaal aantal unieke tests per deelnemer">Total</th>
                <?php foreach ($tests as $testName): ?>
                  <th class="test-head"><span><?= pecH($testName) ?></span></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $user): ?>
                <?php $uid = (int) ($user['id'] ?? 0); ?>
                <tr>
                  <td class="row-head">
                    <div class="user-identity">
                      <span class="user-name" title="<?= pecH(pecUserName($user)) ?>"><?= pecH(pecUserName($user)) ?></span>
                      <span class="user-email" title="<?= pecH($user['email'] ?? ($user['login'] ?? '')) ?>"><?= pecH($user['email'] ?? ($user['login'] ?? '')) ?></span>
                    </div>
                    <?php if (isset($userErrors[$uid])): ?>
                      <div class="user-error">API-fout: <?= pecH($userErrors[$uid]) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="total-cell"><strong><?= pecH($totalResults[$uid]) ?></strong></td>
                  <?php foreach ($tests as $testName): ?>
                    <?php $attempt = $matrix[$uid][$testName] ?? null; ?>
                    <?php $class = 'score-cell ' . pecCellClass($attempt); ?>
                    <td class="<?= pecH($class) ?>" title="<?= $attempt !== null ? pecH(pecTooltip($attempt)) : 'Geen resultaat' ?>">
                      <span class="value"><?= pecH(pecDisplayScore($attempt['score'] ?? null)) ?></span>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
  <?php endif; ?>

<script>
(() => {
  // Split wide matrices into printable sections, repeating names and test headers.
  const matrix = document.querySelector('table.matrix');
  if (matrix) {
    const printable = document.createElement('div');
    printable.className = 'report-print-matrices';
    const fixedColumns = 2; // Repeat the participant and Total in every section.
    const testCount = matrix.tHead.rows[0].cells.length - fixedColumns;
    for (let start = fixedColumns; start < fixedColumns + testCount; start += 10) {
      const section = document.createElement('section');
      const heading = document.createElement('h2');
      heading.textContent = <?= json_encode($courseName . ' / ' . $groupName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
      section.append(heading);
      const table = matrix.cloneNode(true);
      for (const row of table.rows) {
        for (let i = row.cells.length - 1; i >= fixedColumns; i--) {
          if (i < start || i >= start + 10) row.deleteCell(i);
        }
      }
      section.append(table);
      printable.append(section);
    }
    matrix.closest('.table-wrap').after(printable);
  }
  document.getElementById('export-pdf')?.addEventListener('click', () => window.print());
  const form = document.getElementById('report-form');
  // Searching narrows dropdown choices without silently changing the active selection.
  function bindSearch(inputId, selectId, countId, noun) {
    const input = document.getElementById(inputId);
    const select = document.getElementById(selectId);
    const count = document.getElementById(countId);
    const placeholder = select.options[0].textContent;
    const original = [...select.options].slice(1).map(option => ({ value: option.value, name: option.textContent.trim() }));
    function applyFilter() {
      const needle = input.value.trim().toLocaleLowerCase();
      const selected = select.value;
      const matches = original.filter(item => item.name.toLocaleLowerCase().includes(needle));
      select.replaceChildren(new Option(placeholder, ''));
      for (const item of matches) select.add(new Option(item.name, item.value));
      const active = original.find(item => item.value === selected);
      if (active && !matches.some(item => item.value === selected)) {
        select.add(new Option(active.name + ' (geselecteerd)', active.value));
      }
      select.value = selected;
      count.textContent = matches.length + ' van ' + original.length + ' ' + noun;
    }
    input.addEventListener('input', applyFilter);
    select.addEventListener('change', () => form.submit());
    applyFilter();
  }
  bindSearch('course-filter', 'course-select', 'match-count', 'cursussen');
  bindSearch('group-filter', 'group-select', 'group-match-count', 'groepen');
  document.getElementById('user-order').addEventListener('change', () => form.submit());
})();
</script>
</main></body>
</html>
