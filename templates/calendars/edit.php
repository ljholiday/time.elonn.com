<?php
/** @var array<string, mixed> $data */
$error = $data['error'] ?? null;
$calendar = is_array($data['calendar'] ?? null) ? $data['calendar'] : null;
$old = is_array($data['old'] ?? null) ? $data['old'] : [];
$sourceService = (string) ($calendar['source_service'] ?? '');
$status = (string) ($calendar['status'] ?? 'active');
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Calendars</p>
        <h1>Edit calendar</h1>
        <p class="time-copy">Update the calendar's local Time settings.</p>
    </div>
    <a class="button button-secondary" href="/calendars">Back to calendars</a>
</section>

<?php if ($calendar === null): ?>
    <p class="time-empty"><?= html(is_string($error) ? $error : 'Calendar not found.') ?></p>
<?php else: ?>
    <form class="time-form" method="post" action="/calendars/<?= (int) $calendar['id'] ?>/edit">
        <?php if (is_string($error)): ?>
            <p class="time-error"><?= html($error) ?></p>
        <?php endif; ?>
        <?php if ($sourceService !== ''): ?>
            <p class="time-notice">This calendar mirrors <?= html($sourceService) ?>. It can be renamed locally, but deletion is disabled.</p>
        <?php endif; ?>
        <?php if ($status === 'archived' && $sourceService === ''): ?>
            <p class="time-notice">This calendar is archived. It's hidden from the Planner, Dashboard, and CalDAV sync until you unarchive it.</p>
        <?php elseif ($status === 'archived'): ?>
            <p class="time-notice">This calendar is hidden. <?= html(ucfirst($sourceService)) ?> keeps sending events in the background, but they won't show in your Planner, Dashboard, or CalDAV until you show it again.</p>
        <?php endif; ?>
        <label>
            Name
            <input name="name" required value="<?= html((string) ($old['name'] ?? '')) ?>">
        </label>
        <label>
            Description
            <textarea name="description"><?= html((string) ($old['description'] ?? '')) ?></textarea>
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
        <div class="time-form-actions">
            <button type="submit">Save calendar</button>
            <a class="button button-secondary" href="/calendars">Cancel</a>
        </div>
    </form>

    <div class="time-form-actions">
        <?php if ($sourceService !== ''): ?>
            <?php if ($status === 'archived'): ?>
                <form method="post" action="/calendars/<?= (int) $calendar['id'] ?>/unarchive">
                    <button class="button button-secondary" type="submit">Show in Planner</button>
                </form>
            <?php else: ?>
                <form method="post" action="/calendars/<?= (int) $calendar['id'] ?>/archive">
                    <button class="button button-secondary" type="submit">Hide from Planner</button>
                </form>
            <?php endif; ?>
        <?php elseif ($status === 'archived'): ?>
            <form method="post" action="/calendars/<?= (int) $calendar['id'] ?>/unarchive">
                <button class="button button-secondary" type="submit">Unarchive calendar</button>
            </form>
        <?php else: ?>
            <form method="post" action="/calendars/<?= (int) $calendar['id'] ?>/archive">
                <button class="button button-secondary" type="submit">Archive calendar</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($sourceService === ''): ?>
        <form class="time-danger-zone" method="post" action="/calendars/<?= (int) $calendar['id'] ?>/delete">
            <div>
                <h2>Delete calendar</h2>
                <p class="time-meta">This hides the calendar from Time. Existing CalDAV objects attached to it are no longer shown through active calendar views.</p>
            </div>
            <button class="button button-danger" type="submit">Delete calendar</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
