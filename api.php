<?php
/* api.php - zaplecze pamietnika (odczyt, zapis, zdjecia, logowanie). */
define('DIARY_APP', 1);
require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { fail(405, 'Niedozwolona metoda.'); }
require_csrf();

/* ---- definicja tabel ---- */
const TBL = [
    'trips' => [
        'cols' => ['id' => 'id', 'name' => 'str200', 'date_from' => 'date', 'date_to' => 'date?', 'date_precision' => 'prec',
                   'cover_path' => 'path?', 'created_by' => 'str60?', 'created_at' => 'ts', 'edited_by' => 'str60?',
                   'kind' => 'kind?', 'category' => 'str80?'],
        'required' => ['name', 'date_from'],
        'filters' => ['id'],
        'defaults' => ['date_precision' => 'day'],
    ],
    'memories' => [
        'cols' => ['id' => 'id', 'trip_id' => 'id', 'author' => 'str60', 'title' => 'str200?', 'body' => 'text?', 'event_date' => 'date',
                   'date_precision' => 'prec', 'photos' => 'photos', 'created_at' => 'ts', 'updated_at' => 'ts', 'edited_by' => 'str60?'],
        'required' => ['trip_id', 'author', 'event_date'],
        'filters' => ['id', 'trip_id'],
        'defaults' => ['date_precision' => 'day'],
    ],
];
const IMMUTABLE = ['id', 'created_at', 'trip_id'];

class Bad extends Exception {}

function clean_val(string $col, string $type, $v) {
    $nullable = str_ends_with($type, '?');
    $t = rtrim($type, '?');
    if ($v === null || $v === '') {
        if ($t === 'photos') { return '[]'; }
        if ($nullable) { return null; }
        throw new Bad("Pole $col jest wymagane.");
    }
    switch ($t) {
        case 'id':
            if (!is_string($v) || !preg_match(UUID_RE, $v)) { throw new Bad("Nieprawidłowy identyfikator ($col)."); }
            return strtolower($v);
        case 'str200': case 'str80': case 'str60':
            if (!is_string($v)) { throw new Bad("Nieprawidłowa wartość ($col)."); }
            $max = (int)substr($t, 3);
            $v = trim($v);
            if ($v === '' && !$nullable) { throw new Bad("Pole $col jest wymagane."); }
            if (mb_strlen($v) > $max) { throw new Bad("Pole $col jest za długie (maks. $max znaków)."); }
            return $v === '' ? null : $v;
        case 'text':
            if (!is_string($v)) { throw new Bad("Nieprawidłowa wartość ($col)."); }
            if (strlen($v) > 60000) { throw new Bad("Pole $col jest za długie."); }
            return $v;
        case 'date':
            if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                throw new Bad("Nieprawidłowa data ($col).");
            }
            return $v;
        case 'prec':
            if (!in_array($v, ['day', 'month', 'year'], true)) { throw new Bad("Nieprawidłowa dokładność daty."); }
            return $v;
        case 'kind':
            if (!in_array($v, ['trip', 'life'], true)) { throw new Bad("Nieprawidłowy rodzaj wpisu."); }
            return $v;
        case 'path':
            if (!valid_path($v)) { throw new Bad("Nieprawidłowa ścieżka zdjęcia."); }
            return $v;
        case 'photos':
            if (!is_array($v) || count($v) > 80) { throw new Bad("Nieprawidłowa lista zdjęć."); }
            foreach ($v as $p) { if (!valid_path($p)) { throw new Bad("Nieprawidłowa ścieżka zdjęcia."); } }
            return json_encode(array_values($v), JSON_UNESCAPED_SLASHES);
        case 'ts':
            $x = is_string($v) ? ts_to_db($v) : null;
            if ($x === null) { throw new Bad("Nieprawidłowy czas ($col)."); }
            return $x;
    }
    throw new Bad("Nieznany typ pola $col.");
}

function table_spec($name): array {
    if (!is_string($name) || !isset(TBL[$name])) { throw new Bad('Nieznana tabela.'); }
    return TBL[$name];
}

function build_where(array $spec, $filters, array &$params): string {
    $parts = [];
    if (!is_array($filters)) { return ''; }
    foreach ($filters as $f) {
        $col = $f['col'] ?? '';
        if (!in_array($col, $spec['filters'], true)) { throw new Bad('Niedozwolony filtr.'); }
        if (($f['op'] ?? '') === 'eq') {
            $parts[] = "$col = ?";
            $params[] = clean_val($col, 'id', $f['val'] ?? null);
        } elseif (($f['op'] ?? '') === 'in') {
            $vals = $f['val'] ?? null;
            if (!is_array($vals) || count($vals) > 500) { throw new Bad('Nieprawidłowy filtr.'); }
            if (!count($vals)) { $parts[] = '1 = 0'; continue; }
            $parts[] = "$col IN (" . implode(',', array_fill(0, count($vals), '?')) . ')';
            foreach ($vals as $x) { $params[] = clean_val($col, 'id', $x); }
        } else { throw new Bad('Niedozwolony filtr.'); }
    }
    return $parts ? ' WHERE ' . implode(' AND ', $parts) : '';
}

