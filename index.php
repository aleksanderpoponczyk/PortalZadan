<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/repo_tasks.php';
require __DIR__ . '/inc/repo_prefs.php';
require __DIR__ . '/inc/layout.php';
require_login();

/* ================================================================
   Widok listy zadań: karty (domyślnie) + tabela z konfigurowalnymi
   kolumnami, sortowaniem po nagłówkach i edycją inline.
   Karty renderują się identycznie jak wcześniej.
   ================================================================ */

/* ---- Rejestr kolumn tabeli (whitelisty) ---- */
const IDX_ALL_COLS = [
    'sphere'         => 'Sfera',
    'status'         => 'Status',
    'priority'       => 'Priorytet',
    'due_date'       => 'Termin',
    'scheduled_date' => 'Plan',
    'source'         => 'Źródło',
    'created_at'     => 'Utworzono',
    'updated_at'     => 'Zmieniono',
    'completed_at'   => 'Zrobiono',
    'entries'        => 'Wpisy',
    'last_entry'     => 'Ost. wpis',
];
const IDX_DEFAULT_COLS = ['sphere', 'status', 'priority', 'due_date'];
const IDX_EDITABLE = ['sphere', 'status', 'priority', 'due_date', 'scheduled_date'];
const IDX_SORTABLE = [
    'title', 'sphere', 'status', 'priority', 'due_date', 'scheduled_date',
    'source', 'created_at', 'updated_at', 'completed_at', 'id', 'entries', 'last_entry',
];

/* ---- Kontekst widoku (walidowany), do zachowania w URL-ach i przekierowaniach ---- */
function current_ctx(): array
{
    $ctx = [];
    if (($_GET['widok'] ?? '') === 'tabela') {
        $ctx['widok'] = 'tabela';
    }
    $sfera = (string)($_GET['sfera'] ?? '');
    if (isset(SPHERES[$sfera])) {
        $ctx['sfera'] = $sfera;
    }
    $status = (string)($_GET['status'] ?? 'aktywne');
    if ($status !== 'aktywne' && ($status === 'wszystkie' || isset(STATUSES[$status]))) {
        $ctx['status'] = $status; // 'aktywne' to domyślne — pomijamy dla czystych URL-i
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $ctx['q'] = $q;
    }
    $sort = (string)($_GET['sort'] ?? '');
    if (in_array($sort, IDX_SORTABLE, true)) {
        $ctx['sort'] = $sort;
    }
    $dir = strtolower((string)($_GET['dir'] ?? ''));
    if ($dir === 'asc' || $dir === 'desc') {
        $ctx['dir'] = $dir;
    }
    return $ctx;
}

function ctx_url(array $overrides = []): string
{
    $ctx = array_merge(current_ctx(), $overrides);
    // usuń klucze o wartości null (pozwala kasować parametry)
    $ctx = array_filter($ctx, static fn($v) => $v !== null && $v !== '');
    return 'index.php' . ($ctx ? '?' . http_build_query($ctx) : '');
}

/* ================================================================
   POST: edycja inline + zapis wyboru kolumn
   ================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'set_field') {
        $id    = (int)($_POST['id'] ?? 0);
        $field = (string)($_POST['field'] ?? '');
        $value = (string)($_POST['value'] ?? '');

        if ($id > 0 && in_array($field, IDX_EDITABLE, true)) {
            $ok   = false;
            $bind = null;

            if ($field === 'sphere' && isset(SPHERES[$value])) {
                $ok = true;
                $bind = $value;
            } elseif ($field === 'status' && isset(STATUSES[$value])) {
                $ok = true;
                $bind = $value;
            } elseif ($field === 'priority') {
                $p = (int)$value;
                if ($p >= 1 && $p <= 5) {
                    $ok = true;
                    $bind = $p;
                }
            } elseif ($field === 'due_date' || $field === 'scheduled_date') {
                $ok = true;
                $bind = valid_date($value); // null = wyczyszczenie daty
            }

            if ($ok) {
                if ($field === 'status') {
                    task_set_status($id, $bind, true);
                } else {
                    // $field pochodzi z IDX_EDITABLE (whitelist) — bezpieczne w nazwie kolumny
                    task_update_field($id, $field, $bind);
                }
                flash_set('Zapisano zmianę.');
            }
        }
        redirect(ctx_url());
    }

    if ($action === 'save_cols') {
        $chosen = $_POST['cols'] ?? [];
        if (!is_array($chosen)) {
            $chosen = [];
        }
        $valid = array_values(array_intersect(array_keys(IDX_ALL_COLS), $chosen));
        prefs_set((int)($_SESSION['uid'] ?? 0), 'index_cols', implode(',', $valid));
        flash_set('Zapisano układ kolumn.');
        redirect(ctx_url(['widok' => 'tabela']));
    }

    // nieznana akcja — wróć do listy
    redirect(ctx_url());
}

/* ================================================================
   GET: parametry widoku (walidowane) + zapytanie
   ================================================================ */
