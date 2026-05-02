<?php
/** @var array<string, mixed> $data */
$error = $data['error'] ?? null;
$old = is_array($data['old'] ?? null) ? $data['old'] : [];
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Calendars</p>
        <h1>New calendar</h1>
    </div>
</section>

<form class="time-form" method="post" action="/calendars">
    <?php if (is_string($error)): ?>
        <p class="time-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <label>
        Name
        <input name="name" required value="<?= htmlspecialchars((string) ($old['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <label>
        Color
        <input name="color" placeholder="#173f39" value="<?= htmlspecialchars((string) ($old['color'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <label>
        Timezone
        <input name="timezone" placeholder="America/Los_Angeles" value="<?= htmlspecialchars((string) ($old['timezone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <button type="submit">Create calendar</button>
</form>
