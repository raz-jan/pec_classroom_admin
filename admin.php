<?php
declare(strict_types=1);

/*
 * PEC TRAINING ADMINISTRATION
 * PHP 8+, PDO MySQL and PhpSpreadsheet required.
 *
 * Features:
 * - Password-protected administration page
 * - List, add, inline edit and delete
 * - CSV/XLSX export
 * - CSV paste or CSV/XLSX upload
 * - Worksheet selection for Excel workbooks with multiple sheets
 * - Interactive import-column mapping
 * - Create/update import based on ID, friendly_url or meeting_id
 *
 * Configuration: root/.env (two levels above root/www/classroom/admin.php)
 * ADMIN_PASSWORD_HASH must contain password_hash('admin password', PASSWORD_DEFAULT).
 *
 * IMPORTANT: access_code is intentionally stored and displayed as plain text,
 * as requested. Protect this page and its configuration carefully.
 */

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

session_set_cookie_params([
    'httponly' => true,
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();

const COLUMNS = [
    'course'        => 'Course',
    'training_name' => 'Training name *',
    'training_date' => 'Training date',
    'meeting_id'    => 'Meeting ID *',
    'friendly_url'  => 'Friendly URL *',
    'access_code'   => 'Moderator Access Code *',
];
const COLUMN_HELP = [
    'course'        => 'Optional information used to group and filter trainings.',
    'training_name' => 'Required. The name displayed to participants in BigBlueButton.',
    'training_date' => 'Optional date and time used for planning and filtering (dd/mm/yyyy HH:mm).',
    'meeting_id'    => 'Required. The unique technical meeting identifier sent to BigBlueButton.',
    'friendly_url'  => 'Required. The URL-safe name used in the link to index.php.',
    'access_code'   => 'Required. Entering this code allows a participant to start and join the meeting as moderator.',
];
const EXPORT_COLUMNS = [
    'ID',
    'course',
    'training_name',
    'training_date',
    'meeting_id',
    'friendly_url',
    'access_code',
];
const MAX_IMPORT_ROWS = 5000;

/**
 * Load the root .env file without requiring an additional Composer package.
 */
function loadEnvFile(string $path): array
{
    if (!is_readable($path)) {
        throw new RuntimeException('The root .env file is missing or unreadable.');
    }

    $environment = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('The root .env file could not be read.');
    }

    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            throw new RuntimeException(
                'Invalid .env entry on line ' . ($lineNumber + 1) . '.'
            );
        }

        $key = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            throw new RuntimeException(
                'Invalid .env variable on line ' . ($lineNumber + 1) . '.'
            );
        }

        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"')
                || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        $environment[$key] = $value;
    }

    return $environment;
}

function envRequired(array $environment, string $key): string
{
    $value = trim((string) ($environment[$key] ?? ''));
    if ($value === '') {
        throw new RuntimeException("Missing required .env variable: {$key}");
    }
    return $value;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Keep email-template HTML safe while preserving common rich-text markup.
 * Plain-text templates from the previous version are converted automatically.
 */
function sanitizeEmailTemplateHtml(string $html): string
{
    if (trim($html) === '') {
        return '';
    }
    if (!preg_match('/<\/?[a-z][^>]*>/i', $html)) {
        return nl2br(e($html), false);
    }
    if (!class_exists('DOMDocument')) {
        return nl2br(e(strip_tags($html)), false);
    }

    $previousErrors = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="template-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    $root = $document->getElementById('template-root');
    if (!$root) {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        return '';
    }

    $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li', 'div'];
    $elements = [];
    foreach ($root->getElementsByTagName('*') as $element) {
        $elements[] = $element;
    }
    foreach (array_reverse($elements) as $element) {
        $tag = strtolower($element->nodeName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object'], true)) {
            $element->parentNode?->removeChild($element);
            continue;
        }
        if (!in_array($tag, $allowed, true)) {
            $parent = $element->parentNode;
            if ($parent) {
                while ($element->firstChild) {
                    $parent->insertBefore($element->firstChild, $element);
                }
                $parent->removeChild($element);
            }
            continue;
        }

        $href = $tag === 'a' ? trim($element->getAttribute('href')) : '';
        while ($element->attributes->length > 0) {
            $element->removeAttributeNode($element->attributes->item(0));
        }
        if ($tag === 'a'
            && ($href === '{{url}}'
                || preg_match('#^(https?://|mailto:)#i', $href))) {
            $element->setAttribute('href', $href);
        }
    }

    $clean = '';
    foreach ($root->childNodes as $child) {
        $clean .= $document->saveHTML($child);
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    return $clean;
}

function europeanDateTime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date !== false) {
            return $format === '!Y-m-d'
                ? $date->format('d/m/Y')
                : $date->format('d/m/Y H:i');
        }
    }
    return $value;
}

function columnHelp(string $column): string
{
    return COLUMN_HELP[$column] ?? '';
}

function configValue(array $config, string $key): string
{
    $value = $config[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("Missing configuration value: {$key}");
    }
    return trim($value);
}

function checkCsrf(): void
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['training_admin_csrf'] ?? ''), $submitted)) {
        throw new RuntimeException('The form expired. Refresh and try again.');
    }
}

function redirectSelf(): never
{
    $path = strtok((string) $_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $path);
    exit;
}

function queryUrl(array $changes = []): string
{
    $query = array_merge($_GET, $changes);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function validateRecord(array $values, bool $requireAll = true): array
{
    $clean = [];
    foreach (COLUMNS as $column => $_label) {
        $clean[$column] = trim((string) ($values[$column] ?? ''));
    }

    if ($requireAll && $clean['friendly_url'] === '') {
        throw new RuntimeException('Friendly URL is required.');
    }
    if ($clean['friendly_url'] !== ''
        && !preg_match('/^[A-Za-z0-9_-]{1,255}$/', $clean['friendly_url'])) {
        throw new RuntimeException('Friendly URL contains invalid characters.');
    }
    if ($requireAll && $clean['meeting_id'] === '') {
        throw new RuntimeException('Meeting ID is required.');
    }
    if ($clean['meeting_id'] !== '') {
        $meetingIdLength = function_exists('mb_strlen')
            ? mb_strlen($clean['meeting_id'], 'UTF-8')
            : strlen($clean['meeting_id']);

        /*
         * BBB accepts meeting IDs between 2 and 256 characters. Commas and
         * control characters are forbidden. Braces used around UUID values,
         * letters, numbers, hyphens and underscores are accepted and will be
         * URL-encoded when the BBB API URL is generated.
         */
        if (
            $meetingIdLength < 2
            || $meetingIdLength > 256
            || preg_match('/[\x00-\x1F\x7F,]/u', $clean['meeting_id'])
        ) {
            throw new RuntimeException(
                'Meeting ID must contain 2–256 characters and cannot contain '
                . 'commas or control characters.'
            );
        }
    }
    if ($requireAll && $clean['training_name'] === '') {
        throw new RuntimeException('Training name is required.');
    }
    if ($requireAll && $clean['access_code'] === '') {
        throw new RuntimeException('Moderator Access Code is required.');
    }
    if ($clean['training_date'] !== '') {
        $date = null;

        // Excel may provide a numeric serial instead of a formatted date.
        if (is_numeric($clean['training_date'])) {
            $date = DateTimeImmutable::createFromMutable(
                \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject(
                    (float) $clean['training_date']
                )
            );
        } else {
            /*
             * Text dates use ISO or unambiguous European day/month ordering,
             * optionally followed by a 24-hour time.
             * US m/d/Y is deliberately not guessed because values such as
             * 07/08/2026 cannot be interpreted safely.
             */
            foreach ([
                '!Y-m-d H:i:s',
                '!Y-m-d H:i',
                '!Y-m-d\TH:i:s',
                '!Y-m-d\TH:i',
                '!d/m/Y H:i:s',
                '!d/m/Y H:i',
                '!d-m-Y H:i:s',
                '!d-m-Y H:i',
                '!d.m.Y H:i:s',
                '!d.m.Y H:i',
                '!Y-m-d',
                '!d/m/Y',
                '!d-m-Y',
                '!d.m.Y',
            ] as $format) {
                $candidate = DateTimeImmutable::createFromFormat(
                    $format,
                    $clean['training_date']
                );
                $dateErrors = DateTimeImmutable::getLastErrors();
                $isStrictlyValid = $dateErrors === false
                    || (
                        $dateErrors['warning_count'] === 0
                        && $dateErrors['error_count'] === 0
                    );
                if ($candidate !== false && $isStrictlyValid) {
                    $date = $candidate;
                    break;
                }
            }
        }

        if (!$date) {
            throw new RuntimeException(
                'Training date must use dd/mm/yyyy HH:mm or YYYY-MM-DD HH:mm.'
            );
        }
        $clean['training_date'] = $date->format('Y-m-d H:i:s');
    } else {
        $clean['training_date'] = null;
    }

    return $clean;
}

function readCsv(string $content): array
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', trim($content)) ?? trim($content);
    if ($content === '') {
        throw new RuntimeException('The CSV input is empty.');
    }
    $first = preg_split('/\R/u', $content, 2)[0] ?? '';
    $counts = [
        ',' => substr_count($first, ','),
        ';' => substr_count($first, ';'),
        "\t" => substr_count($first, "\t"),
    ];
    arsort($counts);
    $delimiter = (string) array_key_first($counts);
    if (($counts[$delimiter] ?? 0) === 0) {
        throw new RuntimeException('Could not detect the CSV delimiter.');
    }

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
        if (count($row) === 1 && trim((string) $row[0]) === '') {
            continue;
        }
        $rows[] = array_map(static fn ($v) => trim((string) $v), $row);
        if (count($rows) > MAX_IMPORT_ROWS + 1) {
            throw new RuntimeException('The import contains too many rows.');
        }
    }
    fclose($stream);
    return $rows;
}

