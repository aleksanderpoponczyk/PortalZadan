<?php
declare(strict_types=1);

/* ===== Zadania·AP — warstwa dostępu do bazy (rdzeń) =====
 *
 * Strony WWW nie dotykają PDO ani SQL bezpośrednio — używają funkcji
 * domenowych z inc/repo_*.php, które korzystają z helperów poniżej.
 *
 * PDOException łapane centralnie: szczegóły trafiają wyłącznie do logu
 * serwera (error_log), a użytkownik widzi generyczny komunikat (fail-closed).
 */

/**
 * Wewnętrzny uchwyt PDO. W trakcie migracji deleguje do pdo() z bootstrap.php.
 * W kroku końcowym definicja pdo() zostanie tu przeniesiona (po akceptacji diffu).
 */
function db_handle(): PDO
{
    return pdo();
}

/** Generyczny błąd bazy — log już wykonany przez wywołującego. Fail-closed. */
function db_fail(): never
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    exit('<!doctype html><meta charset="utf-8"><title>Błąd</title>'
        . '<p style="font:16px system-ui;margin:2rem">Wystąpił błąd. Spróbuj ponownie za chwilę.</p>');
}

function db_log_and_fail(PDOException $e): never
{
    error_log('[Zadania·AP][DB] ' . $e->getMessage());
    db_fail();
}

/** SELECT wielu wierszy → lista tablic asocjacyjnych. */
function db_select_all(string $sql, array $params = []): array
{
    try {
        $st = db_handle()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (PDOException $e) {
        db_log_and_fail($e);
    }
}

/** SELECT jednego wiersza → tablica albo null. */
function db_select_one(string $sql, array $params = []): ?array
{
    try {
        $st = db_handle()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    } catch (PDOException $e) {
        db_log_and_fail($e);
    }
}

/** INSERT/UPDATE/DELETE → liczba dotkniętych wierszy. */
function db_execute(string $sql, array $params = []): int
{
    try {
        $st = db_handle()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    } catch (PDOException $e) {
        db_log_and_fail($e);
    }
}

/** INSERT → id nowego wiersza. */
function db_insert(string $sql, array $params = []): int
{
    try {
        $h = db_handle();
        $st = $h->prepare($sql);
        $st->execute($params);
        return (int) $h->lastInsertId();
    } catch (PDOException $e) {
        db_log_and_fail($e);
    }
}
