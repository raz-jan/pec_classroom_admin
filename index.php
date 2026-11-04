<?php
declare(strict_types=1);

/*
 * PEC TRAINING ACCESS PAGE
 * --------------------------------
 * This standalone page:
 *   1. Reads the friendly meeting slug from ?meeting=...
 *   2. Retrieves the meeting from the MySQL `trainings` table.
 *   3. Shows the course, meeting name, date/time and available roles.
 *   4. Joins as VIEWER when no access code is provided.
 *   5. Verifies a supplied moderator access code.
 *   6. Creates/starts the meeting only for a valid moderator.
 *   7. Generates a signed viewer or moderator join URL.
 *   8. Redirects the participant's browser to BigBlueButton.
 *
 * Configuration is loaded from:
 *   root/.env (two levels above root/www/classroom/index.php)
 *
 * Example URL:
 *   https://example.com/classroom/pec-training-2026
 *
 * Apache rewrites that clean URL internally to:
 *   index.php?meeting=pec-training-2026
 *
 * Required PHP extensions:
 *   PDO MySQL, cURL and SimpleXML
 */

// Secure the session cookie used for CSRF protection and attempt limiting.
session_set_cookie_params([
    'httponly' => true,
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

const MODERATOR_ROLE = 'MODERATOR';
const VIEWER_ROLE = 'VIEWER';
const MAX_ATTEMPTS = 5;
const LOCK_SECONDS = 300;

/**
 * Load a small, dependency-free .env file.
 * Values may be unquoted or enclosed in matching single/double quotes.
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

const TRANSLATIONS = [
    'en' => [
        'page_title'       => 'Meeting',
        'invitation'       => 'You have been invited to join',
        'course'           => 'Course',
        'date'             => 'Date',
        'waiting'          => 'Waiting for the meeting to start… This page will try again automatically.',
        'your_name'        => 'Your name',
        'speaker_role'     => 'Speaker or moderator',
        'moderator_code'   => 'Access code',
        'viewer_help'      => 'Leave empty to join as a viewer.',
        'join'             => 'Join classroom',
        'unavailable'      => 'Meeting unavailable',
        'not_found'        => 'Meeting not found.',
        'invalid_link'     => 'The meeting link is invalid.',
        'expired'          => 'The form has expired. Refresh the page and try again.',
        'attempts'         => 'Too many unsuccessful attempts. Please try again later.',
        'invalid_name'     => 'Enter a name containing between 2 and 64 characters.',
        'wrong_code'       => 'The access code is incorrect.',
        'start_failed'     => 'The meeting could not be started. Please contact support.',
        'service_down'     => 'The meeting service is temporarily unavailable.',
    ],
    'nl' => [
        'page_title'       => 'Vergadering',
        'invitation'       => 'U bent uitgenodigd om deel te nemen',
        'course'           => 'Opleiding',
        'date'             => 'Datum',
        'waiting'          => 'Wachten tot de vergadering wordt gestart… Deze pagina probeert het automatisch opnieuw.',
        'your_name'        => 'Uw naam',
        'speaker_role'     => 'Spreker of moderator',
        'moderator_code'   => 'Toegangscode',
        'viewer_help'      => 'Laat leeg om als deelnemer deel te nemen.',
        'join'             => 'Deelnemen aan de les',
        'unavailable'      => 'Vergadering niet beschikbaar',
        'not_found'        => 'Vergadering niet gevonden.',
        'invalid_link'     => 'De vergaderlink is ongeldig.',
        'expired'          => 'Het formulier is verlopen. Vernieuw de pagina en probeer opnieuw.',
        'attempts'         => 'Te veel mislukte pogingen. Probeer het later opnieuw.',
        'invalid_name'     => 'Voer een naam van 2 tot 64 tekens in.',
        'wrong_code'       => 'De toegangscode is onjuist.',
        'start_failed'     => 'De vergadering kon niet worden gestart. Neem contact op met support.',
        'service_down'     => 'De vergaderservice is tijdelijk niet beschikbaar.',
    ],
    'fr' => [
        'page_title'       => 'Réunion',
        'invitation'       => 'Vous êtes invité(e) à rejoindre',
        'course'           => 'Formation',
        'date'             => 'Date',
        'waiting'          => 'En attente du démarrage de la réunion… Cette page réessaiera automatiquement.',
        'your_name'        => 'Votre nom',
        'speaker_role'     => 'Intervenant ou modérateur',
        'moderator_code'   => 'Code d’accès',
        'viewer_help'      => 'Laissez ce champ vide pour rejoindre en tant que participant.',
        'join'             => 'Rejoindre la classe',
        'unavailable'      => 'Réunion indisponible',
        'not_found'        => 'Réunion introuvable.',
        'invalid_link'     => 'Le lien de la réunion n’est pas valide.',
        'expired'          => 'Le formulaire a expiré. Actualisez la page et réessayez.',
        'attempts'         => 'Trop de tentatives infructueuses. Veuillez réessayer plus tard.',
        'invalid_name'     => 'Saisissez un nom contenant entre 2 et 64 caractères.',
        'wrong_code'       => 'Le code d’accès est incorrect.',
        'start_failed'     => 'La réunion n’a pas pu être démarrée. Veuillez contacter le support.',
        'service_down'     => 'Le service de réunion est temporairement indisponible.',
    ],
];

function detectLanguage(): string
{
    $requested = strtolower(trim((string) (
        $_GET['lang'] ?? $_POST['lang'] ?? ''
    )));
    if (in_array($requested, ['en', 'nl', 'fr'], true)) {
        return $requested;
    }

    $browserLanguage = strtolower(
        substr((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 2)
    );
    return in_array($browserLanguage, ['en', 'nl', 'fr'], true)
        ? $browserLanguage
        : 'nl';
}

function t(string $key, string $language): string
{
    return TRANSLATIONS[$language][$key]
        ?? TRANSLATIONS['en'][$key]
        ?? $key;
}

function translateError(string $error, string $language): string
{
    $knownErrors = [
        'The meeting link is invalid.' => 'invalid_link',
        'Meeting not found.' => 'not_found',
        'The form has expired. Refresh the page and try again.' => 'expired',
        'Too many unsuccessful attempts. Please try again later.' => 'attempts',
        'Enter a name containing between 2 and 64 characters.' => 'invalid_name',
        'The access code is incorrect.' => 'wrong_code',
        'The meeting could not be started. Please contact support.' => 'start_failed',
        'The meeting service is temporarily unavailable.' => 'service_down',
    ];

    return isset($knownErrors[$error])
        ? t($knownErrors[$error], $language)
        : $error;
}

/**
 * Read a required value from the normalized configuration array.
 */
function configRequired(array $config, string $name): string
{
    $value = $config[$name] ?? null;

    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException(
            "Missing required configuration value: {$name}"
        );
    }

    return trim($value);
}

/**
 * Escape dynamic text before placing it in HTML.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Display the meeting date with a localized weekday, without the time.
 */
function localizedMeetingDate(?string $value, string $language): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date !== false) {
            $weekdays = [
                'en' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
                'nl' => ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'],
                'fr' => ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'],
            ];
            $months = [
                'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
                'nl' => [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'],
                'fr' => [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
            ];
            $language = isset($weekdays[$language]) ? $language : 'en';
            $weekday = $weekdays[$language][(int) $date->format('N') - 1];
            $month = $months[$language][(int) $date->format('n')];
            $prefix = ['en' => 'On', 'nl' => '', 'fr' => ''][$language];

            return trim(sprintf(
                '%s %s %d %s %s',
                $prefix,
                $weekday,
                (int) $date->format('j'),
                $month,
                $date->format('Y')
            ));
        }
    }

    return $value;
}