function out_row(string $table, array $r): array {
    foreach ($r as $k => $v) {
        if ($k === 'photos') {
            $d = is_string($v) ? json_decode($v, true) : null;
            $r[$k] = is_array($d) ? array_values($d) : [];
        } elseif ($k === 'created_at' || $k === 'updated_at') {
            $r[$k] = ts_to_iso($v);
        }
    }
    return $r;
}

function query_select(array $q): void {
    $spec = table_spec($q['table'] ?? null);
    $table = $q['table'];
    $cols = trim((string)($q['cols'] ?? '*'));
    if ($cols === '*' || $cols === '') { $list = array_keys($spec['cols']); }
    else {
        $list = array_map('trim', explode(',', $cols));
        foreach ($list as $c) { if (!isset($spec['cols'][$c])) { throw new Bad("Nieznana kolumna: $c"); } }
    }
    $params = [];
    $where = build_where($spec, $q['filters'] ?? [], $params);
    $order = [];
    foreach (($q['order'] ?? []) as $o) {
        $c = $o['col'] ?? '';
        if (!isset($spec['cols'][$c])) { throw new Bad('Niedozwolone sortowanie.'); }
        $order[] = $c . (($o['asc'] ?? true) ? ' ASC' : ' DESC');
    }
    if (!$order) { $order[] = 'created_at ASC'; }
    $order[] = 'id ASC';
    // do sortowania potrzebujemy kolumn, ktore moga nie byc w select - sortujemy po nazwach z tabeli, wiec OK
    $sql = 'SELECT ' . implode(',', $list) . " FROM $table" . $where . ' ORDER BY ' . implode(',', $order);
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = array_map(fn($r) => out_row($table, $r), $st->fetchAll());
    if (!empty($q['single'])) {
        if (count($rows) !== 1) {
            out(['data' => null, 'error' => ['message' => 'Nie znaleziono wpisu.', 'code' => 'PGRST116']]);
        }
        out(['data' => $rows[0], 'error' => null]);
    }
    out(['data' => $rows, 'error' => null]);
}

function query_write(string $op, array $q): void {
    $spec = table_spec($q['table'] ?? null);
    $table = $q['table'];
    $pdo = db();
    if ($op === 'insert') {
        $row = $q['row'] ?? null;
        if (!is_array($row)) { throw new Bad('Brak danych.'); }
        $data = [];
        foreach ($row as $k => $v) {
            if (!isset($spec['cols'][$k]) || in_array($k, ['id', 'created_at'], true)) { throw new Bad("Nieznane pole: $k"); }
            $data[$k] = clean_val($k, $spec['cols'][$k], $v);
        }
        foreach ($spec['defaults'] as $k => $v) { if (!array_key_exists($k, $data)) { $data[$k] = $v; } }
        foreach ($spec['required'] as $k) { if (!isset($data[$k]) || $data[$k] === null) { throw new Bad("Pole $k jest wymagane."); } }
        if ($table === 'memories') {
            $st = $pdo->prepare('SELECT 1 FROM trips WHERE id = ?'); $st->execute([$data['trip_id']]);
            if (!$st->fetch()) { throw new Bad('Nie ma takiego wyjazdu.'); }
            if (!isset($data['photos'])) { $data['photos'] = '[]'; }
        }
        $now = now_db();
        $data['id'] = uuid4();
        $data['created_at'] = $now;
        if ($table === 'memories') { $data['updated_at'] = $now; }
        $names = array_keys($data);
        $st = $pdo->prepare("INSERT INTO $table (" . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')');
        $st->execute(array_values($data));
        out(['data' => ['id' => $data['id']], 'error' => null]);
    }
    if ($op === 'update') {
        $row = $q['row'] ?? null;
        if (!is_array($row)) { throw new Bad('Brak danych.'); }
        $filters = $q['filters'] ?? [];
        if (count($filters) !== 1 || ($filters[0]['col'] ?? '') !== 'id' || ($filters[0]['op'] ?? '') !== 'eq') { throw new Bad('Brak identyfikatora wpisu.'); }
        $data = [];
        foreach ($row as $k => $v) {
            if (!isset($spec['cols'][$k])) { throw new Bad("Nieznane pole: $k"); }
            if (in_array($k, IMMUTABLE, true)) { continue; }   // strona wysyla np. trip_id przy edycji; tych pol nie zmieniamy
            $data[$k] = clean_val($k, $spec['cols'][$k], $v);
        }
        foreach ($spec['required'] as $k) { if (array_key_exists($k, $data) && $data[$k] === null) { throw new Bad("Pole $k jest wymagane."); } }
        if ($table === 'memories' && !isset($data['updated_at'])) { $data['updated_at'] = now_db(); }
        if (!$data) { out(['data' => null, 'error' => null]); }
        $params = [];
        $set = [];
        foreach ($data as $k => $v) { $set[] = "$k = ?"; $params[] = $v; }
        $where = build_where($spec, $filters, $params);
        $st = $pdo->prepare("UPDATE $table SET " . implode(', ', $set) . $where);
        $st->execute($params);
        out(['data' => null, 'error' => null]);
    }
    if ($op === 'delete') {
        $filters = $q['filters'] ?? [];
        if (count($filters) !== 1 || ($filters[0]['col'] ?? '') !== 'id' || ($filters[0]['op'] ?? '') !== 'eq') { throw new Bad('Brak identyfikatora wpisu.'); }
        $params = [];
        $where = build_where($spec, $filters, $params);
        $st = $pdo->prepare("DELETE FROM $table" . $where);
        $st->execute($params);
        out(['data' => null, 'error' => null]);
    }
}

