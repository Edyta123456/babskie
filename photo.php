<?php
/* photo.php - wydaje zdjecia tylko zalogowanym. */
define('DIARY_APP', 1);
require __DIR__ . '/lib.php';

$ok = logged_in();
session_write_close();
if (!$ok) { http_response_code(401); header('Content-Type: text/plain; charset=utf-8'); echo 'Brak dostepu'; exit; }

$p = $_GET['p'] ?? '';
$f = valid_path($p) ? photo_dir() . '/' . $p : '';
if ($f === '' || !is_file($f)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Nie znaleziono'; exit; }

$mt = filemtime($f);
$size = filesize($f);
$etag = '"' . md5($p . '|' . $mt . '|' . $size) . '"';
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mt) . ' GMT');
header('Cache-Control: private, max-age=604800');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="photo.jpg"');
readfile($f);