/**
 * Build a signed BBB API URL.
 *
 * BBB validates SHA-1 over:
 *     API action + exact query string + shared secret
 *
 * The same $query variable is used for the checksum and URL. This is important:
 * even a small encoding difference would make the checksum invalid.
 */
function buildBbbUrl(
    string $apiUrl,
    string $secret,
    string $action,
    array $parameters
): string {
    $query = http_build_query(
        $parameters,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    $checksum = sha1($action . $query . $secret);

    return rtrim($apiUrl, '/')
        . '/'
        . $action
        . '?'
        . $query
        . '&checksum='
        . $checksum;
}

/**
 * Call BBB's create endpoint server-side and parse its XML response.
 *
 * The browser never receives the BBB secret or create checksum.
 */
function callBbbApi(string $url): SimpleXMLElement
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialise the BBB request.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/xml'],
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException(
            "BBB API request failed (HTTP {$statusCode}): {$curlError}"
        );
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response, SimpleXMLElement::class, LIBXML_NONET);

    if ($xml === false) {
        throw new RuntimeException('BBB returned an invalid XML response.');
    }

    return $xml;
}

/**
 * Verify the moderator access code.
 *
 * Plain-text access codes are used by the administration page. The
 * password_hash() check remains for compatibility with any older records.
 */
