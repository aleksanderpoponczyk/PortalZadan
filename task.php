<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/repo_tasks.php';
require __DIR__ . '/inc/layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('index.php');
}

/* ---------- Akcje POST (wpis, status, usunięcie) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_entry') {
        $author  = (string)($_POST['author'] ?? 'ja');
        if (!isset(AUTHORS[$author])) {
            $author = 'ja';
        }
        $content = trim((string)($_POST['content'] ?? ''));
        if ($content !== '') {
            task_entry_add($id, $author, $content);
            flash_set('Dodano wpis.');
        }
    } elseif ($action === 'set_status') {
        $newStatus = (string)($_POST['status'] ?? '');
        if (isset(STATUSES[$newStatus])) {
            task_set_status($id, $newStatus);
            flash_set('Status zmieniony na: ' . STATUSES[$newStatus] . '.');
        }
    } elseif ($action === 'delete_task') {
        task_delete($id);
        flash_set('Zadanie #' . $id . ' usunięte.');
        redirect('index.php');
    }

    redirect('task.php?id=' . $id);
}

/* ---------- Pobranie danych ---------- */

$t = task_get($id);

if (!$t) {
    flash_set('Nie znaleziono zadania #' . $id . '.');
    redirect('index.php');
}

$entries = task_entries($id);

/* ---------- Kontekst tekstowy dla agenta AI ---------- */

$agent  = 'ZADANIE #' . $t['id'] . ': ' . $t['title'] . "\n";
$agent .= 'Sfera: ' . SPHERES[$t['sphere']]
        . ' | Status: ' . STATUSES[$t['status']]
        . ' | Priorytet: P' . $t['priority']
        . ' | Termin: ' . ($t['due_date'] ?: '—')
        . ' | Zaplanowane: ' . ($t['scheduled_date'] ?: '—') . "\n";
$agent .= 'Utworzono: ' . $t['created_at'] . ' | Źródło: ' . ($t['source'] ?: '—') . "\n\n";
$agent .= "OPIS:\n" . ($t['description'] !== null && $t['description'] !== '' ? $t['description'] : '(brak opisu)') . "\n\n";
$agent .= 'DZIENNIK (' . count($entries) . " wpisów, chronologicznie):\n";
foreach ($entries as $en) {
    $agent .= '[' . $en['created_at'] . '] ' . mb_strtoupper($en['author']) . ":\n" . $en['content'] . "\n---\n";
}
$agent .= "\nINSTRUKCJA DLA AGENTA AI:\n"
        . "- Nowy wpis: formularz #entry-form -> autor 'AI' (#entry-author), treść (#entry-content), przycisk #entry-submit.\n"
        . "- Zmiana statusu: przyciski w sekcji #status-actions.\n"
        . "- Edycja pól zadania: link #edit-link.";

$today   = date('Y-m-d');
$overdue = in_array($t['status'], ACTIVE_STATUSES, true) && $t['due_date'] !== null && $t['due_date'] < $today;

page_header('#' . $t['id'] . ' ' . $t['title']);
?>
<h1><?= e($t['title']) ?></h1>

<div class="meta">
  <span class="badge <?= $t['sphere'] === 'sluzbowe' ? 'work' : 'priv' ?>"><?= e(SPHERES[$t['sphere']]) ?></span>
  <span class="badge st-<?= e($t['status']) ?>"><?= e(STATUSES[$t['status']]) ?></span>
  <span class="badge" title="<?= e(PRIORITIES[(int)$t['priority']] ?? '') ?>"><?= e(PRIORITIES[(int)$t['priority']] ?? 'P' . $t['priority']) ?></span>
  <?php if ($t['due_date']): ?>
    <span class="due mono <?= $overdue ? 'over' : '' ?>">termin: <?= e($t['due_date']) ?></span>
  <?php endif; ?>
  <?php if ($t['scheduled_date']): ?>
    <span class="mono kv">plan: <?= e($t['scheduled_date']) ?></span>
  <?php endif; ?>
  <span class="mono kv">#<?= (int)$t['id'] ?> · <?= e($t['source'] ?: '—') ?> · <?= e($t['created_at']) ?></span>
</div>

<?php if ($t['description'] !== null && $t['description'] !== ''): ?>
  <div class="desc"><?= e($t['description']) ?></div>
<?php endif; ?>

<div class="actions" id="status-actions">
  <?php foreach (STATUSES as $k => $label): if ($k === $t['status']) continue; ?>
    <form method="post" action="task.php?id=<?= (int)$t['id'] ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_status">
      <input type="hidden" name="status" value="<?= e($k) ?>">
      <button type="submit" class="btn small" id="status-<?= e($k) ?>">→ <?= e($label) ?></button>
    </form>
  <?php endforeach; ?>
  <a class="btn small" href="task_form.php?id=<?= (int)$t['id'] ?>" id="edit-link">Edytuj</a>
  <form method="post" action="task.php?id=<?= (int)$t['id'] ?>"
        onsubmit="return confirm('Na pewno usunąć zadanie #<?= (int)$t['id'] ?> wraz z dziennikiem?');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_task">
    <button type="submit" class="btn small danger">Usuń</button>
  </form>
</div>

<h2>Dziennik / konwersacja</h2>
<section class="entries" id="entries">
  <?php if (!$entries): ?>
    <p class="kv">Brak wpisów. Poniżej dodasz pierwszy — Ty albo agent AI.</p>
  <?php endif; ?>
  <?php foreach ($entries as $en): ?>
    <article class="entry" data-entry-id="<?= (int)$en['id'] ?>">
      <div class="entry-head">
        <span class="who <?= e($en['author']) ?>"><?= e(AUTHORS[$en['author']] ?? $en['author']) ?></span>
        <span class="mono"><?= e($en['created_at']) ?></span>
      </div>
      <div class="entry-body"><?= e($en['content']) ?></div>
    </article>
  <?php endforeach; ?>
</section>

<form method="post" action="task.php?id=<?= (int)$t['id'] ?>" id="entry-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add_entry">
  <label for="entry-author">Autor wpisu</label>
  <select name="author" id="entry-author">
    <?php foreach (AUTHORS as $k => $label): ?>
      <option value="<?= e($k) ?>"><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <label for="entry-content">Treść wpisu</label>
  <textarea name="content" id="entry-content" rows="6" required
            placeholder="Notatka, wynik pracy AI, następny krok…"></textarea>
  <div class="actions">
    <button type="submit" class="btn primary" id="entry-submit">Dodaj wpis</button>
  </div>
</form>

<details class="agent" id="agent-context">
  <summary>Kontekst dla agenta AI (pełny tekst zadania)</summary>
  <pre id="agent-context-text"><?= e($agent) ?></pre>
</details>
<?php page_footer();
