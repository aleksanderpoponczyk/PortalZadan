<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* ===== Użytkownicy (tabela users) ===== */

function user_by_username(string $username): ?array
{
    return db_select_one(
        'SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1',
        [':u' => $username]
    );
}

/**
 * Ciche podbicie hasha hasła (pod Etap 5 — password_needs_rehash).
 * Na razie zdefiniowane, jeszcze nieużywane.
 */
function user_touch_password(int $id, string $hash): bool
{
    db_execute(
        'UPDATE users SET password_hash = :h WHERE id = :id',
        [':h' => $hash, ':id' => $id]
    );
    return true;
}