function verifyAccessCode(string $submitted, string $stored): bool
{
    $hashInfo = password_get_info($stored);

    if (($hashInfo['algo'] ?? null) !== null) {
        return password_verify($submitted, $stored);
    }

    return hash_equals($stored, $submitted);
}

$language = detectLanguage();
$error = null;
$meeting = null;
$waitingForMeeting = false;

try {
    /*
     * index.php is in root/www/classroom; .env is two directories higher.
     */
    $environment = loadEnvFile(dirname(__DIR__, 2) . '/.env');
    $config = [
        'db_host'     => envRequired($environment, 'DB_HOST'),
        'db_name'     => envRequired($environment, 'DB_NAME'),
        'db_user'     => envRequired($environment, 'DB_USER'),
        'db_password' => envRequired($environment, 'DB_PASSWORD'),
        'bbb_api_url' => envRequired($environment, 'BBB_API_URL'),
        'bbb_secret'  => envRequired($environment, 'BBB_SECRET'),
    ];

    $dbHost = configRequired($config, 'db_host');
    $dbName = configRequired($config, 'db_name');
    $dbUser = configRequired($config, 'db_user');
    $dbPassword = configRequired($config, 'db_password');
    $bbbApiUrl = configRequired($config, 'bbb_api_url');
    $bbbSecret = configRequired($config, 'bbb_secret');

    // Accept the friendly slug from GET initially and POST after form submit.
    $friendlyUrl = trim((string) (
        $_GET['meeting'] ?? $_POST['meeting'] ?? ''
    ));

    // Limit the slug to URL-safe characters before using it in a DB query.
    if (
        $friendlyUrl === ''
        || !preg_match('/^[A-Za-z0-9_-]{1,255}$/', $friendlyUrl)
    ) {
        http_response_code(400);
        throw new RuntimeException('The meeting link is invalid.');
    }

    // Open a UTF-8 MySQL connection. PDO exceptions are caught below.
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    /*
     * A prepared statement prevents SQL injection. The technical meeting_id
     * and visible training_name both come from this database row.
     */
    $statement = $pdo->prepare(
        'SELECT ID, friendly_url, meeting_id, training_name, course,
                training_date, access_code
         FROM trainings
         WHERE friendly_url = :friendly_url
         LIMIT 1'
    );
    $statement->execute(['friendly_url' => $friendlyUrl]);
    $meeting = $statement->fetch();

    if (!$meeting) {
        http_response_code(404);
        throw new RuntimeException('Meeting not found.');
    }

    // Generate a per-session token to reject forged form submissions.
    if (empty($_SESSION['bbb_csrf'])) {
        $_SESSION['bbb_csrf'] = bin2hex(random_bytes(32));
    }

    // GET displays the form; POST validates it and starts/joins the meeting.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals((string) $_SESSION['bbb_csrf'], $csrfToken)) {
            http_response_code(400);
            throw new RuntimeException(
                'The form has expired. Refresh the page and try again.'
            );
        }

        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $accessCode = trim((string) ($_POST['access_code'] ?? ''));
        $isModerator = $accessCode !== '';
        $role = $isModerator ? MODERATOR_ROLE : VIEWER_ROLE;

        // Track failed moderator-code attempts separately for each meeting.
        $attemptKey = 'bbb_attempts_' . hash('sha256', $friendlyUrl);
        $attempts = $_SESSION[$attemptKey] ?? [
            'count' => 0,
            'locked_until' => 0,
        ];

        if ($isModerator && (int) $attempts['locked_until'] > time()) {
            throw new RuntimeException(
                'Too many unsuccessful attempts. Please try again later.'
            );
        }

        $nameLength = function_exists('mb_strlen')
            ? mb_strlen($fullName, 'UTF-8')
            : strlen($fullName);

        if ($nameLength < 2 || $nameLength > 64) {
            throw new RuntimeException(
                'Enter a name containing between 2 and 64 characters.'
            );
        }

        if ($isModerator) {
            // Do not start BBB until the moderator code has been verified.
            if (!verifyAccessCode($accessCode, (string) $meeting['access_code'])) {
                $attempts['count'] = (int) $attempts['count'] + 1;

                if ($attempts['count'] >= MAX_ATTEMPTS) {
                    $attempts['locked_until'] = time() + LOCK_SECONDS;
                    $attempts['count'] = 0;
                }

                $_SESSION[$attemptKey] = $attempts;
                throw new RuntimeException('The access code is incorrect.');
            }

            unset($_SESSION[$attemptKey]);

            /*
             * BBB create is idempotent: calling it again for an already-running
             * meeting with the same settings does not create a duplicate.
             */
            $createUrl = buildBbbUrl(
                $bbbApiUrl,
                $bbbSecret,
                'create',
                [
                    'name'                    => (string) $meeting['training_name'],
                    'meetingID'               => (string) $meeting['meeting_id'],
                    'allowStartStopRecording' => 'true',
                    'autoStartRecording'      => 'true',
                    'record'                  => 'true',
                    'attendeePW'              => 'ap',
                    'moderatorPW'             => 'mp',
                ]
            );

            $createResponse = callBbbApi($createUrl);
            if ((string) $createResponse->returncode !== 'SUCCESS') {
                error_log(
                    'BBB create failed for meeting '
                    . $meeting['meeting_id']
                    . ': '
                    . trim((string) ($createResponse->message ?? ''))
                );
                throw new RuntimeException(
                    'The meeting could not be started. Please contact support.'
                );
            }
        } else {
            // Viewers wait here until a moderator has started the meeting.
            $runningUrl = buildBbbUrl(
                $bbbApiUrl,
                $bbbSecret,
                'isMeetingRunning',
                ['meetingID' => (string) $meeting['meeting_id']]
            );
            $runningResponse = callBbbApi($runningUrl);
            $waitingForMeeting = strtolower((string) ($runningResponse->running ?? 'false'))
                !== 'true';
        }

        if (!$waitingForMeeting) {
            // The role is derived server-side; visitors cannot manipulate it.
            $joinUrl = buildBbbUrl(
                $bbbApiUrl,
                $bbbSecret,
                'join',
                [
                    'fullName'  => $fullName,
                    'meetingID' => (string) $meeting['meeting_id'],
                    'role'      => $role,
                ]
            );

            session_write_close();
            header('Location: ' . $joinUrl, true, 303);
            exit;
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();

    if (
        str_starts_with($error, 'Missing required configuration value:')
        || str_starts_with($error, 'Missing required .env variable:')
        || $error === 'The root .env file is missing or unreadable.'
        || $error === 'The root .env file could not be read.'
        || str_starts_with($error, 'BBB API request failed')
        || $error === 'BBB returned an invalid XML response.'
    ) {
        error_log($error);
        $error = 'The meeting service is temporarily unavailable.';
        http_response_code(500);
    }
    $error = translateError($error, $language);
}
?>
<!doctype html>
<html lang="<?= e(['nl' => 'nl-BE', 'fr' => 'fr-BE'][$language] ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $meeting ? e((string) $meeting['training_name']) : e(t('page_title', $language)) ?></title>
    <link rel="stylesheet" href="training.css?v=15">
