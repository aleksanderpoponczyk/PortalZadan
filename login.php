<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (logged_in()) {
    redirect('index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $st = pdo()->prepare('SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1');
    $st->execute([':u' => $username]);
    $user = $st->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid']   = (int)$user['id'];
        $_SESSION['uname'] = $user['username'];
        redirect('index.php');
    }

    usleep(500000); // spowolnienie prób zgadywania hasła
    $error = 'Nieprawidłowy login lub hasło.';
}

page_header('Logowanie', withNav: false);
?>
<div class="login-card">
  <h1>Zadania·AP</h1>
  <p class="kv">Prywatny portal zadań. Zaloguj się, aby kontynuować.</p>
  <?php if ($error): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="login.php">
    <?= csrf_field() ?>
    <label for="username">Login</label>
    <input type="text" id="username" name="username" autocomplete="username" required autofocus>
    <label for="password">Hasło</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
    <div class="actions">
      <button type="submit" class="btn primary" style="width:100%">Zaloguj</button>
    </div>
  </form>
</div>
<?php page_footer();
