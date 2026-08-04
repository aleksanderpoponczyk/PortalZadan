<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repo_users.php';

/* ===== Zadania·AP — dziennik zdarzeń + throttling logowania (OWASP A07/A09) =====
 *
 * Throttling etapu HASŁA per KONTO (nie per IP). Kanał kodów odzyskiwania
 * (twofa.php, po haśle) NIE jest tą blokadą objęty.
 */

const AUTH_WINDOW_MIN   = 15;   // okno liczenia nieudanych prób (minuty)
const AUTH_SLOWDOWN_AT  = 3;    // od tylu nieudanych prób: narastające opóźnienie
const AUTH_HARD_DENY_AT = 8;    // od tylu nieudanych prób: twarda odmowa do końca okna

/* Prawidłowy hash bcrypt do wyrównania czasu odpowiedzi, gdy konto NIE istnieje
   (password_verify wykonuje pełne obliczenie zamiast zwrócić od razu false). */
const AUTH_DUMMY_HASH = '$2y$10$0w4n0WVhqNXir/HaJtPR9O4B2YIWVmMxZ9venWM86wBndSOmXWJq2';

/** Zapis zdarzenia bezpieczeństwa. NIGDY haseł/kodów/ID sesji — tylko skrót User-Agent. */
function auth_log_event(string $event, ?int $userId = null): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
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

/** Liczba nieudanych logowań (login_fail) danego konta w oknie AUTH_WINDOW_MIN. */
function auth_recent_fail_count(int $userId): int
{
    $mins = (int) AUTH_WINDOW_MIN;
    $row = db_select_one(
        "SELECT COUNT(*) AS c FROM auth_log
         WHERE user_id = :u AND event = 'login_fail'
           AND created_at > (NOW() - INTERVAL $mins MINUTE)",
        [':u' => $userId]
    );
    return $row ? (int) $row['c'] : 0;
}

/**
 * Throttling per konto. Zwraca 'deny' przy twardej odmowie, inaczej 'ok'
 * (po ewentualnym narastającym usleep). Konto nieistniejące -> 'ok'
 * (nie ujawniamy istnienia konta; stały czas zapewnia login.php dummy-hashem).
 */
function auth_throttle(string $username): string
{
    $user = user_by_username($username);
    if (!$user) {
        return 'ok';
    }
    $n = auth_recent_fail_count((int) $user['id']);
    if ($n >= AUTH_HARD_DENY_AT) {
        return 'deny';
    }
    if ($n >= AUTH_SLOWDOWN_AT) {
        usleep(min($n, 10) * 200000); // narastające opóźnienie (wzorzec jak w twofa_lib)
    }
    return 'ok';
}
