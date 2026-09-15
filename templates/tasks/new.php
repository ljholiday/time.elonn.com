<?php
/** @var array<string, mixed> $data */
$error = $data['error'] ?? null;
$old = is_array($data['old'] ?? null) ? $data['old'] : [];
$calendars = is_array($data['calendars'] ?? null) ? $data['calendars'] : [];
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Tasks</p>
        <h1>New task</h1>
        <p class="time-copy">Create a Time-owned task in an active calendar.</p>
    </div>
    <a class="button button-secondary" href="/tasks">Back to tasks</a>
</section>

<?php if ($calendars === []): ?>
    <p class="time-empty">Create a calendar before adding tasks.</p>
    <p><a class="button" href="/calendars/new">Create calendar</a></p>
<?php else: ?>
    <form class="time-form" method="post" action="/tasks">
        <?php if (is_string($error)): ?>
            <p class="time-error"><?= html($error) ?></p>
        <?php endif; ?>
        <label>
            Calendar
            <select name="calendar_id" required>
                <?php foreach ($calendars as $calendar): ?>
                    <?php $selected = (string) ($old['calendar_id'] ?? '') === (string) $calendar['id']; ?>
                    <option value="<?= (int) $calendar['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                        <?= html((string) $calendar['name']) ?>
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
                Due
                <input name="due_at" type="datetime-local" value="<?= html((string) ($old['due_at'] ?? '')) ?>">
            </label>
            <label>
                Priority
                <input name="priority" type="number" min="0" max="9" value="<?= html((string) ($old['priority'] ?? '')) ?>">
            </label>
        </div>
        <label>
            Location
            <input name="location" autocomplete="street-address" value="<?= html((string) ($old['location'] ?? '')) ?>">
        </label>
        <label>
            Timezone
            <input name="timezone" list="timezones" value="<?= html((string) ($old['timezone'] ?? '')) ?>">
        </label>
        <label class="time-checkbox">
            <input name="all_day" type="checkbox" value="1" <?= (string) ($old['all_day'] ?? '') === '1' ? 'checked' : '' ?>>
            All day
        </label>
        <label>
            Description
            <textarea name="description"><?= html((string) ($old['description'] ?? '')) ?></textarea>
        </label>
        <input type="hidden" name="status" value="needs-action">
        <datalist id="timezones">
            <option value="America/Los_Angeles"></option>
            <option value="America/Denver"></option>
            <option value="America/Chicago"></option>
            <option value="America/New_York"></option>
            <option value="UTC"></option>
        </datalist>
        <button type="submit">Create task</button>
    </form>
<?php endif; ?>