</head>
<body class="classroom-page">
<div class="pec-page-brand" aria-label="Patient Expert Center">
    <img
        src="PatienExpertCenter_Logo_RGB_smaller.png"
        alt="Patient Expert Center"
    >
</div>
<nav class="language-switch" aria-label="Language / Taal / Langue">
    <a href="?lang=nl" lang="nl-BE" <?=$language==='nl'?'aria-current="page"':''?>>NL</a>
    <a href="?lang=fr" lang="fr-BE" <?=$language==='fr'?'aria-current="page"':''?>>FR</a>
    <a href="?lang=en" lang="en" <?=$language==='en'?'aria-current="page"':''?>>EN</a>
</nav>
<main>
    <?php if ($meeting): ?>
        <section class="meeting-heading">
            <div>
                <p class="invitation"><?= e(t('invitation', $language)) ?></p>
                <h1><?= e((string) $meeting['training_name']) ?></h1>
                <p class="details">
                    <?php if (trim((string) ($meeting['course'] ?? '')) !== ''): ?>
                        <span><strong><?= e(t('course', $language)) ?>:</strong> <?= e((string) $meeting['course']) ?></span>
                    <?php endif; ?>
                    <?php if (trim((string) ($meeting['training_date'] ?? '')) !== ''): ?>
                        <span><?= e(localizedMeetingDate((string) $meeting['training_date'], $language)) ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </section>

        <?php if ($error): ?>
            <p class="error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <?php if ($waitingForMeeting): ?>
            <p class="waiting" role="status">
                <?= e(t('waiting', $language)) ?>
            </p>
        <?php endif; ?>

        <form method="post" id="join-form" class="join-form">
            <input
                type="hidden"
                name="meeting"
                value="<?= e((string) $meeting['friendly_url']) ?>"
            >
            <input type="hidden" name="lang" value="<?= e($language) ?>">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e((string) ($_SESSION['bbb_csrf'] ?? '')) ?>"
            >

            <label for="full_name"><?= e(t('your_name', $language)) ?></label>
            <input
                type="text"
                id="full_name"
                name="full_name"
                required
                minlength="2"
                maxlength="64"
                autocomplete="name"
                value="<?= e(trim((string) ($_POST['full_name'] ?? ''))) ?>"
            >

            <p class="access-role-title"><?= e(t('speaker_role', $language)) ?></p>
            <label for="access_code"><?= e(t('moderator_code', $language)) ?></label>
            <input
                type="password"
                id="access_code"
                name="access_code"
                autocomplete="current-password"
            >
            <small><?= e(t('viewer_help', $language)) ?></small>

            <button type="submit"><?= e(t('join', $language)) ?></button>
        </form>
    <?php else: ?>
        <h1><?= e(t('unavailable', $language)) ?></h1>
        <p class="error" role="alert"><?= e($error ?? t('not_found', $language)) ?></p>
    <?php endif; ?>
</main>
<?php if ($waitingForMeeting): ?>
<script>
window.setTimeout(function () {
    document.getElementById('join-form').requestSubmit();
}, 10000);
</script>
<?php endif; ?>
</body>
</html>