function workbookSheetNames(string $path, string $originalName): array
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xls'], true)) {
        throw new RuntimeException('Only XLSX and XLS workbooks have worksheets.');
    }

    $reader = IOFactory::createReaderForFile($path);
    $sheetNames = $reader->listWorksheetNames($path);
    if (!$sheetNames) {
        throw new RuntimeException('The Excel workbook does not contain a worksheet.');
    }
    return array_values(array_map('strval', $sheetNames));
}

function discardPendingImportWorkbook(): void
{
    $pending = $_SESSION['training_import_workbook'] ?? null;
    if (is_array($pending) && !empty($pending['path']) && is_file($pending['path'])) {
        @unlink((string) $pending['path']);
    }
    unset($_SESSION['training_import_workbook']);
}

function readSpreadsheetFile(
    string $path,
    string $originalName,
    ?string $sheetName = null
): array
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === 'csv') {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Could not read the uploaded CSV.');
        }
        return readCsv($content);
    }
    if (!in_array($extension, ['xlsx', 'xls'], true)) {
        throw new RuntimeException('Only CSV, XLSX and XLS files are supported.');
    }

    $reader = IOFactory::createReaderForFile($path);
    if ($sheetName !== null) {
        $reader->setLoadSheetsOnly($sheetName);
    }
    $spreadsheet = $reader->load($path);
    $worksheet = $sheetName === null
        ? $spreadsheet->getActiveSheet()
        : $spreadsheet->getSheetByName($sheetName);
    if ($worksheet === null) {
        throw new RuntimeException('The selected worksheet could not be read.');
    }
    /*
     * Do not return Excel's formatted display text here. A formatted date such
     * as 07/08/2026 is ambiguous and can swap day and month. With formatData
     * set to false, genuine Excel dates remain numeric serial values; these are
     * converted explicitly in validateRecord().
     */
    $rows = $worksheet->toArray('', true, false, false);

    /*
     * Explicitly recognise Excel date/time cells. This preserves the
     * fractional part of Excel's serial value (the time of day), even when
     * the worksheet's display format differs from the server locale.
     */
    foreach ($rows as $rowIndex => &$row) {
        foreach ($row as $columnIndex => &$value) {
            $coordinate =
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $columnIndex + 1
                )
                . ($rowIndex + 1);
            $cell = $worksheet->getCell($coordinate);

            if (
                is_numeric($cell->getValue())
                && \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)
            ) {
                $value = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject(
                    (float) $cell->getValue()
                )
                    ->format('Y-m-d H:i:s');
            }
        }
        unset($value);
    }
    unset($row);
    if (count($rows) > MAX_IMPORT_ROWS + 1) {
        throw new RuntimeException('The import contains too many rows.');
    }
    return array_map(
        static fn (array $row): array =>
            array_map(static fn ($v): string => trim((string) $v), $row),
        $rows
    );
}

function normalizeImportRows(array $rows): array
{
    while ($rows && count(array_filter($rows[0], static fn ($v) => $v !== '')) === 0) {
        array_shift($rows);
    }
    if (count($rows) < 2) {
        throw new RuntimeException('The import requires a header and at least one row.');
    }
    $headers = array_map(
        static fn ($v): string => trim((string) $v),
        array_shift($rows)
    );
    if (count(array_filter($headers, static fn ($v) => $v !== '')) === 0) {
        throw new RuntimeException('The imported header is empty.');
    }
    return ['headers' => $headers, 'rows' => array_values($rows)];
}

