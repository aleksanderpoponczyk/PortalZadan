<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/twofa_lib.php';
require __DIR__ . '/inc/repo_users.php';
require __DIR__ . '/inc/auth_guard.php';

if (logged_in()) {
    redirect('index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (auth_throttle($username) === 'deny') {
        auth_log_event('login_denied');
        usleep(500000);
        $error = 'Nieprawidłowy login lub hasło.';
    } else {
        $user = user_by_username($username);

        if ($user && password_verify($password, $user['password_hash'])) {
            $uid = (int)$user['id'];

            // Ciche podbicie hasha: Argon2id jeśli serwer wspiera, inaczej bcrypt.
            $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            if (password_needs_rehash($user['password_hash'], $algo)) {
                $newHash = password_hash($password, $algo);
                if (is_string($newHash) && $newHash !== '') {
                    user_touch_password($uid, $newHash);
                }
            }

            // Jeśli włączone 2FA i to nie jest zaufana przeglądarka — stan „pending".
            if (twofa_is_enabled($uid) && !trusted_cookie_valid($uid)) {
                twofa_set_pending($uid);
                redirect('twofa.php');
            }

            session_regenerate_id(true);
            $_SESSION['uid']   = $uid;
            $_SESSION['uname'] = $user['username'];
            auth_log_event('login_ok', $uid);
            redirect('index.php');
        }

        // Nieudane: stały czas gdy konto nie istnieje (dummy-hash), zapis zdarzenia.
        if (!$user) {
            password_verify($password, AUTH_DUMMY_HASH);
        }
        auth_log_event('login_fail', $user ? (int)$user['id'] : null);
        usleep(500000);
        $error = 'Nieprawidłowy login lub hasło.';
    }
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
      <button type="submit" class="btn primary btn-block">Zaloguj</button>
    </div>
  </form>
</div>
<?php page_footer();
