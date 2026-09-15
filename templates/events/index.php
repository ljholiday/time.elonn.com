<?php
/** @var array<string, mixed> $data */
$events = $data['events'] ?? [];
$calendars = is_array($data['calendars'] ?? null) ? $data['calendars'] : [];
$selectedCalendarId = $data['selected_calendar_id'] ?? null;
$now = time();
$upcoming = [];
$past = [];
foreach ($events as $event) {
    $timestamp = strtotime((string) ($event['starts_at'] ?? ''));
    if ($timestamp !== false && $timestamp < $now) {
        $past[] = $event;
        continue;
    }
    $upcoming[] = $event;
}
$formatDate = static function (?string $value): string {
    if ($value === null || $value === '') {
        return 'Unscheduled';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('D, M j, Y', $timestamp);
};
$formatRange = static function (?string $startsAt, ?string $endsAt): string {
    if (($startsAt ?? '') === '') {
        return 'No time set';
    }

    $start = strtotime((string) $startsAt);
    $end = ($endsAt ?? '') === '' ? false : strtotime((string) $endsAt);
    if ($start === false) {
        return (string) $startsAt;
    }

    $label = date('g:i A', $start);
    if ($end !== false) {
        $label .= ' - ' . date('g:i A', $end);
    }

    return $label;
};
$renderEvent = static function (array $event) use ($formatDate, $formatRange): void {
    $source = is_array($event['source'] ?? null) ? $event['source'] : null;
    if ($source === null && ($event['source_service'] ?? null) !== null) {
        $source = ['service' => $event['source_service']];
    }
    ?>
    <article class="time-event-row">
        <div class="time-date-block">
            <span><?= html($formatDate($event['starts_at'] ?? null)) ?></span>
            <strong><?= html($formatRange($event['starts_at'] ?? null, $event['ends_at'] ?? null)) ?></strong>
        </div>
        <div class="time-event-body">
            <div class="time-card-topline">
                <?php if (($event['calendar_name'] ?? null) !== null): ?>
                    <p class="time-kicker"><?= html((string) $event['calendar_name']) ?></p>
                <?php endif; ?>
                <?php if ($source !== null): ?>
                    <span class="time-badge">Imported from <?= html((string) ($source['service'] ?? 'source')) ?></span>
                <?php endif; ?>
            </div>
            <h2><?= html((string) $event['title']) ?></h2>
            <?php if (($event['location'] ?? null) !== null): ?>
                <p class="time-meta"><?= html((string) $event['location']) ?></p>
            <?php endif; ?>
            <?php if (($event['description'] ?? null) !== null): ?>
                <p class="time-copy"><?= html((string) $event['description']) ?></p>
            <?php endif; ?>
            <div class="time-card-actions">
                <a class="button button-secondary" href="/events/<?= (int) $event['id'] ?>/edit">Edit</a>
            </div>
        </div>
    </article>
    <?php
};
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Events</p>
        <h1>Calendar events</h1>
        <p class="time-copy">Member-owned events and Social event mirrors, grouped by when they happen.</p>
    </div>
    <a class="button" href="/events/new">New event</a>
</section>

<?php if ($calendars !== []): ?>
    <form class="time-filter" method="get" action="/events">
        <label for="calendar_id">Calendar</label>
        <select id="calendar_id" name="calendar_id">
            <option value="">All calendars</option>
            <?php foreach ($calendars as $calendar): ?>
                <?php $selected = (string) $selectedCalendarId === (string) $calendar['id']; ?>
                <option value="<?= (int) $calendar['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                    <?= html((string) $calendar['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="button button-secondary" type="submit">Apply</button>
    </form>
<?php endif; ?>

<?php if ($events === []): ?>
    <p class="time-empty">No events yet.</p>
<?php else: ?>
    <section class="time-stack" aria-labelledby="upcoming-events-heading">
        <div class="time-section-heading">
            <div>
                <p class="time-kicker">Upcoming</p>
                <h2 id="upcoming-events-heading"><?= (int) count($upcoming) ?> scheduled</h2>
            </div>
        </div>
        <?php if ($upcoming === []): ?>
            <p class="time-empty">No upcoming events match this view.</p>
        <?php else: ?>
            <?php foreach ($upcoming as $event): ?>
                <?php $renderEvent($event); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="time-stack" aria-labelledby="past-events-heading">
        <div class="time-section-heading">
            <div>
                <p class="time-kicker">Past</p>
                <h2 id="past-events-heading"><?= (int) count($past) ?> completed</h2>
            </div>
        </div>
        <?php if ($past === []): ?>
            <p class="time-empty">No past events in this view.</p>
        <?php else: ?>
            <?php foreach (array_reverse($past) as $event): ?>
                <?php $renderEvent($event); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
