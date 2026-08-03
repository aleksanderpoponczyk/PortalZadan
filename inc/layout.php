<?php
declare(strict_types=1);

function page_header(string $title, bool $withNav = true): void
{
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#F6F5F1" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#151913" media="(prefers-color-scheme: dark)">
<title><?= e($title) ?> · Zadania·AP</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if ($withNav): ?>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="index.php">Zadania·AP</a>
    <a class="btn small" href="quick.php" id="nav-quick">Wklej</a>
    <a class="btn small primary" href="task_form.php" id="nav-new">+ Nowe</a>
    <a class="btn small" href="logout.php" title="Wyloguj">⎋</a>
  </div>
</header>
<?php endif; ?>
<main class="wrap">
<?php
    $f = flash_get();
    if ($f !== null) {
        echo '<p class="flash">' . e($f) . '</p>';
    }
}

function page_footer(): void
{
    ?>
</main>
</body>
</html>
<?php
}