function pdoFromConfig(array $config): PDO
{
    return new PDO(
        'mysql:host=' . configValue($config, 'db_host')
        . ';dbname=' . configValue($config, 'db_name')
        . ';charset=utf8mb4',
        configValue($config, 'db_user'),
        configValue($config, 'db_password'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

$isRecordingRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array((string) ($_POST['action'] ?? ''), ['recordings_list_ajax', 'recording_details_ajax', 'delete_recording_ajax'], true);

$error = null;
$notice = $_SESSION['training_admin_notice'] ?? null;
unset($_SESSION['training_admin_notice']);
$importReportErrors = $_SESSION['training_admin_import_errors'] ?? [];
unset($_SESSION['training_admin_import_errors']);
$mappingData = $_SESSION['training_import_data'] ?? null;
$sheetSelectionData = $_SESSION['training_import_workbook'] ?? null;
$rows = [];
$courses = [];
$totalRows = 0;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$sort = (string) ($_GET['sort'] ?? 'training_date');
$direction = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$courseFilter = trim((string) ($_GET['course'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$defaultMailNl = <<<'MAIL'
Onderwerp: Online opleiding {{training_name}}

Beste,

Hierbij ontvangt u de link voor de online opleiding "{{training_name}}".
Opleiding: {{course}}
{{training_date}}

Link: {{url}}
Moderator-toegangscode: {{access_code}}

Met vriendelijke groeten,
Patient Expert Center
MAIL;
$defaultMailFr = <<<'MAIL'
Objet : Formation en ligne {{training_name}}

Bonjour,

Voici le lien pour la formation en ligne « {{training_name}} ».
Formation : {{course}}
{{training_date}}

Lien : {{url}}
Code d’accès modérateur : {{access_code}}

Bien cordialement,
Patient Expert Center
MAIL;
$mailTemplates = [
    'nl' => sanitizeEmailTemplateHtml($defaultMailNl),
    'fr' => sanitizeEmailTemplateHtml($defaultMailFr),
];

try {
    $envFile = dirname(__DIR__, 2) . '/.env';
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_readable($autoload)) {
        throw new RuntimeException('PhpSpreadsheet autoloader not found in vendor/.');
    }
    require_once $autoload;
    $environment = loadEnvFile($envFile);
    $config = [
        'db_host'             => envRequired($environment, 'DB_HOST'),
        'db_name'             => envRequired($environment, 'DB_NAME'),
        'db_user'             => envRequired($environment, 'DB_USER'),
        'db_password'         => envRequired($environment, 'DB_PASSWORD'),
        'import_password_hash'=> envRequired($environment, 'ADMIN_PASSWORD_HASH'),
    ];
    $adminHash = configValue($config, 'import_password_hash');

    if (empty($_SESSION['training_admin_csrf'])) {
        $_SESSION['training_admin_csrf'] = bin2hex(random_bytes(32));
    }

    if (isset($_GET['logout'])) {
        discardPendingImportWorkbook();
        unset($_SESSION['training_admin_authorized'], $_SESSION['training_import_data']);
        redirectSelf();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        checkCsrf();
        if (password_verify((string) $_POST['admin_password'], $adminHash)) {
            session_regenerate_id(true);
            $_SESSION['training_admin_authorized'] = true;
            // Only allow the known sibling page as a post-login destination.
            if (($_GET['return'] ?? '') === 'test-results.php') {
                header('Location: test-results.php');
                exit;
            }
            redirectSelf();
        }
        $error = 'Incorrect password.';
    }

    if ($isRecordingRequest && empty($_SESSION['training_admin_authorized'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['success' => false, 'message' => 'Please sign in again to view recordings.']);
        exit;
    }

    if (!empty($_SESSION['training_admin_authorized'])) {
        $pdo = pdoFromConfig($config);
        if ($isRecordingRequest) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
            try {
                checkCsrf();
                // Release the session lock while BBB requests are in progress.
                session_write_close();
                require_once __DIR__ . '/admin-recordings.php';
                $payload = recordingAdminRequest($pdo, $environment, (string) $_POST['action']);
                echo json_encode(['success' => true] + $payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (Throwable $recordingError) {
                error_log('Training recordings: ' . $recordingError->getMessage());
                http_response_code(502);
                echo json_encode(['success' => false, 'message' => $recordingError->getMessage()],
                    JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            exit;
        }
        $templateRows = $pdo->query(
            "SELECT language, email_template
             FROM training_email_templates
             WHERE language IN ('nl', 'fr')"
        )->fetchAll();
        foreach ($templateRows as $templateRow) {
            $templateLanguage = (string) $templateRow['language'];
            if (isset($mailTemplates[$templateLanguage])) {
                $mailTemplates[$templateLanguage] =
                    sanitizeEmailTemplateHtml(
                        (string) $templateRow['email_template']
                    );
            }
        }
        $action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

        /*
         * Row-level autosave endpoint. It returns JSON so a validation or
         * database error can be shown beside the row without reloading the
         * complete administration page.
         */
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_row_ajax') {
            header('Content-Type: application/json; charset=UTF-8');
            try {
                checkCsrf();
                $id = (int) ($_POST['ID'] ?? 0);
                if ($id < 1) {
                    throw new RuntimeException('Invalid training row.');
                }

                $clean = validateRecord((array) ($_POST['row'] ?? []));
                $clean['ID'] = $id;
                $statement = $pdo->prepare(
                    'UPDATE trainings SET course=:course,
                     training_name=:training_name, training_date=:training_date,
                     meeting_id=:meeting_id, friendly_url=:friendly_url,
                     access_code=:access_code WHERE ID=:ID'
                );
                $statement->execute($clean);
                echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
            } catch (Throwable $ajaxError) {
                http_response_code(422);
                error_log('Training autosave: ' . $ajaxError->getMessage());
                echo json_encode(
                    ['success' => false, 'message' => $ajaxError->getMessage()],
                    JSON_THROW_ON_ERROR
                );
            }
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST'
            && $action === 'save_mail_template_ajax') {
            header('Content-Type: application/json; charset=UTF-8');
            try {
                checkCsrf();
                $templateLanguage = strtolower(trim((string) (
                    $_POST['language'] ?? ''
                )));
                if (!in_array($templateLanguage, ['nl', 'fr'], true)) {
                    throw new RuntimeException('Invalid template language.');
                }
                $emailTemplate = sanitizeEmailTemplateHtml(
                    (string) ($_POST['email_template'] ?? '')
                );
                if (strlen($emailTemplate) > 50000) {
                    throw new RuntimeException('The email template is too long.');
                }

                $statement = $pdo->prepare(
                    'INSERT INTO training_email_templates
                        (language, email_template)
                     VALUES (:language, :email_template)
                     ON DUPLICATE KEY UPDATE
                        email_template=VALUES(email_template)'
                );
                $statement->execute([
                    'language' => $templateLanguage,
                    'email_template' => $emailTemplate,
                ]);
                echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
            } catch (Throwable $templateError) {
                http_response_code(422);
                error_log(
                    'Training email template save: '
                    . $templateError->getMessage()
                );
                echo json_encode(
                    [
                        'success' => false,
                        'message' => $templateError->getMessage(),
                    ],
                    JSON_THROW_ON_ERROR
                );
            }
            exit;
        }

        if ($action === 'export_csv' || $action === 'export_xlsx') {
            $exportRows = $pdo->query(
                'SELECT ID, course, training_name, training_date, meeting_id,
                        friendly_url, access_code
                 FROM trainings ORDER BY training_date, training_name'
            )->fetchAll();
            $filename = 'bbb-trainings-' . date('Y-m-d');

            if ($action === 'export_csv') {
                header('Content-Type: text/csv; charset=UTF-8');
                header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, EXPORT_COLUMNS, ';');
                foreach ($exportRows as $row) {
                    fputcsv($out, array_values($row), ';');
                }
                fclose($out);
                exit;
            }

            $book = new Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('Trainings');
            $sheet->fromArray(EXPORT_COLUMNS, null, 'A1');
            if ($exportRows) {
                $sheet->fromArray(
                    array_map('array_values', $exportRows),
                    null,
                    'A2'
                );
            }
            $sheet->getStyle('A1:G1')->getFont()->setBold(true);
            $sheet->setAutoFilter('A1:G' . max(1, count($exportRows) + 1));
            foreach (range('A', 'G') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header("Content-Disposition: attachment; filename=\"{$filename}.xlsx\"");
            (new Xlsx($book))->save('php://output');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
            checkCsrf();

            if ($action === 'save_rows') {
                $statement = $pdo->prepare(
                    'UPDATE trainings SET course=:course,
                     training_name=:training_name, training_date=:training_date,
                     meeting_id=:meeting_id, friendly_url=:friendly_url,
                     access_code=:access_code WHERE ID=:ID'
                );
                $saved = 0;
                foreach ((array) ($_POST['rows'] ?? []) as $id => $values) {
                    $clean = validateRecord((array) $values);
                    $clean['ID'] = (int) $id;
                    $statement->execute($clean);
                    $saved++;
                }
                $_SESSION['training_admin_notice'] = "{$saved} row(s) saved.";
                redirectSelf();
            }

            if ($action === 'add_row') {
                $clean = validateRecord((array) ($_POST['new'] ?? []));
                $statement = $pdo->prepare(
                    'INSERT INTO trainings (course, training_name,
                     training_date, meeting_id, friendly_url, access_code)
                     VALUES (:course, :training_name, :training_date,
                     :meeting_id, :friendly_url, :access_code)'
                );
                $statement->execute($clean);
                $_SESSION['training_admin_notice'] = 'Training created.';
                redirectSelf();
            }

            if ($action === 'delete_row') {
                $statement = $pdo->prepare('DELETE FROM trainings WHERE ID=:ID');
                $statement->execute(['ID' => (int) ($_POST['ID'] ?? 0)]);
                $_SESSION['training_admin_notice'] = 'Training deleted.';
                redirectSelf();
            }

            if ($action === 'delete_selected') {
                $selectedIds = array_values(array_unique(array_filter(
                    array_map('intval', (array) ($_POST['selected_ids'] ?? [])),
                    static fn (int $id): bool => $id > 0
                )));
                if (!$selectedIds) {
                    $_SESSION['training_admin_notice'] = 'No trainings selected.';
                    redirectSelf();
                }

                $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                $statement = $pdo->prepare(
                    "DELETE FROM trainings WHERE ID IN ({$placeholders})"
                );
                $statement->execute($selectedIds);
                $_SESSION['training_admin_notice'] = count($selectedIds)
                    . ' selected training(s) deleted.';
                redirectSelf();
            }

            if ($action === 'delete_all') {
                $deleted = $pdo->exec('DELETE FROM trainings');
                $_SESSION['training_admin_notice'] = (int) $deleted
                    . ' training(s) deleted.';
                redirectSelf();
            }

            if ($action === 'parse_import') {
                discardPendingImportWorkbook();
                unset($_SESSION['training_import_data']);
                $sheetSelectionData = null;
                $mappingData = null;
                $csvText = trim((string) ($_POST['csv_data'] ?? ''));
                if ($csvText !== '') {
                    $importRows = readCsv($csvText);
                } elseif (!empty($_FILES['import_file']['tmp_name'])) {
                    if ((int) $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('File upload failed.');
                    }
                    $uploadedPath = (string) $_FILES['import_file']['tmp_name'];
                    $uploadedName = (string) $_FILES['import_file']['name'];
                    $extension = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
                    if ($extension === 'csv') {
                        $importRows = readSpreadsheetFile($uploadedPath, $uploadedName);
                    } elseif (in_array($extension, ['xlsx', 'xls'], true)) {
                        $temporaryPath = tempnam(sys_get_temp_dir(), 'pec_classroom_import_');
                        if ($temporaryPath === false
                            || !move_uploaded_file($uploadedPath, $temporaryPath)) {
                            throw new RuntimeException('Could not temporarily store the Excel workbook.');
                        }
                        try {
                            $sheetNames = workbookSheetNames($temporaryPath, $uploadedName);
                            if (count($sheetNames) > 1) {
                                $sheetSelectionData = [
                                    'path' => $temporaryPath,
                                    'name' => $uploadedName,
                                    'sheets' => $sheetNames,
                                ];
                                $_SESSION['training_import_workbook'] = $sheetSelectionData;
                                $importRows = null;
                            } else {
                                $importRows = readSpreadsheetFile(
                                    $temporaryPath,
                                    $uploadedName,
                                    $sheetNames[0]
                                );
                                @unlink($temporaryPath);
                            }
                        } catch (Throwable $workbookError) {
                            @unlink($temporaryPath);
                            throw $workbookError;
                        }
                    } else {
                        throw new RuntimeException('Only CSV, XLSX and XLS files are supported.');
                    }
                } else {
                    throw new RuntimeException('Paste CSV or select a file.');
                }
                if (is_array($importRows)) {
                    $mappingData = normalizeImportRows($importRows);
                    $_SESSION['training_import_data'] = $mappingData;
                }
            }

            if ($action === 'select_import_sheet') {
                $pending = $_SESSION['training_import_workbook'] ?? null;
                if (!is_array($pending)
                    || empty($pending['path'])
                    || empty($pending['name'])
                    || empty($pending['sheets'])) {
                    throw new RuntimeException('The uploaded workbook expired. Please upload it again.');
                }
                $selectedSheet = (string) ($_POST['sheet_name'] ?? '');
                if (!in_array($selectedSheet, (array) $pending['sheets'], true)) {
                    throw new RuntimeException('Select a valid worksheet.');
                }
                try {
                    $importRows = readSpreadsheetFile(
                        (string) $pending['path'],
                        (string) $pending['name'],
                        $selectedSheet
                    );
                    $mappingData = normalizeImportRows($importRows);
                    $_SESSION['training_import_data'] = $mappingData;
                } finally {
                    discardPendingImportWorkbook();
                    $sheetSelectionData = null;
                }
            }

            if ($action === 'run_import') {
                $mappingData = $_SESSION['training_import_data'] ?? null;
                if (!is_array($mappingData)) {
                    throw new RuntimeException('Import preview expired.');
                }
                // Mapping direction: imported source index => SQL destination.
                $mapping = (array) ($_POST['mapping'] ?? []);
                $matchBy = (string) ($_POST['match_by'] ?? 'friendly_url');
                if (!in_array($matchBy, ['friendly_url', 'meeting_id'], true)) {
                    throw new RuntimeException('Invalid matching column.');
                }

                /*
                 * A MySQL DATE column silently removes the time. Stop the
                 * import with a useful instruction instead of storing 00:00.
                 */
                if (in_array('training_date', $mapping, true)) {
                    $dateColumn = $pdo->query(
                        "SHOW COLUMNS FROM trainings LIKE 'training_date'"
                    )->fetch();
                    $sqlType = strtolower((string) ($dateColumn['Type'] ?? ''));

                    if (
                        !str_starts_with($sqlType, 'datetime')
                        && !str_starts_with($sqlType, 'timestamp')
                    ) {
                        throw new RuntimeException(
                            'The SQL training_date column must be DATETIME. '
                            . 'Run: ALTER TABLE trainings MODIFY '
                            . 'training_date DATETIME NULL;'
                        );
                    }
                }

                $created = $updated = $skipped = 0;
                $rowErrors = [];
                foreach ($mappingData['rows'] as $rowIndex => $sourceRow) {
                    // +2 because spreadsheet row 1 contains the column headers.
                    $sourceLine = $rowIndex + 2;
                    $record = array_fill_keys(array_keys(COLUMNS), '');
                    foreach ($mapping as $sourceIndex => $target) {
                        if ($target !== '' && array_key_exists($target, COLUMNS)) {
                            $record[$target] = (string) ($sourceRow[(int) $sourceIndex] ?? '');
                        }
                    }
                    try {
                        $clean = validateRecord($record);
                        $matchValue = (string) $clean[$matchBy];
                        if ($matchValue === '') {
                            throw new RuntimeException("Empty {$matchBy} value.");
                        }
                        $find = $pdo->prepare(
                            "SELECT ID FROM trainings WHERE `{$matchBy}`=:value LIMIT 1"
                        );
                        $find->execute(['value' => $matchValue]);
                        $existingId = $find->fetchColumn();

                        if ($existingId) {
                            $clean['ID'] = (int) $existingId;
                            $update = $pdo->prepare(
                                'UPDATE trainings SET course=:course,
                                 training_name=:training_name,
                                 training_date=:training_date,
                                 meeting_id=:meeting_id,
                                 friendly_url=:friendly_url,
                                 access_code=:access_code WHERE ID=:ID'
                            );
                            $update->execute($clean);
                            $updated++;
                        } else {
                            $insert = $pdo->prepare(
                                'INSERT INTO trainings (course, training_name,
                                 training_date, meeting_id, friendly_url, access_code)
                                 VALUES (:course, :training_name, :training_date,
                                 :meeting_id, :friendly_url, :access_code)'
                            );
                            $insert->execute($clean);
                            $created++;
                        }
                    } catch (Throwable $rowError) {
                        $skipped++;
                        $rowErrors[] = "Row {$sourceLine}: " . $rowError->getMessage();
                        error_log(
                            "Training import row {$sourceLine} skipped: "
                            . $rowError->getMessage()
                        );
                    }
                }
                unset($_SESSION['training_import_data']);
                $_SESSION['training_admin_import_errors'] = $rowErrors;
                $_SESSION['training_admin_notice'] =
                    "Import complete: {$created} created, {$updated} updated, {$skipped} skipped.";
                redirectSelf();
            }
        }

        /*
         * Server-side filters, sorting and pagination. Only whitelisted column
         * names can enter ORDER BY; filter values remain bound parameters.
         */
        $allowedSorts = array_merge(['ID'], array_keys(COLUMNS));
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'training_date';
        }
        $where = [];
        $filterParameters = [];
        if ($courseFilter !== '') {
            $where[] = 'course = :course';
            $filterParameters['course'] = $courseFilter;
        }
        if ($dateFrom !== '') {
            $where[] = 'training_date >= :date_from';
            $filterParameters['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 'training_date <= :date_to';
            $filterParameters['date_to'] = $dateTo . ' 23:59:59';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM trainings' . $whereSql
        );
        $countStatement->execute($filterParameters);
        $totalRows = (int) $countStatement->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $listStatement = $pdo->prepare(
            'SELECT ID, course, training_name, training_date, meeting_id,
                    friendly_url, access_code
             FROM trainings' . $whereSql
            . " ORDER BY `{$sort}` {$direction}, ID ASC"
            . ' LIMIT :limit OFFSET :offset'
        );
        foreach ($filterParameters as $key => $value) {
            $listStatement->bindValue(':' . $key, $value);
        }
        $listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $listStatement->execute();
        $rows = $listStatement->fetchAll();
        $courses = $pdo->query(
            "SELECT DISTINCT course FROM trainings
             WHERE course IS NOT NULL AND course <> '' ORDER BY course"
        )->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $exception) {
    error_log('BBB training admin: ' . $exception->getMessage());
    if ($isRecordingRequest) {
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['success' => false, 'message' => 'Recording availability could not be checked. Please retry.']);
        exit;
    }
    $error = $exception->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PEC training administration</title>
<link rel="stylesheet" href="training.css?v=23">
</head>
<body class="admin-page"><main>
<?php if (empty($_SESSION['training_admin_authorized'])): ?>
<div class="login"><h1>Training administration</h1>
<?php if ($error): ?><p class="msg err"><?=e($error)?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=e((string)($_SESSION['training_admin_csrf']??''))?>">
<label>Administration password<input type="password" name="admin_password" required autofocus></label>
<button type="submit">Sign in</button>
</form></div>
<?php else: ?>
<div class="bar"><h1>PEC trainings</h1><div class="actions">
<a class="button secondary" href="test-results.php">Test results</a>
<a href="?logout=1">Sign out</a>
</div></div>
<?php if ($notice): ?><p class="msg ok"><?=e((string)$notice)?></p><?php endif; ?>
<?php if ($error): ?><p class="msg err"><?=e($error)?></p><?php endif; ?>
<?php if ($importReportErrors): ?><div class="msg err">
<strong>Skipped-row details:</strong><ul>
<?php foreach($importReportErrors as $rowError): ?><li><?=e((string)$rowError)?></li><?php endforeach; ?>
</ul></div><?php endif; ?>

<div class="admin-tools" aria-label="Training actions">
<button type="button" data-open-modal="add-training-modal">Add one training</button>
<button type="button" data-open-modal="import-training-modal">Import trainings</button>
<button class="secondary" type="button" data-open-modal="mail-template-nl-modal">Update NL mail</button>
<button class="secondary" type="button" data-open-modal="mail-template-fr-modal">Update FR mail</button>
<button class="secondary" type="button" data-open-modal="export-training-modal">Export Excel or CSV</button>
</div>

<form class="filters" method="get" id="filter-form">
<label>Course<select name="course" data-auto-filter><option value="">All courses</option>
<?php foreach($courses as $course): ?><option value="<?=e((string)$course)?>" <?=$courseFilter===(string)$course?'selected':''?>><?=e((string)$course)?></option><?php endforeach; ?>
</select></label>
<label>Date from<input type="date" name="date_from" value="<?=e($dateFrom)?>" data-auto-filter></label>
<label>Date to<input type="date" name="date_to" value="<?=e($dateTo)?>" data-auto-filter></label>
<div><button type="submit" id="filter-button">Filter</button> <a href="<?=e(strtok((string)$_SERVER['REQUEST_URI'],'?'))?>">Clear</a></div>
</form>

<form method="post" id="bulk-delete-form" class="bulk-actions">
<input type="hidden" name="csrf_token" value="<?=e((string)$_SESSION['training_admin_csrf'])?>">
<button class="danger" type="submit" name="action" value="delete_selected" id="delete-selected" disabled>Delete selected (0)</button>
<button class="danger outline" type="submit" name="action" value="delete_all" id="delete-all">Delete all trainings</button>
</form>

<form method="post" id="training-rows-form">
<input type="hidden" name="csrf_token" value="<?=e((string)$_SESSION['training_admin_csrf'])?>">
<input type="hidden" name="action" value="save_rows">
<p class="required-note">* Required for the meeting to work. Hover over a column name for details.</p>
<div class="scroll"><table><thead><tr>
<th class="selection-cell"><input type="checkbox" id="select-all-visible" title="Select all trainings on this page" aria-label="Select all trainings on this page"></th>
<?php foreach(COLUMNS as $column=>$label):
$nextDirection=$sort===$column&&$direction==='asc'?'desc':'asc';
$indicator=$sort===$column?($direction==='asc'?' ▲':' ▼'):''; ?>
<th class="field-help" title="<?=e(columnHelp($column))?>"><a href="<?=e(queryUrl(['sort'=>$column,'dir'=>$nextDirection,'page'=>1]))?>"><?=e($label.$indicator)?></a></th>
<?php endforeach; ?>
<th></th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr data-row-id="<?=$row['ID']?>">
<td class="selection-cell"><input class="row-select" type="checkbox" name="selected_ids[]" value="<?=$row['ID']?>" form="bulk-delete-form" aria-label="Select <?=e((string)$row['training_name'])?>"></td>
<?php foreach(COLUMNS as $column=>$label): ?><td>
<input type="text" title="<?=e(columnHelp($column))?>" name="rows[<?=$row['ID']?>][<?=$column?>]" value="<?=e($column==='training_date'?europeanDateTime((string)($row[$column]??'')):(string)($row[$column]??''))?>" <?=$column==='training_date'?'placeholder="dd/mm/yyyy hh:mm"':''?> <?=$column==='meeting_id'?'readonly':''?>>
</td><?php endforeach; ?><td>
<div class="row-actions">
<button class="secondary copy-link" type="button" data-slug="<?=e((string)$row['friendly_url'])?>">Copy link</button>
<button class="secondary copy-mail" type="button" data-language="nl" title="Copy complete Dutch email">✉ NL</button>
<button class="secondary copy-mail" type="button" data-language="fr" title="Copy complete French email">✉ FR</button>
<span class="autosave-status" role="status" aria-live="polite"></span>
<button class="secondary undo-row" type="button" title="Undo all changes in this row" aria-label="Undo all changes in this row" disabled>↶</button>
<button class="secondary recordings-button" type="button" data-training-id="<?=$row['ID']?>" disabled>Checking…</button>
<button class="danger delete-icon" type="submit" form="delete-<?=$row['ID']?>" onclick="return confirm('Delete this training?')" title="Delete training" aria-label="Delete training">🗑</button>
</div>
</td></tr><?php endforeach; ?>
</tbody></table></div><button type="submit">Save all changes</button></form>
<?php $totalPages=max(1,(int)ceil($totalRows/$perPage)); if($totalPages>1): ?>
<nav class="pages" aria-label="Pagination">
<?php if($page>1): ?><a href="<?=e(queryUrl(['page'=>$page-1]))?>">Previous</a><?php endif; ?>
<?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++): ?>
<?php if($p===$page): ?><span class="current"><?=$p?></span><?php else: ?><a href="<?=e(queryUrl(['page'=>$p]))?>"><?=$p?></a><?php endif; ?>
<?php endfor; ?>
<?php if($page<$totalPages): ?><a href="<?=e(queryUrl(['page'=>$page+1]))?>">Next</a><?php endif; ?>
</nav><?php endif; ?>
<?php foreach($rows as $row): ?><form id="delete-<?=$row['ID']?>" method="post">
<input type="hidden" name="csrf_token" value="<?=e((string)$_SESSION['training_admin_csrf'])?>">
<input type="hidden" name="action" value="delete_row"><input type="hidden" name="ID" value="<?=$row['ID']?>">
</form><?php endforeach; ?>

<div class="modal-backdrop" id="recordings-modal" hidden><section class="modal modal-wide recordings-dialog" role="dialog" aria-modal="true" aria-labelledby="recordings-title" aria-describedby="recordings-meeting">
<div class="modal-head"><h2 id="recordings-title">Recordings</h2><button type="button" class="close" data-close-modal aria-label="Close">×</button></div>
<div class="recordings-toolbar"><p id="recordings-meeting"></p><div><span id="recordings-count" role="status"></span><button class="secondary" type="button" id="recordings-refresh">↻ Refresh</button></div></div>
<p class="recording-muted">Times shown in Europe/Brussels. Video links open the actual media file; your browser may play it instead of downloading it.</p>
<p id="recording-delete-notice" role="status" aria-live="polite" hidden></p>
<div id="recordings-list" aria-live="polite"></div>
<div class="modal-actions"><button class="secondary" type="button" data-close-modal>Close</button></div>
</section></div>

<div class="modal-backdrop" id="export-training-modal" hidden><section class="modal" role="dialog" aria-modal="true" aria-labelledby="export-training-title">
<div class="modal-head"><h2 id="export-training-title">Export trainings</h2><button type="button" class="close" data-close-modal aria-label="Close">×</button></div>
<p>Choose the required file format.</p>
<div class="modal-actions"><a class="button" href="?action=export_xlsx">Export Excel</a><a class="button secondary" href="?action=export_csv">Export CSV</a></div>
</section></div>

<?php foreach(['nl'=>'Dutch email (NL)','fr'=>'French email (FR)'] as $templateLanguage=>$templateLabel): ?>
<div class="modal-backdrop" id="mail-template-<?=e($templateLanguage)?>-modal" data-mail-language="<?=e($templateLanguage)?>" hidden><section class="modal modal-wide mail-templates" role="dialog" aria-modal="true" aria-labelledby="mail-template-<?=e($templateLanguage)?>-title">
<div class="modal-head"><h2 id="mail-template-<?=e($templateLanguage)?>-title"><?=e($templateLabel)?></h2><button type="button" class="close" data-close-modal aria-label="Close">×</button></div>
<p class="required-note">Available placeholders: <code>{{url}}</code>, <code>{{access_code}}</code>, <code>{{training_name}}</code>, <code>{{course}}</code> and <code>{{training_date}}</code>. Click <strong>Save &amp; close</strong> to store changes in the database.</p>
<div class="rich-editor-block">
<div class="rich-editor-label">Email content <span class="template-status" data-language="<?=e($templateLanguage)?>" role="status"></span></div>
<div class="rich-toolbar" data-editor="mail-template-<?=e($templateLanguage)?>" role="toolbar" aria-label="Formatting tools">
<button type="button" data-command="bold" title="Bold"><strong>B</strong></button>
<button type="button" data-command="italic" title="Italic"><em>I</em></button>
<button type="button" data-command="underline" title="Underline"><u>U</u></button>
<button type="button" data-command="insertUnorderedList" title="Bullet list">• List</button>
<button type="button" data-command="insertOrderedList" title="Numbered list">1. List</button>
<button type="button" data-command="createLink" title="Add hyperlink">🔗 Link</button>
<button type="button" data-command="unlink" title="Remove hyperlink">Unlink</button>
</div>
<div id="mail-template-<?=e($templateLanguage)?>" class="rich-editor-content" contenteditable="true" role="textbox" aria-multiline="true" aria-label="<?=e($templateLabel)?>"><?=$mailTemplates[$templateLanguage]?></div>
</div>
<div class="modal-actions"><button class="secondary" type="button" data-close-modal>Close</button><button type="button" class="save-mail-template" data-language="<?=e($templateLanguage)?>">Save &amp; close</button></div>
</section></div>
<?php endforeach; ?>

<div class="modal-backdrop" id="add-training-modal" hidden><section class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="add-training-title">
<div class="modal-head"><h2 id="add-training-title">Add one training</h2><button type="button" class="close" data-close-modal aria-label="Close">×</button></div>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=e((string)$_SESSION['training_admin_csrf'])?>">
<input type="hidden" name="action" value="add_row">
<p class="required-note">* Required for the meeting to work. Hover over a field name for details.</p>
<table><tr><?php foreach(COLUMNS as $column=>$label): ?><td><label class="field-help" title="<?=e(columnHelp($column))?>"><?=e($label)?>
<input type="text" title="<?=e(columnHelp($column))?>" name="new[<?=$column?>]" <?=$column==='training_date'?'placeholder="dd/mm/yyyy hh:mm"':''?> <?=$column==='course'||$column==='training_date'?'':'required'?>>
</label></td><?php endforeach; ?></tr></table><button type="submit">Add training</button>
</form></section></div>

<div class="modal-backdrop" id="import-training-modal" hidden><section class="modal" role="dialog" aria-modal="true" aria-labelledby="import-training-title">
<div class="modal-head"><h2 id="import-training-title">Import trainings</h2><button type="button" class="close" data-close-modal aria-label="Close">×</button></div>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?=e((string)$_SESSION['training_admin_csrf'])?>">
<input type="hidden" name="action" value="parse_import">
<label>Excel or CSV file<input type="file" name="import_file" accept=".xlsx,.xls,.csv"></label>
<p><strong>or paste CSV</strong></p>
<textarea name="csv_data" placeholder="course;training_name;training_date;meeting_id;friendly_url;access_code"></textarea>
<button type="submit">Read and map columns</button></form>
</section></div>

<?php if (is_array($sheetSelectionData) && !empty($sheetSelectionData['sheets'])): ?>
<div class="modal-backdrop" id="sheet-selection-modal"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="sheet-selection-title">
<div class="modal-head"><h2 id="sheet-selection-title">Select worksheet</h2><button type="button" class="close" data-close-modal aria-label="Close">×</button></div>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=e((string)$_SESSION['training_admin_csrf'])?>">
<input type="hidden" name="action" value="select_import_sheet">
<p>This Excel file contains multiple worksheets. Select the worksheet containing the trainings.</p>
<label>Worksheet<select name="sheet_name" required autofocus>
<option value="">Select a worksheet</option>
<?php foreach ((array)$sheetSelectionData['sheets'] as $sheetName): ?><option value="<?=e((string)$sheetName)?>"><?=e((string)$sheetName)?></option><?php endforeach; ?>
</select></label>
<button type="submit">Read selected worksheet</button>
</form></section></div>
<?php endif; ?>

<?php if (is_array($mappingData)): ?><div class="modal-backdrop" id="mapping-modal"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="mapping-title">
<div class="modal-head"><h2 id="mapping-title">Map imported columns</h2><button type="button" class="close" data-close-modal aria-label="Close">×</button></div>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=e((string)$_SESSION['training_admin_csrf'])?>">
<input type="hidden" name="action" value="run_import">
<table><thead><tr><th>Imported column</th><th>Database column</th></tr></thead><tbody>
<?php foreach($mappingData['headers'] as $index=>$header):
$normalizedHeader=strtolower(trim((string)$header)); ?>
<tr><td><?=e((string)$header)?></td><td><select name="mapping[<?=$index?>]"><option value="">Do not import</option>
<?php foreach(COLUMNS as $target=>$label): ?><option value="<?=$target?>" <?=$normalizedHeader===strtolower($target)?'selected':''?>><?=e($label)?></option><?php endforeach; ?>
</select></td></tr><?php endforeach; ?></tbody></table>
<label>Match existing records by<select name="match_by">
<option value="friendly_url">Friendly URL</option><option value="meeting_id">Meeting ID</option>
</select></label><button type="submit">Run import</button></form>
</section></div><?php endif; ?>
<?php endif; ?>
</main>
<script>
const modalOpeners = new WeakMap();
const savedMailTemplates = new WeakMap();
const classroomBaseUrl = new URL(window.location.href);
classroomBaseUrl.pathname = classroomBaseUrl.pathname.replace(/\/admin\/?$/, '/');
classroomBaseUrl.search = '';
classroomBaseUrl.hash = '';

function openAdminModal(modal, opener = null) {
    if (!modal) {
        return;
    }
    if (opener) {
        modalOpeners.set(modal, opener);
    }
    modal.hidden = false;
    document.body.classList.add('modal-open');
    const firstControl = modal.querySelector(
        'input:not([type="hidden"]), textarea, [contenteditable="true"]'
    ) || modal.querySelector('button, a');
    if (firstControl) {
        window.setTimeout(function () { firstControl.focus(); }, 0);
    }
}

function closeAdminModal(modal) {
    if (!modal) {
        return;
    }
    const mailLanguage = modal.dataset.mailLanguage;
    if (mailLanguage) {
        const editor = document.getElementById('mail-template-' + mailLanguage);
        if (editor && savedMailTemplates.has(editor)) {
            editor.innerHTML = savedMailTemplates.get(editor);
        }
        const status = document.querySelector(
            '.template-status[data-language="' + mailLanguage + '"]'
        );
        if (status) {
            status.textContent = '';
            status.title = '';
            status.className = 'template-status';
        }
    }
    modal.hidden = true;
    if (!document.querySelector('.modal-backdrop:not([hidden])')) {
        document.body.classList.remove('modal-open');
    }
    const opener = modalOpeners.get(modal);
    if (opener) {
        opener.focus();
    }
}

document.querySelectorAll('[data-open-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
        openAdminModal(document.getElementById(button.dataset.openModal), button);
    });
});

document.querySelectorAll('[data-close-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
        closeAdminModal(button.closest('.modal-backdrop'));
    });
});

