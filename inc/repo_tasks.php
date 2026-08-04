<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* ===== Zadania i dziennik (tabele tasks, task_entries) =====
 *
 * Nazwy kolumn i kierunek sortowania NIGDY nie pochodzą ze stringów wejściowych —
 * wyłącznie z białych list poniżej.
 */

/** Kolumny/aliasy dozwolone do sortowania (zgodne z UI index.php). */
const TASKS_SORTABLE = [
    'title', 'sphere', 'status', 'priority', 'due_date', 'scheduled_date',
    'source', 'created_at', 'updated_at', 'completed_at', 'id', 'entries', 'last_entry',
];

/** Kolumny sortowania, dla których stosujemy ISNULL(...) (wartości puste na koniec). */
const TASKS_SORT_NULLS_LAST = ['due_date', 'scheduled_date', 'completed_at', 'last_entry'];

/** Pojedyncze pola edytowalne inline (bez 'status' — ten ma własną funkcję). */
const TASKS_EDITABLE_FIELDS = ['sphere', 'priority', 'due_date', 'scheduled_date'];

/** Kolumny dozwolone przy tworzeniu/edycji zadania. */
const TASKS_WRITABLE = [
    'title', 'description', 'sphere', 'status', 'priority', 'due_date', 'scheduled_date', 'source',
];

/** Biała lista kolumn sortowania — dla UI (index.php). Jedno źródło prawdy. */
function tasks_sortable_columns(): array
{
    return TASKS_SORTABLE;
}

/**
 * Lista zadań z filtrami. Zachowanie identyczne z dotychczasowym index.php.
 *
 * @param array  $filters ['sphere'=>?string, 'status'=>?string, 'q'=>?string]
 * @param string $widok   'tabela' | 'karty'
 */
function tasks_list(array $filters, string $widok, string $sort = '', string $dir = 'asc'): array
{
    $where  = [];
    $params = [];

    $sphere = (string) ($filters['sphere'] ?? '');
    if (isset(SPHERES[$sphere])) {
        $where[]          = 'sphere = :sfera';
        $params[':sfera'] = $sphere;
    }

    $status = (string) ($filters['status'] ?? 'aktywne');
    if ($status === 'aktywne') {
        $where[] = "status IN ('nowe','w_toku','oczekuje')";
    } elseif (isset(STATUSES[$status])) {
        $where[]           = 'status = :status';
        $params[':status'] = $status;
    } // 'wszystkie' => bez warunku

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[]      = '(title LIKE :q OR description LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    $baseCols = 't.id, t.title, t.sphere, t.status, t.priority, t.due_date, t.scheduled_date, t.source';
    if ($widok === 'tabela') {
        $selCols = $baseCols
            . ', t.created_at, t.updated_at, t.completed_at'
            . ', (SELECT COUNT(*) FROM task_entries te WHERE te.task_id = t.id) AS entries'
            . ', (SELECT MAX(te2.created_at) FROM task_entries te2 WHERE te2.task_id = t.id) AS last_entry';
    } else {
        $selCols = $baseCols;
    }

    if ($widok === 'tabela' && $sort !== '' && in_array($sort, TASKS_SORTABLE, true)) {
        $d = (strtolower($dir) === 'desc') ? 'DESC' : 'ASC';
        if (in_array($sort, TASKS_SORT_NULLS_LAST, true)) {
            $orderBy = "ISNULL($sort) ASC, $sort $d, t.id DESC";
        } else {
            $orderBy = "$sort $d, t.id DESC";
        }
    } else {
        $orderBy = 'priority ASC, ISNULL(due_date) ASC, due_date ASC, id DESC';
    }

    $sql = "SELECT $selCols FROM tasks t"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY $orderBy LIMIT 300";

    return db_select_all($sql, $params);
}

/** Pojedyncze zadanie albo null. */
function task_get(int $id): ?array
{
    return db_select_one('SELECT * FROM tasks WHERE id = :id', [':id' => $id]);
}