$action = (string)($_GET['a'] ?? '');
$body = [];
if ($action !== 'upload') {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 3000000) { fail(413, 'Za duże żądanie.'); }
    if ($raw !== '') {
        $body = json_decode($raw, true);
        if (!is_array($body)) { fail(400, 'Nieprawidłowe żądanie.'); }
    }
}

try {
    switch ($action) {
        case 'session':
            $ok = logged_in();
            session_write_close();
            out(['data' => ['session' => $ok ? ['ok' => true] : null], 'error' => null]);

        case 'login':
            login_throttle_check();
            $pw = (string)($body['password'] ?? '');
            $hash = (string)(cfg()['password_hash'] ?? '');
            $ip = substr($_SERVER['REMOTE_ADDR'] ?? '0', 0, 45);
            if ($pw !== '' && $hash !== '' && password_verify($pw, $hash)) {
                diary_session();
                session_regenerate_id(true);
                $_SESSION['ok'] = true;
                session_write_close();
                db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
                out(['data' => ['session' => ['ok' => true]], 'error' => null]);
            }
            db()->prepare('INSERT INTO login_attempts (ip, attempt_at) VALUES (?, ?)')->execute([$ip, time()]);
            usleep(400000);
            out(['data' => null, 'error' => ['message' => 'Nieprawidłowe hasło.']]);

        case 'logout':
            diary_session();
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            }
            session_destroy();
            out(['data' => null, 'error' => null]);
    }

    /* wszystko ponizej wymaga zalogowania */
    if (!logged_in()) { fail(401, 'Sesja wygasła. Zaloguj się ponownie.'); }
    session_write_close();

    switch ($action) {
        case 'select':
            query_select($body);
            break;
        case 'insert': case 'update': case 'delete':
            query_write($action, $body);
            break;

        case 'sign':
            $paths = $body['paths'] ?? [];
            if (!is_array($paths) || count($paths) > 600) { throw new Bad('Nieprawidłowa lista.'); }
            $res = [];
            foreach ($paths as $p) {
                if (!valid_path($p)) { continue; }
                $f = photo_dir() . '/' . $p;
                if (is_file($f)) { $res[] = ['path' => $p, 'signedUrl' => 'photo.php?p=' . rawurlencode($p) . '&v=' . filemtime($f)]; }
            }
            out(['data' => $res, 'error' => null]);

        case 'remove':
            $paths = $body['paths'] ?? [];
            if (!is_array($paths) || count($paths) > 600) { throw new Bad('Nieprawidłowa lista.'); }
            foreach ($paths as $p) {
                if (valid_path($p)) { $f = photo_dir() . '/' . $p; if (is_file($f)) { @unlink($f); } }
            }
            out(['data' => null, 'error' => null]);

        case 'upload':
            $p = $_POST['path'] ?? '';
            if (!valid_path($p)) { throw new Bad('Nieprawidłowa ścieżka zdjęcia.'); }
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                $msg = ($f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) ? 'Zdjęcie jest za duże dla tego serwera.' : 'Nie udało się odebrać zdjęcia.';
                throw new Bad($msg);
            }
            if ($f['size'] > 15 * 1024 * 1024) { throw new Bad('Zdjęcie jest za duże.'); }
            $info = @getimagesize($f['tmp_name']);
            if (!$info || $info[2] !== IMAGETYPE_JPEG) { throw new Bad('Dozwolone są tylko zdjęcia JPEG.'); }
            $dest = photo_dir() . '/' . $p;
            if (is_file($dest)) { throw new Bad('Takie zdjęcie już istnieje.'); }
            if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0750, true)) { throw new Bad('Nie można zapisać zdjęcia na serwerze.'); }
            if (!move_uploaded_file($f['tmp_name'], $dest)) { throw new Bad('Nie można zapisać zdjęcia na serwerze.'); }
            @chmod($dest, 0640);
            out(['data' => ['path' => $p], 'error' => null]);

        default:
            fail(400, 'Nieznana akcja.');
    }
} catch (Bad $e) {
    out(['data' => null, 'error' => ['message' => $e->getMessage()]]);
} catch (Throwable $e) {
    error_log('diary api: ' . $e->getMessage());
    fail(500, 'Błąd serwera.');
}
