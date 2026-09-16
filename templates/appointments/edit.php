<?php
/** @var array<string, mixed> $data */
$error = $data['error'] ?? null;
$appointment = is_array($data['appointment'] ?? null) ? $data['appointment'] : null;
$old = is_array($data['old'] ?? null) ? $data['old'] : [];
$calendars = is_array($data['calendars'] ?? null) ? $data['calendars'] : [];
$readOnly = $appointment !== null && is_array($appointment['source'] ?? null);
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Planner</p>
        <h1>Edit event</h1>
        <p class="time-copy">Update a Time-owned event.</p>
    </div>
    <a class="button button-secondary" href="/planner">Back to planner</a>
</section>

<?php if ($appointment === null): ?>
    <p class="time-empty"><?= html(is_string($error) ? $error : 'Event not found.') ?></p>
<?php elseif ($readOnly): ?>
    <p class="time-error"><?= html(is_string($error) ? $error : 'This event is read-only in Time.') ?></p>
<?php else: ?>
    <form class="time-form" method="post" action="/appointments/<?= (int) $appointment['id'] ?>/edit">
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
                Starts
                <input name="starts_at" type="datetime-local" required value="<?= html((string) ($old['starts_at'] ?? '')) ?>">
            </label>
            <label>
                Ends
                <input name="ends_at" type="datetime-local" required value="<?= html((string) ($old['ends_at'] ?? '')) ?>">
            </label>
        </div>
        <label>
            Status
            <select name="status">
                <?php foreach (['active' => 'Active', 'cancelled' => 'Cancelled'] as $value => $label): ?>
                    <option value="<?= html($value) ?>" <?= (string) ($old['status'] ?? 'active') === $value ? 'selected' : '' ?>>
                        <?= html($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
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
        <datalist id="timezones">
            <option value="America/Los_Angeles"></option>
            <option value="America/Denver"></option>
            <option value="America/Chicago"></option>
            <option value="America/New_York"></option>
            <option value="UTC"></option>
        </datalist>
        <div class="time-form-actions">
            <button type="submit">Save event</button>
            <a class="button button-secondary" href="/planner">Cancel</a>
        </div>
    </form>

    <form class="time-danger-zone" method="post" action="/appointments/<?= (int) $appointment['id'] ?>/delete">
        <div>
            <h2>Delete event</h2>
            <p class="time-meta">This removes the event from Time and CalDAV sync.</p>
        </div>
        <button class="button button-danger" type="submit">Delete event</button>
    </form>
<?php endif; ?>
