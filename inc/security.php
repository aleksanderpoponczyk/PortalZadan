<?php
declare(strict_types=1);

/* ===== Zadania·AP — nagłówki bezpieczeństwa + hardening sesji =====
 *
 * Dołączany na początku bootstrap.php, PRZED konfiguracją i startem sesji.
 * Wyłącznie ini sesji oraz nagłówki HTTP — żadnych innych efektów ubocznych.
 * (CSP w trybie enforce; HSTS z krótkim max-age — szczegóły w komentarzach niżej.)
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

    /* HSTS — krótki max-age (1 dzień) na start; po potwierdzeniu można podnieść
       do 31536000 i dodać includeSubDomains/preload. Honorowane tylko przez HTTPS. */
    header('Strict-Transport-Security: max-age=86400');

    /* CSP w trybie enforce — inline <script>/style/handlery usunięte w Etapie 7,
       zero naruszeń potwierdzone w trybie Report-Only przed tą zmianą. */
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; script-src 'self'; style-src 'self'; "
        . "img-src 'self' data:; object-src 'none'; base-uri 'none'; "
        . "frame-ancestors 'none'; form-action 'self'"
    );
}
