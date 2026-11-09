<?php
/* lib.php - wspolne funkcje pamietnika. Nie otwieraj bezposrednio. */
if (!defined('DIARY_APP')) { http_response_code(403); exit; }

date_default_timezone_set('UTC');

const PHOTO_RE = '~^(?:covers|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$~i';
const UUID_RE  = '~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i';

function out($data): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail(int $code, string $msg): void {
    http_response_code($code);
    out(['data' => null, 'error' => ['message' => $msg]]);
}

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        if (!is_file($f)) { fail(503, 'Aplikacja nie jest jeszcze zainstalowana (brak pliku config.php).'); }
        $c = require $f;
    }
    return $c;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) { return $pdo; }
    $c = cfg()['db'];
    $o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['file'], null, null, $o);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $o + [PDO::ATTR_EMULATE_PREPARES => false]);
    }
    return $pdo;
}
function is_sqlite(): bool { return (cfg()['db']['driver'] ?? 'mysql') === 'sqlite'; }

/* Struktura bazy. Zwraca liste polecen CREATE dla danego sterownika. */
function schema_sql(string $driver): array {
    if ($driver === 'sqlite') {
        return [
            "CREATE TABLE IF NOT EXISTS trips (
                id TEXT PRIMARY KEY, name TEXT NOT NULL, date_from TEXT NOT NULL, date_to TEXT,
                date_precision TEXT NOT NULL DEFAULT 'day', cover_path TEXT, created_by TEXT,
                created_at TEXT NOT NULL, edited_by TEXT, kind TEXT, category TEXT)",
            "CREATE TABLE IF NOT EXISTS memories (
                id TEXT PRIMARY KEY, trip_id TEXT NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
                author TEXT NOT NULL, title TEXT, body TEXT, event_date TEXT NOT NULL,
                date_precision TEXT NOT NULL DEFAULT 'day', photos TEXT, created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL, edited_by TEXT)",
            "CREATE INDEX IF NOT EXISTS idx_mem_trip ON memories(trip_id)",
            "CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, attempt_at INTEGER NOT NULL)",
            "CREATE INDEX IF NOT EXISTS idx_la_ip ON login_attempts(ip)",
        ];
    }
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS trips (
            id CHAR(36) CHARACTER SET ascii NOT NULL,
            name VARCHAR(200) NOT NULL,
            date_from DATE NOT NULL,
            date_to DATE NULL,
            date_precision VARCHAR(10) NOT NULL DEFAULT 'day',
            cover_path VARCHAR(255) NULL,
            created_by VARCHAR(60) NULL,
            created_at DATETIME(6) NOT NULL,
            edited_by VARCHAR(60) NULL,
            kind VARCHAR(10) NULL,
            category VARCHAR(80) NULL,
            PRIMARY KEY (id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS memories (
            id CHAR(36) CHARACTER SET ascii NOT NULL,
            trip_id CHAR(36) CHARACTER SET ascii NOT NULL,
            author VARCHAR(60) NOT NULL,
            title VARCHAR(200) NULL,
            body MEDIUMTEXT NULL,
            event_date DATE NOT NULL,
            date_precision VARCHAR(10) NOT NULL DEFAULT 'day',
            photos MEDIUMTEXT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            edited_by VARCHAR(60) NULL,
            PRIMARY KEY (id),
            KEY idx_mem_trip (trip_id),
            CONSTRAINT fk_mem_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE CASCADE
        ) $t",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            attempt_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (id), KEY idx_la_ip (ip)
        ) $t",
    ];
}

function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}
function now_db(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
function ts_to_db(string $s): ?string {
    try { return (new DateTimeImmutable($s))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
    catch (Throwable $e) { return null; }
}
function ts_to_iso(?string $s): ?string {
    if ($s === null || $s === '') { return null; }
    return str_replace(' ', 'T', $s) . '+00:00';
}

function photo_dir(): string { return __DIR__ . '/storage/photos'; }
function valid_path($p): bool { return is_string($p) && strlen($p) <= 120 && preg_match(PHOTO_RE, $p) === 1; }

/* Sesja */
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function diary_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $dir = __DIR__ . '/storage/sessions';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (is_dir($dir) && is_writable($dir)) { session_save_path($dir); }
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    session_name('diary_sid');
    session_set_cookie_params([
        'lifetime' => 60 * 86400, 'path' => $path, 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string)(60 * 86400));
    ini_set('session.use_strict_mode', '1');
    session_start();
}
function logged_in(): bool { diary_session(); return ($_SESSION['ok'] ?? false) === true; }

function require_csrf(): void {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'diary') { fail(403, 'Niedozwolone żądanie.'); }
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o !== '') {
        $oh = strtolower((string)parse_url($o, PHP_URL_HOST));
        $hh = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        if ($oh !== $hh) { fail(403, 'Niedozwolone żądanie.'); }
    }
}

/* Katalogi i pliki zabezpieczajace (uzywane przez instalator) */
function write_htaccess_files(): void {
    $deny = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
    foreach (['storage', 'storage/photos', 'storage/sessions'] as $d) {
        $p = __DIR__ . '/' . $d;
        if (!is_dir($p)) { @mkdir($p, 0750, true); }
    }
    @file_put_contents(__DIR__ . '/storage/.htaccess', $deny);
    @file_put_contents(__DIR__ . '/storage/index.html', '');
    @file_put_contents(__DIR__ . '/storage/probe.txt', 'probe');
}

function login_throttled(): bool {
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '0', 0, 45);
    $pdo = db();
    $pdo->prepare('DELETE FROM login_attempts WHERE attempt_at < ?')->execute([time() - 3600]);
    $st = $pdo->prepare('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND attempt_at > ?');
    $st->execute([$ip, time() - 900]);
    return (int)$st->fetch()['n'] >= 8;
}
function login_throttle_check(): void {
    if (login_throttled()) { fail(429, 'Za dużo prób. Spróbuj ponownie za kilkanaście minut.'); }
}