document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
    backdrop.addEventListener('click', function (event) {
        if (event.target === backdrop) {
            closeAdminModal(backdrop);
        }
    });
});

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
        return;
    }
    const openModals = Array.from(
        document.querySelectorAll('.modal-backdrop:not([hidden])')
    );
    closeAdminModal(openModals.at(-1));
});

const initialModal = document.querySelector('.modal-backdrop:not([hidden])');
if (initialModal) {
    document.body.classList.add('modal-open');
}

const filterForm = document.getElementById('filter-form');
if (filterForm) {
    // Keep the submit button as a no-JavaScript fallback.
    document.getElementById('filter-button').hidden = true;
    filterForm.querySelectorAll('[data-auto-filter]').forEach(function (field) {
        field.addEventListener('change', function () {
            filterForm.requestSubmit();
        });
    });
}

const rowsForm = document.getElementById('training-rows-form');
if (rowsForm) {
    const csrfToken = rowsForm.querySelector('input[name="csrf_token"]').value;
    const rowStates = new WeakMap();

    const rowInputs = function (row) {
        return Array.from(row.querySelectorAll('input[name^="rows["]'));
    };

    const valuesFor = function (row) {
        const values = {};
        rowInputs(row).forEach(function (input) {
            const match = input.name.match(/\[([^\]]+)\]$/);
            if (match) {
                values[match[1]] = input.value;
            }
        });
        return values;
    };

    const setUndoState = function (row) {
        const state = rowStates.get(row);
        const changed = rowInputs(row).some(function (input) {
            const match = input.name.match(/\[([^\]]+)\]$/);
            return match && input.value !== state.original[match[1]];
        });
        row.querySelector('.undo-row').disabled = !changed;
    };

    const saveRow = async function (row) {
        const status = row.querySelector('.autosave-status');
        status.textContent = 'Saving…';
        status.title = '';
        status.className = 'autosave-status saving';

        const body = new FormData();
        body.append('action', 'save_row_ajax');
        body.append('csrf_token', csrfToken);
        body.append('ID', row.dataset.rowId);
        Object.entries(valuesFor(row)).forEach(function ([column, value]) {
            body.append('row[' + column + ']', value);
        });

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        const result = await response.json().catch(function () {
            return {success: false, message: 'Invalid server response.'};
        });
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Saving failed.');
        }

        status.textContent = 'Saved';
        status.title = '';
        status.className = 'autosave-status saved';
        window.setTimeout(function () {
            if (status.textContent === 'Saved') {
                status.textContent = '';
            }
        }, 1500);
    };

    const queueSave = function (row) {
        const state = rowStates.get(row);
        state.queue = state.queue
            .catch(function () {})
            .then(function () { return saveRow(row); })
            .catch(function (error) {
                const status = row.querySelector('.autosave-status');
                status.textContent = 'Error';
                status.title = error.message;
                status.className = 'autosave-status save-error';
            });
    };

    rowsForm.querySelectorAll('tbody tr[data-row-id]').forEach(function (row) {
        rowStates.set(row, {original: valuesFor(row), queue: Promise.resolve()});

        rowInputs(row).forEach(function (input) {
            input.addEventListener('input', function () { setUndoState(row); });
            input.addEventListener('change', function () { queueSave(row); });
        });

        row.querySelector('.undo-row').addEventListener('click', function () {
            const original = rowStates.get(row).original;
            rowInputs(row).forEach(function (input) {
                const match = input.name.match(/\[([^\]]+)\]$/);
                if (match) {
                    input.value = original[match[1]];
                }
            });
            setUndoState(row);
            queueSave(row);
        });
    });
}

