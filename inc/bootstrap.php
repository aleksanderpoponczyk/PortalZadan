<?php
declare(strict_types=1);

/* ===== Zadania·AP — rdzeń aplikacji ===== */

if (!extension_loaded('mbstring')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Brak rozszerzenia PHP 'mbstring'.\nWłącz je w ustawieniach PHP w panelu hostingu (na CyberFolks jest domyślnie dostępne).");
}
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Warsaw');

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Brak pliku config.php.\nSkopiuj config.sample.php jako config.php i uzupełnij dane bazy danych.");
}
require $configPath;

require __DIR__ . '/security.php';

session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('ZADANIA_SESS');
session_start();

/* ---------- Słowniki ---------- */

const SPHERES = [
    'prywatne' => 'Prywatne',
    'sluzbowe' => 'Służbowe',
];

const STATUSES = [
    'nowe'      => 'Nowe',
    'w_toku'    => 'W toku',
    'oczekuje'  => 'Oczekuje',
    'zrobione'  => 'Zrobione',
    'anulowane' => 'Anulowane',
];

const ACTIVE_STATUSES = ['nowe', 'w_toku', 'oczekuje'];

const PRIORITIES = [
    1 => 'P1 — krytyczny',
    2 => 'P2 — wysoki',
    3 => 'P3 — normalny',
    4 => 'P4 — niski',
    5 => 'P5 — kiedyś',
];

const AUTHORS = [
    'ja'     => 'Ja',
    'ai'     => 'AI',
    'system' => 'System',
];

/* ---------- Baza danych ---------- */

function pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

/* ---------- Pomocnicze ---------- */

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function flash_set(string $msg): void
{
    $_SESSION['flash'] = $msg;
}

function flash_get(): ?string
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function valid_date(?string $d): ?string
{
    $d = trim((string)$d);
    if ($d === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Błąd weryfikacji formularza (CSRF). Wróć i spróbuj ponownie.');
    }
}

/* ---------- Autoryzacja ---------- */

function logged_in(): bool
{
    return !empty($_SESSION['uid']);
}

function require_login(): void
{
    if (!logged_in()) {
        redirect('login.php');
    }
}
