<?php
/** @var array<string, mixed> $data */
$calendarCount = count($data['calendars'] ?? []);
$eventCount = count($data['events'] ?? []);
$upcomingEvents = is_array($data['upcoming_events'] ?? null) ? $data['upcoming_events'] : [];
$tasks = is_array($data['tasks'] ?? null) ? $data['tasks'] : [];
$openTaskCount = count(array_filter($tasks, static fn (array $task): bool => ($task['completed_at'] ?? null) === null && ($task['status'] ?? '') !== 'completed'));
$upcomingEventCount = (int) ($data['upcoming_event_count'] ?? count($upcomingEvents));
$nextEvent = $upcomingEvents[0] ?? null;
$formatDate = static function (?string $value): string {
    if ($value === null || $value === '') {
        return 'Unscheduled';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('M j, Y', $timestamp);
};
$formatTime = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? '' : date('g:i A', $timestamp);
};
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Calendar service</p>
        <h1>Time</h1>
        <p class="time-copy">Calendars, calendar events, and Social event mirrors for this member.</p>
    </div>
    <div class="time-actions">
        <a class="button" href="/calendars/new">Create calendar</a>
        <a class="button button-secondary" href="/events/new">Create event</a>
        <a class="button button-secondary" href="/tasks/new">Create task</a>
    </div>
</section>

<section class="time-stats" aria-label="Time summary">
    <article class="time-stat">
        <h2><?= (int) $calendarCount ?></h2>
        <p class="time-meta">Calendars</p>
    </article>
    <article class="time-stat">
        <h2><?= (int) $eventCount ?></h2>
        <p class="time-meta">Calendar events</p>
    </article>
    <article class="time-stat">
        <h2><?= $upcomingEventCount ?></h2>
        <p class="time-meta">Upcoming</p>
    </article>
    <article class="time-stat">
        <h2><?= (int) $openTaskCount ?></h2>
        <p class="time-meta">Open tasks</p>
    </article>
</section>

<section class="time-board" aria-labelledby="time-overview-heading">
    <div class="time-panel time-panel-large">
        <div class="time-section-heading">
            <div>
                <p class="time-kicker">Overview</p>
                <h2 id="time-overview-heading">Next on the calendar</h2>
            </div>
            <a href="/planner">Open planner</a>
        </div>
        <?php if ($nextEvent === null): ?>
            <p class="time-empty">No upcoming calendar events.</p>
        <?php else: ?>
            <article class="time-feature-event">
                <p class="time-date"><?= html($formatDate($nextEvent['starts_at'] ?? null)) ?></p>
                <h3><?= html((string) $nextEvent['title']) ?></h3>
                <p class="time-meta">
                    <?= html($formatTime($nextEvent['starts_at'] ?? null)) ?>
                    <?php if (($nextEvent['ends_at'] ?? null) !== null): ?>
                        - <?= html($formatTime($nextEvent['ends_at'])) ?>
                    <?php endif; ?>
                    <?php if (($nextEvent['calendar_name'] ?? null) !== null): ?>
                        <span aria-hidden="true">/</span>
                        <?= html((string) $nextEvent['calendar_name']) ?>
                    <?php endif; ?>
                </p>
                <?php if (($nextEvent['location'] ?? null) !== null): ?>
                    <p class="time-copy"><?= html((string) $nextEvent['location']) ?></p>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    </div>

    <div class="time-panel">
        <div class="time-section-heading">
            <div>
                <p class="time-kicker">Upcoming</p>
                <h2>Queue</h2>
            </div>
        </div>
        <?php if ($upcomingEvents === []): ?>
            <p class="time-empty">Nothing scheduled.</p>
        <?php else: ?>
            <ol class="time-list">
                <?php foreach ($upcomingEvents as $event): ?>
                    <li>
                        <strong><?= html((string) $event['title']) ?></strong>
                        <span><?= html($formatDate($event['starts_at'] ?? null)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
</section>