$widok  = (($_GET['widok'] ?? '') === 'tabela') ? 'tabela' : 'karty';
$sphere = (string)($_GET['sfera'] ?? '');
$status = (string)($_GET['status'] ?? 'aktywne');
$q      = trim((string)($_GET['q'] ?? ''));
$sort   = in_array((string)($_GET['sort'] ?? ''), IDX_SORTABLE, true) ? (string)$_GET['sort'] : '';
$dir    = (strtolower((string)($_GET['dir'] ?? '')) === 'desc') ? 'desc' : 'asc';

$tasks = tasks_list(
    ['sphere' => $sphere, 'status' => $status, 'q' => $q],
    $widok,
    $sort,
    $dir
);

$today = date('Y-m-d');

/* Wybrane kolumny (z preferencji użytkownika) */
$savedCols = prefs_get((int)($_SESSION['uid'] ?? 0), 'index_cols', null);
if ($savedCols !== null) {
    $cols = array_values(array_intersect(array_keys(IDX_ALL_COLS), explode(',', $savedCols)));
} else {
    $cols = IDX_DEFAULT_COLS;
}

/* ---- Pomocnicze renderowanie ---- */
function idx_sort_link(string $col, string $label): string
{
    $ctx     = current_ctx();
    $curSort = $ctx['sort'] ?? '';
    $curDir  = $ctx['dir'] ?? 'asc';
    $newDir  = ($curSort === $col && $curDir === 'asc') ? 'desc' : 'asc';
    $arrow   = $curSort === $col ? ($curDir === 'asc' ? ' ▲' : ' ▼') : '';
    $url     = ctx_url(['widok' => 'tabela', 'sort' => $col, 'dir' => $newDir]);
    return '<a href="' . e($url) . '">' . e($label) . e($arrow) . '</a>';
}

/**
 * Komórka edytowalna inline: mini-formularz POST (auto-submit przez JS,
 * z fallbackiem do przycisku „✓" gdy JS wyłączony).
 */