/** Wpisy dziennika zadania (rosnąco). */
function task_entries(int $id): array
{
    return db_select_all(
        'SELECT * FROM task_entries WHERE task_id = :id ORDER BY created_at ASC, id ASC',
        [':id' => $id]
    );
}

/** Dodanie wpisu do dziennika. Autor spoza słownika → 'ja'. */
function task_entry_add(int $taskId, string $author, string $content): void
{
    if (!isset(AUTHORS[$author])) {
        $author = 'ja';
    }
    db_execute(
        'INSERT INTO task_entries (task_id, author, content) VALUES (:t, :a, :c)',
        [':t' => $taskId, ':a' => $author, ':c' => $content]
    );
}

/**
 * Utworzenie zadania. Wstawiane są wyłącznie kolumny z TASKS_WRITABLE,
 * które faktycznie występują w $data. Gdy brak 'status' → 'nowe'.
 * Obsługuje task_form.php, quick.php, inbox.php (zachowanie 1:1).
 */
function task_create(array $data): int
{
    $cols   = [];
    $place  = [];
    $params = [];
    foreach (TASKS_WRITABLE as $c) {
        if (array_key_exists($c, $data)) {
            $cols[]           = $c;
            $place[]          = ':' . $c;
            $params[':' . $c] = $data[$c];
        }
    }
    if (!in_array('status', $cols, true)) {
        $cols[]             = 'status';
        $place[]            = ':status';
        $params[':status']  = 'nowe';
    }

    $sql = 'INSERT INTO tasks (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $place) . ')';
    return db_insert($sql, $params);
}

/**
 * Pełna edycja zadania (task_form.php).
 * Wariant completed_at = COALESCE(completed_at, NOW()) — zachowuje istniejący
 * znacznik ukończenia. Nie dotyka updated_at (jak w oryginale).
 */
function task_update(int $id, array $data): bool
{
    db_execute(
        "UPDATE tasks SET title = :t, description = :d, sphere = :sf, status = :st,
                priority = :p, due_date = :dd, scheduled_date = :sd, source = :src,
                completed_at = IF(:st2 = 'zrobione', COALESCE(completed_at, NOW()), NULL)
         WHERE id = :id",
        [
            ':t'  => $data['title'],       ':d'  => $data['description'], ':sf'  => $data['sphere'],
            ':st' => $data['status'],      ':p'  => $data['priority'],    ':dd'  => $data['due_date'],
            ':sd' => $data['scheduled_date'], ':src' => $data['source'],
            ':st2' => $data['status'],     ':id' => $id,
        ]
    );
    return true;
}

/**
 * Edycja pojedynczego pola inline (index.php) — sphere/priority/due_date/scheduled_date.
 * Nazwa kolumny wyłącznie z białej listy. Ustawia updated_at = NOW().
 */
function task_update_field(int $id, string $field, string|int|null $value): bool
{
    if (!in_array($field, TASKS_EDITABLE_FIELDS, true)) {
        return false;
    }
    db_execute(
        "UPDATE tasks SET $field = :v, updated_at = NOW() WHERE id = :id",
        [':v' => $value, ':id' => $id]
    );
    return true;
}

/**
 * Zmiana statusu z logiką completed_at = NOW()/NULL (reset).
 * $touchUpdated=true dodaje updated_at=NOW() (wariant inline z index.php);
 * task.php woła bez tego (jak w oryginale).
 */
function task_set_status(int $id, string $status, bool $touchUpdated = false): bool
{
    $set = "status = :s, completed_at = IF(:s2 = 'zrobione', NOW(), NULL)";
    if ($touchUpdated) {
        $set .= ', updated_at = NOW()';
    }
    db_execute("UPDATE tasks SET $set WHERE id = :id", [':s' => $status, ':s2' => $status, ':id' => $id]);
    return true;
}

/** Usunięcie zadania (bez kasowania wpisów dziennika — jak dotychczas). */
function task_delete(int $id): bool
{
    db_execute('DELETE FROM tasks WHERE id = :id', [':id' => $id]);
    return true;
}
