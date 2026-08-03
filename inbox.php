<?php
declare(strict_types=1);

/**
 * Skrzynka wejściowa — dodawanie zadań przez HTTP POST bez logowania,
 * zabezpieczona tokenem z config.php (INBOX_TOKEN).
 *
 * Pola POST: token* (lub nagłówek X-Inbox-Token), title, description,
 *            sphere (prywatne|sluzbowe), priority (1–5), due_date (RRRR-MM-DD), source.
 *
 * Przykład:
 *   curl -X POST https://twojadomena.pl/zadania/inbox.php \
 *        -d token=TWÓJ_TOKEN -d title="Oddzwonić do klienta" \
 *        -d sphere=sluzbowe -d priority=2 -d source=outlook
 */

require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function jout(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jout(['ok' => false, 'error' => 'Dozwolony tylko POST.'], 405);
}

if (!defined('INBOX_TOKEN') || INBOX_TOKEN === '') {
    jout(['ok' => false, 'error' => 'Skrzynka wyłączona (pusty INBOX_TOKEN w config.php).'], 503);
}

$token = (string)($_POST['token'] ?? ($_SERVER['HTTP_X_INBOX_TOKEN'] ?? ''));
if (!hash_equals(INBOX_TOKEN, $token)) {
    usleep(500000);
    jout(['ok' => false, 'error' => 'Nieprawidłowy token.'], 403);
}

$title       = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$sphere      = isset(SPHERES[$_POST['sphere'] ?? '']) ? $_POST['sphere'] : 'sluzbowe';
$prio        = (int)($_POST['priority'] ?? 3);
$prio        = isset(PRIORITIES[$prio]) ? $prio : 3;
$due         = valid_date($_POST['due_date'] ?? null);
$source      = trim((string)($_POST['source'] ?? '')) ?: 'inbox';
$source      = mb_substr($source, 0, 50);

if ($title === '' && $description === '') {
    jout(['ok' => false, 'error' => 'Podaj title lub description.'], 422);
}
if ($title === '') {
    $title = mb_substr($description, 0, 159);
}
if (mb_strlen($title) > 160) {
    $title = mb_substr($title, 0, 159) . '…';
}

$st = pdo()->prepare(
    'INSERT INTO tasks (title, description, sphere, status, priority, due_date, source)
     VALUES (:t, :d, :sf, \'nowe\', :p, :dd, :src)'
);
$st->execute([
    ':t' => $title, ':d' => $description, ':sf' => $sphere,
    ':p' => $prio, ':dd' => $due, ':src' => $source,
]);

jout(['ok' => true, 'id' => (int)pdo()->lastInsertId()]);