const bulkDeleteForm = document.getElementById('bulk-delete-form');
if (bulkDeleteForm) {
    const selectAll = document.getElementById('select-all-visible');
    const rowSelections = Array.from(document.querySelectorAll('.row-select'));
    const deleteSelected = document.getElementById('delete-selected');

    const updateSelection = function () {
        const selectedCount = rowSelections.filter(function (checkbox) {
            return checkbox.checked;
        }).length;
        deleteSelected.disabled = selectedCount === 0;
        deleteSelected.textContent = 'Delete selected (' + selectedCount + ')';
        selectAll.checked = rowSelections.length > 0
            && selectedCount === rowSelections.length;
        selectAll.indeterminate = selectedCount > 0
            && selectedCount < rowSelections.length;
    };

    selectAll.addEventListener('change', function () {
        rowSelections.forEach(function (checkbox) {
            checkbox.checked = selectAll.checked;
        });
        updateSelection();
    });
    rowSelections.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSelection);
    });

    bulkDeleteForm.addEventListener('submit', function (event) {
        const action = event.submitter ? event.submitter.value : '';
        if (action === 'delete_selected') {
            const selectedCount = rowSelections.filter(function (checkbox) {
                return checkbox.checked;
            }).length;
            if (selectedCount === 0
                || !window.confirm('Delete ' + selectedCount + ' selected training(s)?')) {
                event.preventDefault();
            }
        }
        if (action === 'delete_all'
            && !window.confirm('Delete ALL trainings? This cannot be undone.')) {
            event.preventDefault();
        }
    });

    updateSelection();
}

