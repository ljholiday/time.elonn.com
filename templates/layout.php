<?php
/** @var string $title */
/** @var array{id: string, email: string, display_name: string|null} $identity */
/** @var string $contentTemplate */
/** @var array<string, mixed> $data */

$displayUser = $identity['display_name'] ?: $identity['email'];
$pageTitle = $title . ' - Elonn Time';
$pageDescription = 'Elonn Time keeps calendars, tasks, and reminders connected to the Elonn identity and runtime system.';
$pageUrl = 'https://time.elonn.com/';
$shareImage = 'https://elonn.com/assets/img/elonn-logo.png';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:site_name" content="Elonn">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($shareImage, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image:secure_url" content="<?= htmlspecialchars($shareImage, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1024">
    <meta property="og:image:height" content="1024">
    <meta property="og:image:alt" content="Elonn logo">
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-LNJE3CGYKC"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', 'G-LNJE3CGYKC');
    </script>
    <link rel="stylesheet" href="/assets/css/time.css">
</head>
<body>
<div class="time-shell">
    <header class="time-topbar">
        <a class="time-brand" href="/">Elonn Time</a>
        <nav class="time-nav" aria-label="Primary">
            <a href="/calendars">Calendars</a>
            <a href="/events">Events</a>
            <a href="/calendars/new">New calendar</a>
            <a href="/events/new">New event</a>
        </nav>
        <div class="time-user"><?= htmlspecialchars($displayUser, ENT_QUOTES, 'UTF-8') ?></div>
    </header>
    <main class="time-main">
        <?php require __DIR__ . '/' . $contentTemplate; ?>
    </main>
</div>
</body>
</html>
