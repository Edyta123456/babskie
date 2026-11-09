<?php
/* install.php - jednorazowy instalator. Po instalacji USUN ten plik z serwera. */
define('DIARY_APP', 1);
require __DIR__ . '/lib.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$cfgFile = __DIR__ . '/config.php';
$msg = ''; $done = false;

function page_start(): void { ?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Instalacja pamiętnika</title>
<style>
 body{font-family:system-ui,sans-serif;max-width:620px;margin:20px auto;padding:0 14px;line-height:1.5;color:#222}
 label{display:block;margin-top:12px;font-weight:600} small{font-weight:400;color:#666}
 input{width:100%;padding:10px;font-size:16px;box-sizing:border-box}
 button{margin-top:18px;padding:12px 18px;font-size:16px}
 .ok{color:#1b6e2d}.err{color:#b00020;font-weight:600}.box{background:#f4f4f4;padding:10px 14px;border-radius:8px}
</style></head><body>
<h2>Instalacja pamiętnika</h2>
<?php }

if (is_file($cfgFile)) {
    page_start();
    echo '<p class="ok"><b>Pamiętnik jest już zainstalowany.</b></p><p>Ze względów bezpieczeństwa <b>usuń teraz plik install.php</b> z serwera (Menedżer plików → zaznacz plik → usuń).</p></body></html>';
    exit;
}

$problems = [];
if (PHP_VERSION_ID < 80000) { $problems[] = 'Potrzebne jest PHP 8.0 lub nowsze (teraz: ' . PHP_VERSION . '). Zmień wersję PHP w panelu hostingu.'; }
foreach (['pdo_mysql', 'mbstring', 'fileinfo'] as $ext) { if (!extension_loaded($ext)) { $problems[] = "Brakuje rozszerzenia PHP: $ext (włącz je w panelu hostingu, np. w PHP Selector)."; } }
if (!is_writable(__DIR__)) { $problems[] = 'Folder nie jest zapisywalny. Sprawdź uprawnienia.'; }

$v = ['host' => 'localhost', 'name' => '', 'user' => '', 'pass' => '', 'gpw' => '', 'gpw2' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$problems) {
    foreach ($v as $k => $_) { $v[$k] = trim((string)($_POST[$k] ?? '')); }
    $v['gpw'] = (string)($_POST['gpw'] ?? ''); $v['gpw2'] = (string)($_POST['gpw2'] ?? '');
    $v['pass'] = (string)($_POST['pass'] ?? '');
    try {
        if ($v['name'] === '' || $v['user'] === '') { throw new RuntimeException('Uzupełnij nazwę bazy i użytkownika bazy.'); }
        if (mb_strlen($v['gpw']) < 6) { throw new RuntimeException('Hasło grupy musi mieć co najmniej 6 znaków.'); }
        if ($v['gpw'] !== $v['gpw2']) { throw new RuntimeException('Hasła grupy nie są takie same.'); }
        try {
            $pdo = new PDO('mysql:host=' . $v['host'] . ';dbname=' . $v['name'] . ';charset=utf8mb4', $v['user'], $v['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            throw new RuntimeException('Nie udało się połączyć z bazą. Sprawdź host, nazwę bazy, użytkownika i hasło bazy. (' . $e->getMessage() . ')');
        }
        foreach (schema_sql('mysql') as $sql) { $pdo->exec($sql); }
        write_htaccess_files();
        $conf = [
            'db' => ['driver' => 'mysql', 'host' => $v['host'], 'name' => $v['name'], 'user' => $v['user'], 'pass' => $v['pass']],
            'password_hash' => password_hash($v['gpw'], PASSWORD_DEFAULT),
        ];
        $php = "<?php\n// Wygenerowane przez install.php. Nie udostepniaj tego pliku.\nreturn " . var_export($conf, true) . ";\n";
        if (file_put_contents($cfgFile, $php, LOCK_EX) === false) { throw new RuntimeException('Nie udało się zapisać pliku config.php.'); }
        @chmod($cfgFile, 0600);
        $done = true;
    } catch (Throwable $e) { $msg = $e->getMessage(); }
}

page_start();
if ($done) { ?>
<p class="ok"><b>Gotowe! Baza i ustawienia zostały zapisane.</b></p>
<div class="box" id="probe">Sprawdzam zabezpieczenie folderu ze zdjęciami…</div>
<script>
fetch('storage/probe.txt', {cache:'no-store'}).then(function(r){
  var el=document.getElementById('probe');
  if (r.status===200){ el.className='box err'; el.textContent='UWAGA: folder storage jest dostępny z internetu. NIE wgrywaj jeszcze zdjęć i napisz do osoby, która pomaga Ci w instalacji.'; }
  else { el.className='box ok'; el.textContent='Folder ze zdjęciami jest zabezpieczony (OK).'; }
}).catch(function(){ document.getElementById('probe').textContent='Nie udało się sprawdzić zabezpieczenia.'; });
</script>
<h3>Co dalej</h3>
<ol>
 <li><b>Usuń plik install.php</b> z serwera.</li>
 <li>Jeśli przenosisz dane z poprzedniej wersji, otwórz <a href="import.php">import.php</a>. Jeśli zaczynasz od zera, pomiń ten krok i usuń też import.php.</li>
 <li>Wejdź na <a href="./">stronę pamiętnika</a> i zaloguj się hasłem grupy.</li>
</ol></body></html>
<?php exit; }

if ($problems) {
    echo '<p class="err">Zanim zaczniemy, trzeba to poprawić:</p><ul>';
    foreach ($problems as $p) { echo '<li>' . h($p) . '</li>'; }
    echo '</ul></body></html>'; exit;
}
if ($msg) { echo '<p class="err">' . h($msg) . '</p>'; }
?>
<p>Wpisz dane pustej bazy MySQL, którą założyłaś w panelu hostingu, oraz hasło, którym będzie wchodziła grupa.</p>
<form method="post" autocomplete="off">
 <label>Host bazy <small>(zwykle localhost)</small></label><input name="host" value="<?= h($v['host']) ?>" required>
 <label>Nazwa bazy</label><input name="name" value="<?= h($v['name']) ?>" required>
 <label>Użytkownik bazy</label><input name="user" value="<?= h($v['user']) ?>" required>
 <label>Hasło do bazy</label><input name="pass" type="password">
 <label>Hasło grupy <small>(to hasło wpisują znajomi, min. 6 znaków)</small></label><input name="gpw" type="password" required>
 <label>Powtórz hasło grupy</label><input name="gpw2" type="password" required>
 <button type="submit">Zainstaluj</button>
</form></body></html>