document.querySelectorAll('.copy-link').forEach(function (button) {
    button.addEventListener('click', async function () {
        const friendlyUrlInput = button.closest('tr')
            .querySelector('input[name$="[friendly_url]"]');
        const relative = encodeURIComponent(friendlyUrlInput.value.trim());
        const fullUrl = new URL(relative, classroomBaseUrl).href;

        await copyText(fullUrl);

        const oldText = button.textContent;
        button.textContent = 'Copied';
        window.setTimeout(function () {
            button.textContent = oldText;
        }, 1500);
    });
});

async function copyText(text, html = '') {
    if (html && navigator.clipboard && navigator.clipboard.write
        && window.ClipboardItem) {
        try {
            await navigator.clipboard.write([
                new ClipboardItem({
                    'text/plain': new Blob([text], {type: 'text/plain'}),
                    'text/html': new Blob([html], {type: 'text/html'})
                })
            ]);
            return;
        } catch (error) {
            // Continue with the browser-compatible rich-copy fallback below.
        }
    }

    if (html) {
        const richCopy = document.createElement('div');
        richCopy.contentEditable = 'true';
        richCopy.style.position = 'fixed';
        richCopy.style.left = '-9999px';
        richCopy.innerHTML = html;
        document.body.appendChild(richCopy);
        const range = document.createRange();
        range.selectNodeContents(richCopy);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        const copied = document.execCommand('copy');
        selection.removeAllRanges();
        richCopy.remove();
        if (copied) {
            return;
        }
    }

    try {
        await navigator.clipboard.writeText(text);
    } catch (error) {
        const temporary = document.createElement('textarea');
        temporary.value = text;
        document.body.appendChild(temporary);
        temporary.select();
        document.execCommand('copy');
        temporary.remove();
    }
}

