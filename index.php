<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$sphere = (string)($_GET['sfera'] ?? '');
$status = (string)($_GET['status'] ?? 'aktywne');
$q      = trim((string)($_GET['q'] ?? ''));

$where  = [];
$params = [];

if (isset(SPHERES[$sphere])) {
    $where[] = 'sphere = :sfera';
    $params[':sfera'] = $sphere;
}

if ($status === 'aktywne') {
    $where[] = "status IN ('nowe','w_toku','oczekuje')";
} elseif (isset(STATUSES[$status])) {
    $where[] = 'status = :status';
    $params[':status'] = $status;
} // 'wszystkie' => bez warunku

if ($q !== '') {
    $where[] = '(title LIKE :q OR description LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

$sql = 'SELECT id, title, sphere, status, priority, due_date, scheduled_date, source
        FROM tasks'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY priority ASC, ISNULL(due_date) ASC, due_date ASC, id DESC
        LIMIT 300';

$st = pdo()->prepare($sql);
$st->execute($params);
$tasks = $st->fetchAll();

$today = date('Y-m-d');

page_header('Zadania');
?>
<form class="filterbar" method="get" action="index.php" id="filter-form">
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

<?php if (!$tasks): ?>
  <div class="empty">
    Brak zadań w tym widoku.<br>
    Dodaj pierwsze przyciskiem „+ Nowe" albo wklej notatkę przez „Wklej".
  </div>
<?php else: ?>
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
