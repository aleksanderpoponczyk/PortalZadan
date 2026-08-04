<?php
declare(strict_types=1);

/*
 * Zadania·AP — Inbox API: cienki kontroler (cała logika w inc/inbox_lib.php).
 *
 * ŚWIADOME ODSTĘPSTWO od checklisty DoD (§10 polityki bezpieczeństwa):
 * - brak require_login() i csrf_check(): endpoint jest bez-sesyjny i bez-cookie'owy,
 *   autoryzuje wyłącznie token w nagłówku (X-Inbox-Token / Authorization: Bearer),
 *   niczego nie czyta z $_SESSION i nie woła logged_in() — klasyczny CSRF
 *   (jeździec na cookie sesyjnym) nie ma tu wektora. Warunek tego odstępstwa:
 *   ten plik i inbox_lib.php IGNORUJĄ sesję w całości.
 * - fail closed: brak INBOX_TOKEN w config.php => 404 (feature wyłączony).
 */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/inbox_lib.php';

inbox_handle_request();
