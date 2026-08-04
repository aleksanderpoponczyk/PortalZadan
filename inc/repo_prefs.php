<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* ===== Preferencje per użytkownik (tabela user_prefs) ===== */

function prefs_get(int $uid, string $key, ?string $default = null): ?string
{
    $row = db_select_one(
        'SELECT v FROM user_prefs WHERE user_id = :u AND k = :k',
        [':u' => $uid, ':k' => $key]
    );
    return $row ? (string) $row['v'] : $default;
}

function prefs_set(int $uid, string $key, string $value): void
{
    db_execute(
        'INSERT INTO user_prefs (user_id, k, v) VALUES (:u, :k, :v)
         ON DUPLICATE KEY UPDATE v = VALUES(v)',
        [':u' => $uid, ':k' => $key, ':v' => $value]
    );
}
