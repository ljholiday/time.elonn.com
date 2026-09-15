<?php
/** @var array<string, mixed> $data */
$error = $data['error'] ?? null;
$old = is_array($data['old'] ?? null) ? $data['old'] : [];
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Calendars</p>
        <h1>New calendar</h1>
        <p class="time-copy">Create a member-owned calendar for Time events and CalDAV clients.</p>
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
        <input name="color" type="color" value="<?= html((string) (($old['color'] ?? '') ?: '#173f39')) ?>">
    </label>
    <label>
        Timezone
        <input name="timezone" list="timezones" placeholder="America/Los_Angeles" value="<?= html((string) ($old['timezone'] ?? '')) ?>">
    </label>
    <datalist id="timezones">
        <option value="America/Los_Angeles"></option>
        <option value="America/Denver"></option>
        <option value="America/Chicago"></option>
        <option value="America/New_York"></option>
        <option value="UTC"></option>
    </datalist>
    <button type="submit">Create calendar</button>
</form>
