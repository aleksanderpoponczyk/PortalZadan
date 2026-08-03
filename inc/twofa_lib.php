<?php
declare(strict_types=1);

/**
 * Biblioteka 2FA dla Zadania·AP.
 * Dołączana przez login.php i twofa.php (NIE przez bootstrap.php).
 * Korzysta z pdo(), $_SESSION, $_COOKIE, $_SERVER dostarczanych przez bootstrap.php.
 */

require_once __DIR__ . '/totp.php';

const TOTP_ISSUER          = 'Zadania·AP';
const TRUSTED_DEVICE_DAYS  = 30;
const TRUSTED_COOKIE       = 'ZADANIA_TRUST';
const RECOVERY_CODE_COUNT  = 10;
const TWOFA_PENDING_TTL     = 600;  // 10 min na wpisanie kodu
const TWOFA_MAX_ATTEMPTS    = 8;    // po tylu nieudanych próbach: restart logowania

/* ================= Stan 2FA użytkownika ================= */

function twofa_user(int $uid): ?array
{
    $st = pdo()->prepare(
        'SELECT id, username, totp_secret, totp_enabled, totp_confirmed_at
         FROM users WHERE id = :id LIMIT 1'
    );
    $st->execute([':id' => $uid]);
    $u = $st->fetch();
    return $u ?: null;
}

function twofa_is_enabled(int $uid): bool
{
    $u = twofa_user($uid);
    return $u !== null && (int)$u['totp_enabled'] === 1;
}

/* ================= Rejestracja (opt-in) ================= */

/** Ustawia świeży, niepotwierdzony sekret; zwraca go (do pokazania raz). */
function twofa_begin_enroll(int $uid): string
{
    $secret = totp_generate_secret(20);
    $st = pdo()->prepare(
        'UPDATE users SET totp_secret = :s, totp_enabled = 0, totp_confirmed_at = NULL WHERE id = :id'
    );
    $st->execute([':s' => $secret, ':id' => $uid]);
    return $secret;
}

/** Potwierdza rejestrację kodem z aplikacji → włącza 2FA. */
function twofa_confirm_enroll(int $uid, string $code): bool
{
    $u = twofa_user($uid);
    if ($u === null || empty($u['totp_secret'])) {
        return false;
    }
    if (!totp_verify((string)$u['totp_secret'], $code)) {
        return false;
    }
    $st = pdo()->prepare('UPDATE users SET totp_enabled = 1, totp_confirmed_at = NOW() WHERE id = :id');
    $st->execute([':id' => $uid]);
    return true;
}

/** Całkowicie wyłącza 2FA i czyści powiązane dane. */
function twofa_disable(int $uid): void
{
    pdo()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_confirmed_at = NULL WHERE id = :id')
        ->execute([':id' => $uid]);
    pdo()->prepare('DELETE FROM user_recovery_codes WHERE user_id = :id')->execute([':id' => $uid]);
    pdo()->prepare('DELETE FROM trusted_devices WHERE user_id = :id')->execute([':id' => $uid]);
}

/* ================= Weryfikacja kodu logowania ================= */

function twofa_verify_totp(int $uid, string $code): bool
{
    $u = twofa_user($uid);
    if ($u === null || empty($u['totp_secret'])) {
        return false;
    }
    return totp_verify((string)$u['totp_secret'], $code);
}

/* ================= Kody odzyskiwania ================= */

/** Generuje nowy zestaw kodów; zwraca je jawnie (pokazywane raz), w bazie tylko hash. */
function twofa_generate_recovery_codes(int $uid): array
{
    pdo()->prepare('DELETE FROM user_recovery_codes WHERE user_id = :id')->execute([':id' => $uid]);
    $codes = [];
    $ins = pdo()->prepare('INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (:u, :h)');
    for ($i = 0; $i < RECOVERY_CODE_COUNT; $i++) {
        $raw  = bin2hex(random_bytes(5)); // 10 znaków hex
        $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 2);
        $codes[] = $code;
        $ins->execute([':u' => $uid, ':h' => password_hash($code, PASSWORD_DEFAULT)]);
    }
    return $codes;
}

/** Zużywa (jednorazowo) pasujący kod odzyskiwania. */
function twofa_consume_recovery_code(int $uid, string $input): bool
{
    $input = strtolower(trim($input));
    if ($input === '') {
        return false;
    }
    $st = pdo()->prepare('SELECT id, code_hash FROM user_recovery_codes WHERE user_id = :u AND used_at IS NULL');
    $st->execute([':u' => $uid]);
    foreach ($st->fetchAll() as $row) {
        if (password_verify($input, (string)$row['code_hash'])) {
            pdo()->prepare('UPDATE user_recovery_codes SET used_at = NOW() WHERE id = :id')
                ->execute([':id' => (int)$row['id']]);
            return true;
        }
    }
    return false;
}

