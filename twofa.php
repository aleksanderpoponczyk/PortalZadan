<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/twofa_lib.php';

/* ================================================================
   twofa.php — dwa tryby:
   (A) 'code'   — użytkownik przeszedł hasło, czeka na kod 2FA (stan pending)
   (B) 'manage' — zalogowany: panel włącz/wyłącz 2FA, kody, zaufane urządzenia
   ================================================================ */

$pendingUid = twofa_pending_uid();
if (!logged_in() && $pendingUid !== null) {
    $mode = 'code';
    $uid  = $pendingUid;
} elseif (logged_in()) {
    $mode = 'manage';
    $uid  = (int)$_SESSION['uid'];
} else {
    redirect('login.php');
}

$error = null;

/* ---------------------------- POST ---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    /* ===== Tryb kodu przy logowaniu ===== */
    if ($mode === 'code') {
        if ($action === 'verify') {
            $tries = twofa_note_attempt();
            usleep(min($tries, 10) * 200000); // narastające opóźnienie anty-brute-force
            if ($tries > TWOFA_MAX_ATTEMPTS) {
                twofa_clear_pending();
                flash_set('Zbyt wiele prób. Zaloguj się ponownie.');
                redirect('login.php');
            }
            $code  = (string)($_POST['code'] ?? '');
            $trust = !empty($_POST['trust']);

            if (twofa_verify_totp($uid, $code) || twofa_consume_recovery_code($uid, $code)) {
                $u = twofa_user($uid);
                session_regenerate_id(true);
                $_SESSION['uid']   = $uid;
                $_SESSION['uname'] = $u['username'] ?? '';
                twofa_clear_pending();
                if ($trust) {
                    trusted_device_issue($uid);
                }
                flash_set('Zalogowano.');
                redirect('index.php');
            }
            $error = 'Nieprawidłowy kod. Spróbuj ponownie lub użyj kodu odzyskiwania.';
        } elseif ($action === 'cancel') {
            twofa_clear_pending();
            redirect('login.php');
        }
    }

    /* ===== Tryb zarządzania (zalogowany) ===== */
    if ($mode === 'manage') {
        if ($action === 'enable_begin') {
            twofa_begin_enroll($uid);
            redirect('twofa.php');
        } elseif ($action === 'enable_confirm') {
            $code = (string)($_POST['code'] ?? '');
            if (twofa_confirm_enroll($uid, $code)) {
                $_SESSION['show_recovery'] = twofa_generate_recovery_codes($uid);
                flash_set('Dwuskładnikowe uwierzytelnianie włączone.');
                redirect('twofa.php');
            }
            $error = 'Kod nie pasuje. Sprawdź, czy zegar w telefonie jest zsynchronizowany, i spróbuj ponownie.';
        } elseif ($action === 'cancel_enroll') {
            twofa_disable($uid);
            flash_set('Anulowano włączanie 2FA.');
            redirect('twofa.php');
        } elseif ($action === 'regen_recovery') {
            $_SESSION['show_recovery'] = twofa_generate_recovery_codes($uid);
            flash_set('Wygenerowano nowe kody odzyskiwania. Poprzednie przestały działać.');
            redirect('twofa.php');
        } elseif ($action === 'disable') {
            $code = (string)($_POST['code'] ?? '');
            if (twofa_verify_totp($uid, $code) || twofa_consume_recovery_code($uid, $code)) {
                twofa_disable($uid);
                flash_set('Dwuskładnikowe uwierzytelnianie wyłączone.');
                redirect('twofa.php');
            }
            $error = 'Aby wyłączyć 2FA, podaj aktualny kod z aplikacji lub kod odzyskiwania.';
        } elseif ($action === 'revoke_trusted') {
            trusted_devices_revoke_all($uid);
            flash_set('Odwołano wszystkie zaufane urządzenia.');
            redirect('twofa.php');
        }
    }
}

/* ---------------------------- WIDOK: kod przy logowaniu ---------------------------- */
if ($mode === 'code') {
    page_header('Weryfikacja dwuetapowa', false);
    ?>
    <section class="auth">
      <h1>Weryfikacja dwuetapowa</h1>
      <p>Podaj 6-cyfrowy kod z aplikacji uwierzytelniającej albo jeden z kodów odzyskiwania.</p>
      <?php if ($error !== null): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
      <form method="post" action="twofa.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <label>Kod
          <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                 autofocus required placeholder="123456" aria-label="Kod uwierzytelniający">
        </label>
        <label class="check">
          <input type="checkbox" name="trust" value="1"> Ufaj tej przeglądarce przez <?= TRUSTED_DEVICE_DAYS ?> dni
        </label>
        <button type="submit" class="btn primary">Zaloguj</button>
      </form>
      <form method="post" action="twofa.php" class="muted-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn small">Anuluj</button>
      </form>
    </section>
    <?php
    page_footer();
    exit;
}

