<?php
/* import.php - jednorazowy import kopii (trips/memories + zdjecia) z poprzedniej wersji.
   Po imporcie USUN ten plik z serwera. */
define('DIARY_APP', 1);
require __DIR__ . '/lib.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
@set_time_limit(0);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function say($s, $cls = '') { echo '<div class="' . h($cls) . '">' . h($s) . '</div>' . str_repeat(' ', 256) . "\n"; @flush(); }

if (!is_file(__DIR__ . '/config.php')) { http_response_code(503); echo 'Najpierw uruchom install.php.'; exit; }

function read_table(string $dir, string $name): ?array {
    if (is_file("$dir/$name.json")) {
        $j = json_decode((string)file_get_contents("$dir/$name.json"), true);
        return is_array($j) ? $j : null;
    }
    if (is_file("$dir/$name.csv")) {
        $fh = fopen("$dir/$name.csv", 'rb');
        $head = fgetcsv($fh, 0, ',', '"', '');
        $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($r === [null] || count($r) !== count($head)) { continue; }
            $row = array_combine($head, $r);
            if (isset($row['photos'])) { $d = json_decode($row['photos'], true); $row['photos'] = is_array($d) ? $d : []; }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }
    return null;
}
function nn($v) { return ($v === null || $v === '') ? null : $v; }
function ts($v) { $v = nn($v); return $v === null ? now_db() : (ts_to_db((string)$v) ?? now_db()); }

