<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repo_tasks.php';

/* ===== Zadania·AP — Inbox API: logika (autoryzacja tokenem, walidacja, guard) =====
 *
 * Endpoint bez-sesyjny i bez-cookie'owy: autoryzuje WYŁĄCZNIE token z nagłówka
 * (X-Inbox-Token albo Authorization: Bearer). Niczego nie czyta z $_SESSION,
 * nie woła logged_in()/require_login() ani csrf_check() — klasyczny CSRF
 * (jeździec na cookie sesyjnym) nie ma tu wektora. To świadome, udokumentowane
 * odstępstwo od checklisty DoD; jego warunkiem bezpieczeństwa jest CAŁKOWITE
 * ignorowanie sesji w tym pliku i w api_inbox.php.
 *
 * Fail closed: brak stałej INBOX_TOKEN w config.php => 404 (feature wyłączony).
 * Do auth_log trafiają wyłącznie zdarzenia (inbox_ok/inbox_fail/inbox_denied)
 * z ip + ua_hash — NIGDY token ani treści pól.
 */

const INBOX_WINDOW_MIN   = 15; // okno liczenia nieudanych autoryzacji (minuty)
const INBOX_SLOWDOWN_AT  = 3;  // od tylu nieudanych: narastające opóźnienie
const INBOX_HARD_DENY_AT = 10; // od tylu nieudanych: twarda odmowa (429) do końca okna

/** Czy feature jest włączony (INBOX_TOKEN ustawiony w config.php). */
function inbox_enabled(): bool
{
    return defined('INBOX_TOKEN') && is_string(INBOX_TOKEN) && INBOX_TOKEN !== '';
}

/** Odpowiedź JSON i koniec żądania. Zawsze Cache-Control: no-store. */
function inbox_json(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($status === 405) {
        header('Allow: POST');
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** IP klienta (do logu i throttlingu). */
function inbox_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Zapis zdarzenia do auth_log — wzorzec jak auth_log_event(); nigdy sekretów. */
function inbox_log_event(string $event, ?int $userId = null): void
{
    $ip = inbox_client_ip();
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    db_execute(
        'INSERT INTO auth_log (user_id, event, ip, ua_hash, created_at)
         VALUES (:u, :e, :ip, :ua, NOW())',
        [
            ':u'  => $userId,
            ':e'  => mb_substr($event, 0, 32),
            ':ip' => $ip !== '' ? mb_substr($ip, 0, 45) : null,
            ':ua' => $ua !== '' ? hash('sha256', $ua) : null,
        ]
    );
}

/** Liczba nieudanych autoryzacji (inbox_fail) z danego IP w oknie INBOX_WINDOW_MIN. */
function inbox_recent_fail_count(string $ip): int
{
    if ($ip === '') {
        return 0;
    }
    $mins = (int) INBOX_WINDOW_MIN;
    $row  = db_select_one(
        "SELECT COUNT(*) AS c FROM auth_log
         WHERE event = 'inbox_fail' AND ip = :ip
           AND created_at > (NOW() - INTERVAL $mins MINUTE)",
        [':ip' => mb_substr($ip, 0, 45)]
    );
    return $row ? (int) $row['c'] : 0;
}

/**
 * Throttling per IP (osobny od auth_guard.php — tamtego nie modyfikujemy).
 * Zwraca 'deny' przy twardej odmowie, inaczej 'ok' (po ewentualnym narastającym usleep).
 */
function inbox_throttle(): string
{
    $n = inbox_recent_fail_count(inbox_client_ip());
    if ($n >= INBOX_HARD_DENY_AT) {
        return 'deny';
    }
    if ($n >= INBOX_SLOWDOWN_AT) {
        usleep(min($n, 10) * 200000); // narastające opóźnienie (wzorzec z auth_guard)
    }
    return 'ok';
}

/** Token podany przez klienta: X-Inbox-Token albo Authorization: Bearer … */
function inbox_given_token(): string
{
    $t = (string) ($_SERVER['HTTP_X_INBOX_TOKEN'] ?? '');
    if ($t !== '') {
        return $t;
    }
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (str_starts_with($auth, 'Bearer ')) {
        return trim(substr($auth, 7));
    }
    return '';
}

/** Porównanie tokenów w stałym czasie (hash_equals na hashach — wyrównana długość). */
function inbox_token_valid(): bool
{
    $given = inbox_given_token();
    if ($given === '') {
        return false;
    }
    return hash_equals(
        hash('sha256', INBOX_TOKEN),
        hash('sha256', $given)
    );
}

/** Wejście: JSON (Content-Type: application/json) albo fallback $_POST (formularz). */
function inbox_input(): array
{
    $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($ct, 'application/json')) {
        $raw  = (string) file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

/** Wartość domyślna z INBOX_DEFAULTS (config.php) albo null. */
function inbox_default(string $key): mixed
{
    if (defined('INBOX_DEFAULTS') && is_array(INBOX_DEFAULTS)) {
        return INBOX_DEFAULTS[$key] ?? null;
    }
    return null;
}

/**
 * Walidacja pól wejściowych (białe listy z bootstrap.php).
 * Zwraca dane dla task_create() albo null (=> 422, generycznie, bez echa danych).
 */
function inbox_validate(array $in): ?array
{
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 255) {
        return null;
    }

    $description = trim((string) ($in['description'] ?? ''));
    if (mb_strlen($description) > 10000) {
        return null;
    }

    $sphere = trim((string) ($in['sphere'] ?? ''));
    if ($sphere === '') {
        $sphere = (string) (inbox_default('sphere') ?? '');
        if (!isset(SPHERES[$sphere])) {
            $sphere = (string) array_key_first(SPHERES);
        }
    } elseif (!isset(SPHERES[$sphere])) {
        return null;
    }

    $priorityRaw = $in['priority'] ?? '';
    if ($priorityRaw === '' || $priorityRaw === null) {
        $priority = (int) (inbox_default('priority') ?? 0);
        if (!isset(PRIORITIES[$priority])) {
            $priority = 3;
        }
    } else {
        if (!is_numeric($priorityRaw) || !isset(PRIORITIES[(int) $priorityRaw])) {
            return null;
        }
        $priority = (int) $priorityRaw;
    }

    $dueRaw = trim((string) ($in['due_date'] ?? ''));
    $due    = null;
    if ($dueRaw !== '') {
        $due = valid_date($dueRaw);
        if ($due === null) {
            return null;
        }
    }

    return [
        'title'       => $title,
        'description' => $description,
        'sphere'      => $sphere,
        'priority'    => $priority,
        'due_date'    => $due,
        'status'      => ACTIVE_STATUSES[0],
    ];
}

/** Obsługa całego żądania — woła api_inbox.php. Zawsze kończy odpowiedzią JSON. */
function inbox_handle_request(): never
{
    if (!inbox_enabled()) {
        inbox_json(404, ['ok' => false]); // feature wyłączony — fail closed
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        inbox_json(405, ['ok' => false]);
    }
    if (inbox_throttle() === 'deny') {
        inbox_log_event('inbox_denied');
        inbox_json(429, ['ok' => false]);
    }
    if (!inbox_token_valid()) {
        inbox_log_event('inbox_fail');
        inbox_json(401, ['ok' => false]);
    }
    $data = inbox_validate(inbox_input());
    if ($data === null) {
        inbox_json(422, ['ok' => false, 'error' => 'validation']);
    }
    $id  = task_create($data);
    $uid = defined('INBOX_USER_ID') ? (int) INBOX_USER_ID : null;
    inbox_log_event('inbox_ok', $uid);
    inbox_json(201, ['ok' => true, 'id' => $id]);
}