function escapeHtml(value) {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

const mailTemplates = {
    nl: document.getElementById('mail-template-nl'),
    fr: document.getElementById('mail-template-fr')
};

Object.entries(mailTemplates).forEach(function ([language, editor]) {
    if (!editor) {
        return;
    }
    savedMailTemplates.set(editor, editor.innerHTML);
});

document.querySelectorAll('.save-mail-template').forEach(function (button) {
    button.addEventListener('click', async function () {
        const language = button.dataset.language;
        const editor = mailTemplates[language];
        const modal = button.closest('.modal-backdrop');
        const status = document.querySelector(
            '.template-status[data-language="' + language + '"]'
        );
        if (!editor || !modal || !status) {
            return;
        }

        button.disabled = true;
        status.textContent = 'Saving…';
        status.title = '';
        status.className = 'template-status saving';
        const body = new FormData();
        body.append('action', 'save_mail_template_ajax');
        body.append(
            'csrf_token',
            document.querySelector(
                '#training-rows-form input[name="csrf_token"]'
            ).value
        );
        body.append('language', language);
        body.append('email_template', editor.innerHTML);

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const result = await response.json().catch(function () {
                return {success: false, message: 'Invalid server response.'};
            });
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Saving failed.');
            }
            savedMailTemplates.set(editor, editor.innerHTML);
            status.textContent = 'Saved';
            status.className = 'template-status saved';
            closeAdminModal(modal);
        } catch (error) {
            status.textContent = 'Error';
            status.title = error.message;
            status.className = 'template-status save-error';
        } finally {
            button.disabled = false;
        }
    });
});