$src = trim((string)($_POST['src'] ?? 'kopia/babskie'));
$ran = ($_SERVER['REQUEST_METHOD'] === 'POST');
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Import kopii</title>
<style>
 body{font-family:system-ui,sans-serif;max-width:640px;margin:20px auto;padding:0 14px;line-height:1.5;color:#222}
 label{display:block;margin-top:12px;font-weight:600} small{font-weight:400;color:#666}
 input{width:100%;padding:10px;font-size:16px;box-sizing:border-box} button{margin-top:18px;padding:12px 18px;font-size:16px}
 .ok{color:#1b6e2d}.err{color:#b00020;font-weight:600}.muted{color:#666;font-size:14px}
</style></head><body>
<h2>Import kopii pamiętnika</h2>
<?php
if (!$ran) { ?>
<p>Wczytuje wyjazdy, wspomnienia i zdjęcia z kopii pobranej wcześniej skryptem. Można uruchamiać wielokrotnie: wpisy, które już są, są pomijane.</p>
<form method="post" autocomplete="off">
 <label>Folder z kopią <small>(względem tego folderu, np. <code>kopia/babskie</code>, albo pełna ścieżka na serwerze)</small></label>
 <input name="src" value="<?= h($src) ?>" required>
 <label>Hasło grupy</label>
 <input name="pw" type="password" required>
 <button type="submit">Importuj</button>
</form>
<p class="muted">Ten folder tego pliku: <code><?= h(__DIR__) ?></code></p>
</body></html>
<?php exit; }

if (login_throttled()) { say('Za dużo prób. Spróbuj ponownie za kilkanaście minut.', 'err'); echo '</body></html>'; exit; }
$hash = (string)(cfg()['password_hash'] ?? '');
if (!password_verify((string)($_POST['pw'] ?? ''), $hash)) {
    db()->prepare('INSERT INTO login_attempts (ip, attempt_at) VALUES (?, ?)')->execute([substr($_SERVER['REMOTE_ADDR'] ?? '0', 0, 45), time()]);
    usleep(400000);
    say('Nieprawidłowe hasło.', 'err'); echo '<p><a href="import.php">Wróć</a></p></body></html>'; exit;
}

$dir = $src !== '' && ($src[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $src)) ? $src : __DIR__ . '/' . $src;
$dir = realpath($dir);
if (!$dir || !is_dir($dir)) { say('Nie znaleziono folderu z kopią: ' . $src, 'err'); echo '<p><a href="import.php">Wróć</a></p></body></html>'; exit; }

$trips = read_table($dir, 'trips');
$mems  = read_table($dir, 'memories');
if ($trips === null || $mems === null) { say('W folderze brakuje plików trips i memories (.json lub .csv).', 'err'); echo '</body></html>'; exit; }
say('Znaleziono: wyjazdów ' . count($trips) . ', wspomnień ' . count($mems) . '.');

/* zabezpiecz folder z kopia przed dostepem z internetu */
@file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");

write_htaccess_files();
$pdo = db();
foreach (schema_sql(is_sqlite() ? 'sqlite' : 'mysql') as $sql) { $pdo->exec($sql); }

$have = [];
foreach ($pdo->query('SELECT id FROM trips') as $r) { $have['t:' . $r['id']] = 1; }
foreach ($pdo->query('SELECT id FROM memories') as $r) { $have['m:' . $r['id']] = 1; }

$nt = $skt = $nm = $skm = $bad = 0; $paths = []; $badMsgs = [];
try {
    $pdo->beginTransaction();
    $it = $pdo->prepare('INSERT INTO trips (id,name,date_from,date_to,date_precision,cover_path,created_by,created_at,edited_by,kind,category) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($trips as $t) {
        $id = strtolower((string)($t['id'] ?? ''));
        if (!preg_match(UUID_RE, $id) || trim((string)($t['name'] ?? '')) === '' || nn($t['date_from'] ?? null) === null) { $bad++; $badMsgs[] = 'wyjazd: ' . ($t['name'] ?? '?'); continue; }
        $cover = nn($t['cover_path'] ?? null);
        if ($cover !== null) { if (valid_path($cover)) { $paths[$cover] = 1; } else { $cover = null; } }
        if (isset($have['t:' . $id])) { $skt++; continue; }
        $it->execute([$id, $t['name'], $t['date_from'], nn($t['date_to'] ?? null), nn($t['date_precision'] ?? null) ?? 'day', $cover,
            nn($t['created_by'] ?? null), ts($t['created_at'] ?? null), nn($t['edited_by'] ?? null), nn($t['kind'] ?? null), nn($t['category'] ?? null)]);
        $have['t:' . $id] = 1; $nt++;
    }
    $im = $pdo->prepare('INSERT INTO memories (id,trip_id,author,title,body,event_date,date_precision,photos,created_at,updated_at,edited_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($mems as $m) {
        $id = strtolower((string)($m['id'] ?? '')); $tid = strtolower((string)($m['trip_id'] ?? ''));
        if (!preg_match(UUID_RE, $id) || !isset($have['t:' . $tid]) || nn($m['author'] ?? null) === null || nn($m['event_date'] ?? null) === null) { $bad++; $badMsgs[] = 'wspomnienie: ' . ($m['title'] ?? $id); continue; }
        $ph = [];
        foreach ((array)($m['photos'] ?? []) as $p) { if (valid_path($p)) { $ph[] = $p; $paths[$p] = 1; } }
        if (isset($have['m:' . $id])) { $skm++; continue; }
        $created = ts($m['created_at'] ?? null);
        $im->execute([$id, $tid, $m['author'], nn($m['title'] ?? null), nn($m['body'] ?? null), $m['event_date'], nn($m['date_precision'] ?? null) ?? 'day',
            json_encode($ph, JSON_UNESCAPED_SLASHES), $created, ts($m['updated_at'] ?? null), nn($m['edited_by'] ?? null)]);
        $have['m:' . $id] = 1; $nm++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    say('Błąd importu danych: ' . $e->getMessage(), 'err'); echo '</body></html>'; exit;
}
say("Wyjazdy: dodano $nt, pominięto (już były) $skt. Wspomnienia: dodano $nm, pominięto $skm.", 'ok');
if ($bad) { say("Pominięto $bad nieprawidłowych wpisów:", 'err'); foreach (array_slice($badMsgs, 0, 10) as $b) { say($b); } }

/* zdjecia */
$copied = $skipped = 0; $missing = [];
foreach (array_keys($paths) as $p) {
    $from = $dir . '/photos/' . $p; $to = photo_dir() . '/' . $p;
    if (is_file($to) && filesize($to) > 0) { $skipped++; continue; }
    if (!is_file($from)) { $missing[] = $p; continue; }
    if (!is_dir(dirname($to))) { @mkdir(dirname($to), 0750, true); }
    if (@copy($from, $to)) { @chmod($to, 0640); $copied++; } else { $missing[] = $p . ' (nie udało się skopiować)'; }
}
say("Zdjęcia: skopiowano $copied, pominięto (już były) $skipped.", 'ok');
if ($missing) {
    say('Brakuje ' . count($missing) . ' zdjęć w folderze kopii (wpisy zostały, ale bez tych zdjęć):', 'err');
    foreach (array_slice($missing, 0, 15) as $m) { say($m); }
} else { say('Wszystkie zdjęcia na miejscu.', 'ok'); }
say('Gotowe. TERAZ USUŃ pliki import.php i install.php z serwera. Folder z kopią zostaw jako zapas (jest zablokowany przed wejściem z internetu).', 'err');
echo '<p><a href="./">Przejdź do pamiętnika</a></p></body></html>';
