<?php
/** @var array<string, mixed> $data */
$error = $data['error'] ?? null;
$event = is_array($data['event'] ?? null) ? $data['event'] : null;
$old = is_array($data['old'] ?? null) ? $data['old'] : [];
$calendars = is_array($data['calendars'] ?? null) ? $data['calendars'] : [];
$sourceService = (string) ($event['source_service'] ?? '');
$readOnly = $sourceService !== '';
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Events</p>
        <h1>Edit event</h1>
        <p class="time-copy">Update a Time-owned calendar event.</p>
    </div>
    <a class="button button-secondary" href="/events">Back to events</a>
</section>

<?php if ($event === null): ?>
    <p class="time-empty"><?= html(is_string($error) ? $error : 'Event not found.') ?></p>
<?php else: ?>
    <form class="time-form" method="post" action="/events/<?= (int) $event['id'] ?>/edit">
        <?php if (is_string($error)): ?>
            <p class="time-error"><?= html($error) ?></p>
        <?php endif; ?>
        <?php if ($readOnly): ?>
            <p class="time-notice">This is a <?= html($sourceService) ?> event mirror. Time shows it on calendars, but the source event owns its details.</p>
        <?php endif; ?>
        <label>
            Calendar
            <select name="calendar_id" required <?= $readOnly ? 'disabled' : '' ?>>
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
            <input name="title" required autocomplete="off" value="<?= html((string) ($old['title'] ?? '')) ?>" <?= $readOnly ? 'disabled' : '' ?>>
        </label>
        <div class="time-form-grid">
            <label>
                Starts
                <input name="starts_at" type="datetime-local" required value="<?= html((string) ($old['starts_at'] ?? '')) ?>" <?= $readOnly ? 'disabled' : '' ?>>
            </label>
            <label>
                Ends
                <input name="ends_at" type="datetime-local" required value="<?= html((string) ($old['ends_at'] ?? '')) ?>" <?= $readOnly ? 'disabled' : '' ?>>
            </label>
        </div>
        <label>
            Location
            <input name="location" autocomplete="street-address" value="<?= html((string) ($old['location'] ?? '')) ?>" <?= $readOnly ? 'disabled' : '' ?>>
        </label>
        <label>
            Timezone
            <input name="timezone" list="timezones" value="<?= html((string) ($old['timezone'] ?? '')) ?>" <?= $readOnly ? 'disabled' : '' ?>>
        </label>
        <label>
            Status
            <select name="status" <?= $readOnly ? 'disabled' : '' ?>>
                <?php foreach (['active' => 'Active', 'cancelled' => 'Cancelled'] as $value => $label): ?>
                    <option value="<?= html($value) ?>" <?= (string) ($old['status'] ?? 'active') === $value ? 'selected' : '' ?>>
                        <?= html($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="time-checkbox">
            <input name="all_day" type="checkbox" value="1" <?= (string) ($old['all_day'] ?? '') === '1' ? 'checked' : '' ?> <?= $readOnly ? 'disabled' : '' ?>>
            All day
        </label>
        <label>
            Description
            <textarea name="description" <?= $readOnly ? 'disabled' : '' ?>><?= html((string) ($old['description'] ?? '')) ?></textarea>
        </label>
        <datalist id="timezones">
            <option value="America/Los_Angeles"></option>
            <option value="America/Denver"></option>
            <option value="America/Chicago"></option>
            <option value="America/New_York"></option>
            <option value="UTC"></option>
        </datalist>
        <?php if (!$readOnly): ?>
            <div class="time-form-actions">
                <button type="submit">Save event</button>
                <a class="button button-secondary" href="/events">Cancel</a>
            </div>
        <?php endif; ?>
    </form>

    <?php if (!$readOnly): ?>
        <form class="time-danger-zone" method="post" action="/events/<?= (int) $event['id'] ?>/delete">
            <div>
                <h2>Delete event</h2>
                <p class="time-meta">This removes the Time-owned calendar event from active views and syncs the backing calendar object.</p>
            </div>
            <button class="button button-danger" type="submit">Delete event</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
