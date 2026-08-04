<?php
declare(strict_types=1);

/* ===== Zadania·AP — nagłówki bezpieczeństwa + hardening sesji =====
 *
 * Dołączany na początku bootstrap.php, PRZED konfiguracją i startem sesji.
 * Wyłącznie ini sesji oraz nagłówki HTTP — żadnych innych efektów ubocznych.
 * (HSTS celowo NIE tutaj — osobny etap. CSP na razie tylko Report-Only.)
 */

/* --- Hardening sesji (musi być przed session_start) --- */
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
/* Flagi cookie sesji (Secure/HttpOnly/SameSite=Lax, lifetime 30 dni) ustawia
   bootstrap.php przez session_set_cookie_params tuż po tym require. */

/* --- Nagłówki bezpieczeństwa (OWASP A02) --- */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

    /* CSP wyłącznie w trybie Report-Only — nie blokuje, tylko raportuje do konsoli.
       Inline <script>/style/handlery zostaną usunięte w osobnym etapie (potem enforce). */
    header(
        "Content-Security-Policy-Report-Only: "
        . "default-src 'self'; script-src 'self'; style-src 'self'; "
        . "img-src 'self' data:; object-src 'none'; base-uri 'none'; "
        . "frame-ancestors 'none'; form-action 'self'"
    );
}
