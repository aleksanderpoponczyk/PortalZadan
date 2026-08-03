<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$error  = null;

$t = [
    'title'          => '',
    'description'    => '',
    'sphere'         => 'prywatne',
    'status'         => 'nowe',
    'priority'       => 3,
    'due_date'       => null,
    'scheduled_date' => null,
    'source'         => 'ręcznie',
];

if ($isEdit) {
    $st = pdo()->prepare('SELECT * FROM tasks WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) {
        flash_set('Nie znaleziono zadania #' . $id . '.');
        redirect('index.php');
    }
    $t = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $t['title']          = trim((string)($_POST['title'] ?? ''));
    $t['description']    = trim((string)($_POST['description'] ?? ''));
    $t['sphere']         = isset(SPHERES[$_POST['sphere'] ?? '']) ? $_POST['sphere'] : 'prywatne';
    $t['status']         = isset(STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : 'nowe';
    $prio                = (int)($_POST['priority'] ?? 3);
    $t['priority']       = isset(PRIORITIES[$prio]) ? $prio : 3;
    $t['due_date']       = valid_date($_POST['due_date'] ?? null);
    $t['scheduled_date'] = valid_date($_POST['scheduled_date'] ?? null);
    $t['source']         = trim((string)($_POST['source'] ?? '')) ?: 'ręcznie';

    if ($t['title'] === '') {
        $error = 'Tytuł zadania jest wymagany.';
    } else {
        if ($isEdit) {
            $st = pdo()->prepare(
                'UPDATE tasks SET title = :t, description = :d, sphere = :sf, status = :st,
                        priority = :p, due_date = :dd, scheduled_date = :sd, source = :src,
                        completed_at = IF(:st2 = \'zrobione\', COALESCE(completed_at, NOW()), NULL)
                 WHERE id = :id'
            );
            $st->execute([
                ':t' => $t['title'], ':d' => $t['description'], ':sf' => $t['sphere'],
                ':st' => $t['status'], ':p' => $t['priority'], ':dd' => $t['due_date'],
                ':sd' => $t['scheduled_date'], ':src' => $t['source'],
                ':st2' => $t['status'], ':id' => $id,
            ]);
            flash_set('Zapisano zmiany w zadaniu #' . $id . '.');
        } else {
            $st = pdo()->prepare(
                'INSERT INTO tasks (title, description, sphere, status, priority, due_date, scheduled_date, source)
                 VALUES (:t, :d, :sf, :st, :p, :dd, :sd, :src)'
            );
            $st->execute([
                ':t' => $t['title'], ':d' => $t['description'], ':sf' => $t['sphere'],
                ':st' => $t['status'], ':p' => $t['priority'], ':dd' => $t['due_date'],
                ':sd' => $t['scheduled_date'], ':src' => $t['source'],
            ]);
            $id = (int)pdo()->lastInsertId();
            flash_set('Utworzono zadanie #' . $id . '.');
        }
        redirect('task.php?id=' . $id);
    }
}

page_header($isEdit ? 'Edycja #' . $id : 'Nowe zadanie');
?>
<h1><?= $isEdit ? 'Edycja zadania #' . (int)$id : 'Nowe zadanie' ?></h1>
<?php if ($error): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>

<form method="post" action="task_form.php<?= $isEdit ? '?id=' . (int)$id : '' ?>" id="task-form">
  <?= csrf_field() ?>

  <label for="f-title">Tytuł *</label>
  <input type="text" id="f-title" name="title" value="<?= e((string)$t['title']) ?>" required maxlength="255">

  <label for="f-sphere">Sfera</label>
  <select id="f-sphere" name="sphere">
    <?php foreach (SPHERES as $k => $label): ?>
      <option value="<?= e($k) ?>" <?= $t['sphere'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="f-priority">Priorytet</label>
  <select id="f-priority" name="priority">
    <?php foreach (PRIORITIES as $k => $label): ?>
      <option value="<?= (int)$k ?>" <?= (int)$t['priority'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="f-status">Status</label>
  <select id="f-status" name="status">
    <?php foreach (STATUSES as $k => $label): ?>
      <option value="<?= e($k) ?>" <?= $t['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="f-due">Termin (deadline)</label>
  <input type="date" id="f-due" name="due_date" value="<?= e((string)$t['due_date']) ?>">

  <label for="f-scheduled">Zaplanowane na dzień</label>
  <input type="date" id="f-scheduled" name="scheduled_date" value="<?= e((string)$t['scheduled_date']) ?>">

  <label for="f-source">Źródło</label>
  <input type="text" id="f-source" name="source" value="<?= e((string)$t['source']) ?>"
         placeholder="ręcznie / outlook / teams / transkrypcja…" maxlength="50">

  <label for="f-description">Opis</label>
  <textarea id="f-description" name="description" rows="10"
            placeholder="Pełna treść zadania, kontekst, kryteria ukończenia…"><?= e((string)$t['description']) ?></textarea>

  <div class="actions">
    <button type="submit" class="btn primary" id="task-save"><?= $isEdit ? 'Zapisz zmiany' : 'Utwórz zadanie' ?></button>
    <a class="btn" href="<?= $isEdit ? 'task.php?id=' . (int)$id : 'index.php' ?>">Anuluj</a>
  </div>
</form>
<?php page_footer();