/* ---------------------------- WIDOK: zarządzanie ---------------------------- */
$u        = twofa_user($uid);
$enabled  = $u !== null && (int)$u['totp_enabled'] === 1;
$midEnroll = !$enabled && !empty($u['totp_secret']);

page_header('Uwierzytelnianie dwuetapowe (2FA)');

/* Kody odzyskiwania do pokazania raz */
if (!empty($_SESSION['show_recovery'])) {
    $codes = $_SESSION['show_recovery'];
    unset($_SESSION['show_recovery']);
    ?>
    <section class="recovery-box">
      <h2>Kody odzyskiwania — zapisz je teraz</h2>
      <p>Zobaczysz je tylko ten jeden raz. Każdy działa jednorazowo, gdy nie masz dostępu do telefonu.</p>
      <ul class="codes">
        <?php foreach ($codes as $c): ?><li class="mono"><?= e($c) ?></li><?php endforeach; ?>
      </ul>
      <p class="kv">Zapisz w menedżerze haseł lub wydrukuj. Nowe kody unieważnią te powyżej.</p>
    </section>
    <?php
}

if ($error !== null) {
    echo '<p class="flash error">' . e($error) . '</p>';
}
?>

<section class="twofa">
<?php if ($enabled): ?>
  <p><strong>2FA jest włączone.</strong> Przy logowaniu w nowej przeglądarce poprosimy o kod.</p>
  <p class="kv">Pozostałe kody odzyskiwania: <strong><?= (int)twofa_recovery_remaining($uid) ?></strong></p>

  <form method="post" action="twofa.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="regen_recovery">
    <button type="submit" class="btn small">Wygeneruj nowe kody odzyskiwania</button>
  </form>

  <details>
    <summary>Wyłącz 2FA</summary>
    <form method="post" action="twofa.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="disable">
      <label>Aby wyłączyć, podaj aktualny kod lub kod odzyskiwania
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required placeholder="123456">
      </label>
      <button type="submit" class="btn small danger">Wyłącz 2FA</button>
    </form>
  </details>

  <h2>Zaufane urządzenia</h2>
  <?php $devs = trusted_devices_list($uid); ?>
  <?php if (!$devs): ?>
    <p class="kv">Brak zaufanych urządzeń.</p>
  <?php else: ?>
    <ul class="devices">
      <?php foreach ($devs as $d): ?>
        <li>
          <span class="mono kv"><?= e(substr((string)$d['user_agent'], 0, 60)) ?></span>
          <span class="kv">dodane: <?= e((string)$d['created_at']) ?> · wygasa: <?= e((string)$d['expires_at']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="twofa.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="revoke_trusted">
      <button type="submit" class="btn small danger">Odwołaj wszystkie zaufane urządzenia</button>
    </form>
  <?php endif; ?>

<?php elseif ($midEnroll): ?>
  <p><strong>Prawie gotowe.</strong> Dodaj konto w aplikacji uwierzytelniającej i potwierdź kodem.</p>
  <?php
    $secret = (string)$u['totp_secret'];
    $grouped = trim(chunk_split($secret, 4, ' '));
    $uri = totp_otpauth_uri($secret, (string)$u['username'], TOTP_ISSUER);
  ?>
  <p>Wpisz ręcznie ten klucz (lub użyj linku <code>otpauth://</code>):</p>
  <p class="secret mono"><?= e($grouped) ?></p>
  <p class="kv">Link do aplikacji: <br><code class="mono break"><?= e($uri) ?></code></p>

  <form method="post" action="twofa.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="enable_confirm">
    <label>Kod z aplikacji
      <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required placeholder="123456">
    </label>
    <button type="submit" class="btn primary">Potwierdź i włącz</button>
  </form>
  <form method="post" action="twofa.php" class="muted-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel_enroll">
    <button type="submit" class="btn small">Anuluj</button>
  </form>

<?php else: ?>
  <p>2FA jest <strong>wyłączone</strong>. Po włączeniu logowanie w nowej przeglądarce będzie wymagać kodu z aplikacji (np. Google Authenticator, Aegis).</p>
  <form method="post" action="twofa.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="enable_begin">
    <button type="submit" class="btn primary">Włącz 2FA</button>
  </form>
<?php endif; ?>

  <p class="kv"><a href="index.php">← Powrót do zadań</a></p>
</section>
<?php
page_footer();
