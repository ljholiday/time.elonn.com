<?php
/** @var string $title */
/** @var array{id: string, email: string, display_name: string|null} $identity */
/** @var string $contentTemplate */
/** @var array<string, mixed> $data */

$displayUser = $identity['display_name'] ?: $identity['email'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> - Elonn Time</title>
    <link rel="stylesheet" href="/assets/time.css">
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