document.querySelectorAll('.rich-toolbar').forEach(function (toolbar) {
    const editor = document.getElementById(toolbar.dataset.editor);
    toolbar.querySelectorAll('button[data-command]').forEach(function (button) {
        button.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        button.addEventListener('click', function () {
            const command = button.dataset.command;
            let value = null;
            if (command === 'createLink') {
                value = window.prompt('Enter the full link, including https://');
                if (!value) {
                    return;
                }
                if (!/^https?:\/\//i.test(value) && !/^mailto:/i.test(value)) {
                    value = 'https://' + value;
                }
            }
            editor.focus();
            document.execCommand(command, false, value);
            editor.dispatchEvent(new Event('input', {bubbles: true}));
        });
    });
});

document.querySelectorAll('.copy-mail').forEach(function (button) {
    button.addEventListener('click', async function () {
        const row = button.closest('tr');
        const language = button.dataset.language;
        const templateField = mailTemplates[language];
        if (!row || !templateField) {
            return;
        }

        const fieldValue = function (column) {
            const field = row.querySelector('input[name$="[' + column + ']"]');
            return field ? field.value.trim() : '';
        };
        const localizedMailDate = function (value, selectedLanguage) {
            const match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
            if (!match) {
                return value;
            }
            const date = new Date(Date.UTC(
                Number(match[3]),
                Number(match[2]) - 1,
                Number(match[1])
            ));
            const locale = selectedLanguage === 'fr' ? 'fr-BE' : 'nl-BE';
            const formatted = new Intl.DateTimeFormat(locale, {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric',
                timeZone: 'UTC'
            }).format(date);
            return formatted;
        };
        const replacements = {
            url: new URL(
                encodeURIComponent(fieldValue('friendly_url')),
                classroomBaseUrl
            ).href,
            access_code: fieldValue('access_code'),
            training_name: fieldValue('training_name'),
            course: fieldValue('course'),
            training_date: localizedMailDate(
                fieldValue('training_date'),
                language
            )
        };

        let mail = templateField.innerText;
        Object.entries(replacements).forEach(function ([placeholder, value]) {
            mail = mail.split('{{' + placeholder + '}}').join(value);
        });

        let mailHtml = templateField.innerHTML
            .split('href="{{url}}"')
            .join('href="' + escapeHtml(replacements.url) + '"');
        Object.entries(replacements).forEach(function ([placeholder, value]) {
            const escapedValue = escapeHtml(value);
            const htmlValue = placeholder === 'url'
                ? '<a href="' + escapedValue + '">' + escapedValue + '</a>'
                : escapedValue;
            mailHtml = mailHtml
                .split('{{' + placeholder + '}}')
                .join(htmlValue);
        });
        mailHtml = '<div style="font-family:Arial,Helvetica,sans-serif">'
            + mailHtml
            + '</div>';

        await copyText(mail, mailHtml);

        const oldText = button.textContent;
        button.textContent = '✓';
        window.setTimeout(function () {
            button.textContent = oldText;
        }, 1500);
    });
});
</script>
<script src="admin-recordings.js?v=3"></script>
</body></html>
