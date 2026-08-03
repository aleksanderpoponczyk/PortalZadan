<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $content = trim(str_replace("\r\n", "\n", (string)($_POST['content'] ?? '')));
    $sphere  = isset(SPHERES[$_POST['sphere'] ?? '']) ? $_POST['sphere'] : 'prywatne';
    $prio    = (int)($_POST['priority'] ?? 3);
    $prio    = isset(PRIORITIES[$prio]) ? $prio : 3;
    $due     = valid_date($_POST['due_date'] ?? null);
    $firstLineAsTitle = isset($_POST['first_line_title']);

    if ($content === '') {
        $error = 'Wklej najpierw jakąś treść.';
    } else {
        if ($firstLineAsTitle && str_contains($content, "\n")) {
            $nl          = strpos($content, "\n");
            $title       = trim(substr($content, 0, $nl));
            $description = trim(substr($content, $nl + 1));
        } elseif ($firstLineAsTitle) {
            $title       = $content;
            $description = '';
        } else {
            $title       = $content;
            $description = $content;
        }

        if (mb_strlen($title) > 160) {
            $title = mb_substr($title, 0, 159) . '…';
        }
        if ($title === '') {
            $title = 'Nowe zadanie ' . date('Y-m-d H:i');
        }

        $st = pdo()->prepare(
            'INSERT INTO tasks (title, description, sphere, status, priority, due_date, source)
             VALUES (:t, :d, :sf, \'nowe\', :p, :dd, :src)'
        );
        $st->execute([
            ':t' => $title, ':d' => $description, ':sf' => $sphere,
            ':p' => $prio, ':dd' => $due, ':src' => 'wklejka',
        ]);
        $id = (int)pdo()->lastInsertId();
        flash_set('Utworzono zadanie #' . $id . ' z wklejonej treści.');
        redirect('task.php?id=' . $id);
    }
}

page_header('Wklej i utwórz');
?>
<h1>Wklej i utwórz zadanie</h1>
<p class="kv">Wklej transkrypcję nagrania, treść maila z Outlooka, wiadomość z Teamsa
albo notatkę z rozmowy z AI — powstanie z tego zadanie.</p>
<?php if ($error): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>

<form method="post" action="quick.php" id="quick-form">
  <?= csrf_field() ?>

  <label for="q-content">Treść</label>
  <textarea id="q-content" name="content" rows="12" required autofocus
            placeholder="Pierwsza linia stanie się tytułem (poniżej możesz to wyłączyć)…"></textarea>

  <label class="check">
    <input type="checkbox" name="first_line_title" checked>
    Pierwsza linia to tytuł, reszta to opis
  </label>

  <label for="q-sphere">Sfera</label>
  <select id="q-sphere" name="sphere">
    <?php foreach (SPHERES as $k => $label): ?>
      <option value="<?= e($k) ?>"><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="q-priority">Priorytet</label>
  <select id="q-priority" name="priority">
    <?php foreach (PRIORITIES as $k => $label): ?>
      <option value="<?= (int)$k ?>" <?= $k === 3 ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="q-due">Termin (opcjonalnie)</label>
  <input type="date" id="q-due" name="due_date">

  <div class="actions">
    <button type="submit" class="btn primary" id="quick-submit">Utwórz zadanie</button>
  </div>
</form>
<?php page_footer();
