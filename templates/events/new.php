<?php
/** @var array<string, mixed> $data */
$error = $data['error'] ?? null;
$old = is_array($data['old'] ?? null) ? $data['old'] : [];
$calendars = is_array($data['calendars'] ?? null) ? $data['calendars'] : [];
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Events</p>
        <h1>New event</h1>
        <p class="time-copy">Create a Time-owned calendar event. Social-owned event mirrors stay read-only here.</p>
    </div>
</section>

<?php if ($calendars === []): ?>
    <p class="time-empty">Create a calendar before adding events.</p>
    <p><a class="button" href="/calendars/new">Create calendar</a></p>
<?php else: ?>
    <form class="time-form" method="post" action="/events">
        <?php if (is_string($error)): ?>
            <p class="time-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <label>
            Calendar
            <select name="calendar_id" required>
                <?php foreach ($calendars as $calendar): ?>
                    <?php $selected = (string) ($old['calendar_id'] ?? '') === (string) $calendar['id']; ?>
                    <option value="<?= (int) $calendar['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $calendar['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Title
            <input name="title" required autocomplete="off" value="<?= html((string) ($old['title'] ?? '')) ?>">
        </label>
        <div class="time-form-grid">
            <label>
                Starts
                <input name="starts_at" type="datetime-local" required value="<?= html((string) ($old['starts_at'] ?? '')) ?>">
            </label>
            <label>
                Ends
                <input name="ends_at" type="datetime-local" required value="<?= html((string) ($old['ends_at'] ?? '')) ?>">
            </label>
        </div>
        <label>
            Location
            <input name="location" autocomplete="street-address" value="<?= html((string) ($old['location'] ?? '')) ?>">
        </label>
        <label>
            Description
            <textarea name="description"><?= html((string) ($old['description'] ?? '')) ?></textarea>
        </label>
        <button type="submit">Create event</button>
    </form>
<?php endif; ?>