function idx_edit_cell(array $t, string $field): string
{
    $id     = (int)$t['id'];
    $action = e(ctx_url());
    $csrf   = csrf_field();
    $val    = (string)($t[$field] ?? '');

    ob_start();
    ?>
    <form method="post" action="<?= $action ?>" class="cellf">
      <?= $csrf ?>
      <input type="hidden" name="action" value="set_field">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="field" value="<?= e($field) ?>">
      <?php if ($field === 'sphere'): ?>
        <select name="value" class="cell-input" aria-label="Sfera zadania <?= $id ?>">
          <?php foreach (SPHERES as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $val === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      <?php elseif ($field === 'status'): ?>
        <select name="value" class="cell-input" aria-label="Status zadania <?= $id ?>">
          <?php foreach (STATUSES as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $val === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      <?php elseif ($field === 'priority'): ?>
        <select name="value" class="cell-input" aria-label="Priorytet zadania <?= $id ?>">
          <?php for ($p = 1; $p <= 5; $p++): ?>
            <option value="<?= $p ?>" <?= (int)$val === $p ? 'selected' : '' ?>>P<?= $p ?></option>
          <?php endfor; ?>
        </select>
      <?php else: /* due_date / scheduled_date */ ?>
        <input type="date" name="value" value="<?= e($val) ?>" class="cell-input"
               aria-label="<?= $field === 'due_date' ? 'Termin' : 'Plan' ?> zadania <?= $id ?>">
      <?php endif; ?>
      <button type="submit" class="btn small cell-save" title="Zapisz">✓</button>
    </form>
    <?php
    return (string)ob_get_clean();
}

function idx_cell_value(array $t, string $col): string
{
    switch ($col) {
        case 'source':
            return $t['source'] !== null && $t['source'] !== ''
                ? '<span class="mono kv">' . e((string)$t['source']) . '</span>' : '—';
        case 'created_at':
        case 'updated_at':
        case 'completed_at':
        case 'last_entry':
            return $t[$col] ? '<span class="mono kv">' . e((string)$t[$col]) . '</span>' : '—';
        case 'entries':
            return '<span class="kv">' . (int)($t['entries'] ?? 0) . '</span>';
        default:
            return e((string)($t[$col] ?? ''));
    }
}

page_header('Zadania');
?>
<div class="actions">
  <a class="btn small" href="<?= e(ctx_url(['widok' => $widok === 'tabela' ? null : 'tabela', 'sort' => null, 'dir' => null])) ?>">
    <?= $widok === 'tabela' ? '▤ Widok kart' : '▦ Widok tabeli' ?>
  </a>
</div>

<form class="filterbar" method="get" action="index.php" id="filter-form">
  <?php if ($widok === 'tabela'): ?>
    <input type="hidden" name="widok" value="tabela">
    <?php if ($sort !== ''): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
    <?php if ($sort !== ''): ?><input type="hidden" name="dir" value="<?= e($dir) ?>"><?php endif; ?>
  <?php endif; ?>
  <select name="sfera" id="filter-sfera" aria-label="Sfera">
    <option value="">Obie sfery</option>
    <?php foreach (SPHERES as $k => $label): ?>
      <option value="<?= e($k) ?>" <?= $sphere === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status" id="filter-status" aria-label="Status">
    <option value="aktywne" <?= $status === 'aktywne' ? 'selected' : '' ?>>Aktywne</option>
    <?php foreach (STATUSES as $k => $label): ?>
      <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
    <option value="wszystkie" <?= $status === 'wszystkie' ? 'selected' : '' ?>>Wszystkie</option>
  </select>
  <input type="search" name="q" id="filter-q" value="<?= e($q) ?>" placeholder="Szukaj…" aria-label="Szukaj">
  <button type="submit" class="btn">Filtruj</button>
</form>

<?php if ($widok === 'tabela'): ?>
  <details class="colpick">
    <summary>Kolumny (<?= count($cols) ?>)</summary>
    <form method="post" action="<?= e(ctx_url()) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_cols">
      <div class="cols">
        <?php foreach (IDX_ALL_COLS as $k => $label): ?>
          <label class="check">
            <input type="checkbox" name="cols[]" value="<?= e($k) ?>" <?= in_array($k, $cols, true) ? 'checked' : '' ?>>
            <?= e($label) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="actions">
        <button type="submit" class="btn small primary">Zapisz kolumny</button>
      </div>
    </form>
  </details>
<?php endif; ?>

<?php if (!$tasks): ?>
  <div class="empty">
    Brak zadań w tym widoku.<br>
    Dodaj pierwsze przyciskiem „+ Nowe" albo wklej notatkę przez „Wklej".
  </div>

<?php elseif ($widok === 'tabela'): ?>
  <div class="table-wrap">
    <table class="tasks">
      <thead>
        <tr>
          <th class="col-title"><?= idx_sort_link('title', 'Zadanie') ?></th>
          <?php foreach ($cols as $col): ?>
            <th class="col-<?= e($col) ?>">
              <?= in_array($col, IDX_SORTABLE, true) ? idx_sort_link($col, IDX_ALL_COLS[$col]) : e(IDX_ALL_COLS[$col]) ?>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tasks as $t):
            $isActive = in_array($t['status'], ACTIVE_STATUSES, true);
            $overdue  = $isActive && $t['due_date'] !== null && $t['due_date'] < $today;
        ?>
          <tr class="p<?= (int)$t['priority'] ?>" data-task-id="<?= (int)$t['id'] ?>">
            <td class="col-title">
              <a class="task-title" href="task.php?id=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
              <span class="mono kv">#<?= (int)$t['id'] ?></span>
            </td>
            <?php foreach ($cols as $col): ?>
              <td class="col-<?= e($col) ?>">
                <?php if (in_array($col, IDX_EDITABLE, true)): ?>
                  <?= idx_edit_cell($t, $col) ?>
                  <?php if ($col === 'due_date' && $overdue): ?><span class="due over mono">po terminie</span><?php endif; ?>
                <?php else: ?>
                  <?= idx_cell_value($t, $col) ?>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <script>
  (function () {
    document.querySelectorAll('.cell-save').forEach(function (b) { b.style.display = 'none'; });
    document.querySelectorAll('.cellf .cell-input').forEach(function (el) {
      el.addEventListener('change', function () { if (el.form) el.form.submit(); });
    });
  })();
  </script>

<?php else: /* ---- widok kart (bez zmian) ---- */ ?>
  <section id="task-list">
  <?php foreach ($tasks as $t):
      $isActive = in_array($t['status'], ACTIVE_STATUSES, true);
      $overdue  = $isActive && $t['due_date'] !== null && $t['due_date'] < $today;
  ?>
    <article class="task p<?= (int)$t['priority'] ?>" data-task-id="<?= (int)$t['id'] ?>">
      <a class="task-title" href="task.php?id=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
      <div class="meta">
        <span class="badge <?= $t['sphere'] === 'sluzbowe' ? 'work' : 'priv' ?>"><?= e(SPHERES[$t['sphere']]) ?></span>
        <span class="badge st-<?= e($t['status']) ?>"><?= e(STATUSES[$t['status']]) ?></span>
        <span class="badge" title="<?= e(PRIORITIES[(int)$t['priority']] ?? '') ?>">P<?= (int)$t['priority'] ?></span>
        <?php if ($t['due_date']): ?>
          <span class="due mono <?= $overdue ? 'over' : '' ?>">⏱ <?= e($t['due_date']) ?><?= $overdue ? ' · po terminie' : '' ?></span>
        <?php endif; ?>
        <?php if ($t['scheduled_date']): ?>
          <span class="mono kv">plan: <?= e($t['scheduled_date']) ?></span>
        <?php endif; ?>
        <span class="mono kv">#<?= (int)$t['id'] ?></span>
      </div>
    </article>
  <?php endforeach; ?>
  </section>
<?php endif; ?>
<?php page_footer();