function twofa_recovery_remaining(int $uid): int
{
    $st = pdo()->prepare('SELECT COUNT(*) AS c FROM user_recovery_codes WHERE user_id = :u AND used_at IS NULL');
    $st->execute([':u' => $uid]);
    $row = $st->fetch();
    return (int)($row['c'] ?? 0);
}

/* ================= Zaufane urządzenia (selector:validator) ================= */

function trusted_cookie_valid(int $uid): bool
{
    $raw = (string)($_COOKIE[TRUSTED_COOKIE] ?? '');
    if (strpos($raw, ':') === false) {
        return false;
    }
    [$selector, $validator] = explode(':', $raw, 2);
    if ($selector === '' || $validator === '') {
        return false;
    }
    $st = pdo()->prepare(
        'SELECT id, user_id, validator_hash, expires_at FROM trusted_devices WHERE selector = :s LIMIT 1'
    );
    $st->execute([':s' => $selector]);
    $row = $st->fetch();
    if (!$row || (int)$row['user_id'] !== $uid) {
        return false;
    }
    if (strtotime((string)$row['expires_at']) < time()) {
        return false;
    }
    if (!hash_equals((string)$row['validator_hash'], hash('sha256', $validator))) {
        return false;
    }
    pdo()->prepare('UPDATE trusted_devices SET last_used_at = NOW() WHERE id = :id')
        ->execute([':id' => (int)$row['id']]);
    return true;
}

/** Wystawia zaufane urządzenie: zapis w bazie + cookie. Zwraca wartość cookie (selector:validator). */
function trusted_device_issue(int $uid): string
{
    $selector  = bin2hex(random_bytes(9));   // 18 znaków (mieści się w CHAR(24))
    $validator = bin2hex(random_bytes(32));
    $expires   = date('Y-m-d H:i:s', time() + TRUSTED_DEVICE_DAYS * 86400);
    $ua        = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    pdo()->prepare(
        'INSERT INTO trusted_devices (user_id, selector, validator_hash, user_agent, expires_at)
         VALUES (:u, :s, :h, :ua, :e)'
    )->execute([
        ':u'  => $uid,
        ':s'  => $selector,
        ':h'  => hash('sha256', $validator),
        ':ua' => $ua,
        ':e'  => $expires,
    ]);

    $value = $selector . ':' . $validator;
    twofa_setcookie(TRUSTED_COOKIE, $value, time() + TRUSTED_DEVICE_DAYS * 86400);
    return $value;
}

function trusted_device_clear_current(): void
{
    $raw = (string)($_COOKIE[TRUSTED_COOKIE] ?? '');
    if (strpos($raw, ':') !== false) {
        [$selector] = explode(':', $raw, 2);
        pdo()->prepare('DELETE FROM trusted_devices WHERE selector = :s')->execute([':s' => $selector]);
    }
    twofa_setcookie(TRUSTED_COOKIE, '', time() - 3600);
}

function trusted_devices_revoke_all(int $uid): void
{
    pdo()->prepare('DELETE FROM trusted_devices WHERE user_id = :u')->execute([':u' => $uid]);
    twofa_setcookie(TRUSTED_COOKIE, '', time() - 3600);
}

function trusted_devices_list(int $uid): array
{
    $st = pdo()->prepare(
        'SELECT id, user_agent, created_at, last_used_at, expires_at
         FROM trusted_devices WHERE user_id = :u ORDER BY created_at DESC'
    );
    $st->execute([':u' => $uid]);
    return $st->fetchAll();
}

function twofa_setcookie(string $name, string $value, int $expires): void
{
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/* ================= Stan „pending 2FA" w sesji ================= */

function twofa_set_pending(int $uid): void
{
    $_SESSION['pending_2fa_uid']   = $uid;
    $_SESSION['pending_2fa_at']    = time();
    $_SESSION['pending_2fa_tries'] = 0;
}

function twofa_pending_uid(): ?int
{
    if (!isset($_SESSION['pending_2fa_uid'])) {
        return null;
    }
    if (time() - (int)($_SESSION['pending_2fa_at'] ?? 0) > TWOFA_PENDING_TTL) {
        twofa_clear_pending();
        return null;
    }
    return (int)$_SESSION['pending_2fa_uid'];
}

function twofa_clear_pending(): void
{
    unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_tries']);
}

function twofa_note_attempt(): int
{
    $_SESSION['pending_2fa_tries'] = (int)($_SESSION['pending_2fa_tries'] ?? 0) + 1;
    return (int)$_SESSION['pending_2fa_tries'];
}
