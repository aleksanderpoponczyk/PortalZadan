<?php
declare(strict_types=1);

/**
 * Czysty TOTP wg RFC 6238 (HMAC-SHA1, 6 cyfr, krok 30 s) + base32 (RFC 4648).
 * Bez zewnętrznych bibliotek. Zgodne z Google Authenticator / Aegis / itp.
 */

/* ---- base32 (RFC 4648, alfabet A-Z2-7, bez paddingu) ---- */
function totp_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $buffer = 0;
    $bitsLeft = 0;
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $buffer = ($buffer << 8) | (ord($data[$i]) & 0xFF);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $bitsLeft -= 5;
            $out .= $alphabet[($buffer >> $bitsLeft) & 0x1F];
        }
    }
    if ($bitsLeft > 0) {
        $out .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
    }
    return $out;
}

function totp_base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $out = '';
    $buffer = 0;
    $bitsLeft = 0;
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $val = strpos($alphabet, $b32[$i]);
        if ($val === false) {
            continue;
        }
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $out .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $out;
}

/* ---- HOTP (RFC 4226) na surowym kluczu bajtowym ---- */
function totp_hotp_rawkey(string $key, int $counter, int $digits = 6): string
{
    $binCounter = pack('J', $counter); // 64-bit unsigned, big-endian
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    $mod = 10 ** $digits;
    return str_pad((string)($value % $mod), $digits, '0', STR_PAD_LEFT);
}

/* ---- TOTP ---- */
function totp_at(string $secretB32, int $time, int $step = 30, int $digits = 6): string
{
    $key = totp_base32_decode($secretB32);
    $counter = intdiv($time, $step);
    return totp_hotp_rawkey($key, $counter, $digits);
}

function totp_now(string $secretB32, int $step = 30, int $digits = 6): string
{
    return totp_at($secretB32, time(), $step, $digits);
}

/**
 * Weryfikacja z oknem tolerancji (domyślnie ±1 krok = ±30 s).
 * Porównanie w czasie stałym (hash_equals).
 */
function totp_verify(string $secretB32, string $code, ?int $time = null, int $window = 1, int $step = 30, int $digits = 6): bool
{
    $code = preg_replace('/\D/', '', $code);
    if ($code === '' || strlen($code) !== $digits) {
        return false;
    }
    $time = $time ?? time();
    for ($i = -$window; $i <= $window; $i++) {
        $candidate = totp_at($secretB32, $time + $i * $step, $step, $digits);
        if (hash_equals($candidate, $code)) {
            return true;
        }
    }
    return false;
}

/* ---- Generowanie sekretu (20 bajtów losowych = 160 bitów = 32 znaki base32) ---- */
function totp_generate_secret(int $bytes = 20): string
{
    return totp_base32_encode(random_bytes($bytes));
}

/* ---- otpauth:// URI do wklejenia/zeskanowania w aplikacji ---- */
function totp_otpauth_uri(string $secretB32, string $label, string $issuer): string
{
    $labelEnc = rawurlencode($issuer) . ':' . rawurlencode($label);
    $params = http_build_query([
        'secret'    => $secretB32,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => 6,
        'period'    => 30,
    ]);
    return 'otpauth://totp/' . $labelEnc . '?' . $params;
}
