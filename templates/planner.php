<?php
/** @var array<string, mixed> $data */
$workspace = is_array($data['workspace'] ?? null) ? $data['workspace'] : [];
$view = (string) ($workspace['view'] ?? 'week');
$anchorDate = (string) ($workspace['anchor_date'] ?? date('Y-m-d'));
$timezone = (string) ($workspace['timezone'] ?? date_default_timezone_get());
$appointments = is_array($workspace['appointments'] ?? null) ? $workspace['appointments'] : [];
$tasks = is_array($workspace['tasks'] ?? null) ? $workspace['tasks'] : [];
$calendars = is_array($workspace['calendars'] ?? null) ? $workspace['calendars'] : [];
$timezoneObject = new DateTimeZone($timezone);
$anchor = new DateTimeImmutable($anchorDate . ' 00:00:00', $timezoneObject);
$range = is_array($workspace['range'] ?? null) ? $workspace['range'] : [];
$rangeStart = new DateTimeImmutable((string) ($range['start'] ?? $anchor->format(DATE_ATOM)));
$rangeEnd = new DateTimeImmutable((string) ($range['end'] ?? $anchor->modify('+1 day')->format(DATE_ATOM)));
$rangeStart = $rangeStart->setTimezone($timezoneObject);
$rangeEnd = $rangeEnd->setTimezone($timezoneObject);
$views = [
    'day' => 'Day',
    'week' => 'Week',
    'month' => 'Month',
    'agenda' => 'Agenda',
    'tasks' => 'Tasks',
];
$step = match ($view) {
    'day' => 'P1D',
    'month' => 'P1M',
    'agenda' => 'P90D',
    'tasks' => 'P1M',
    default => 'P7D',
};
$previousDate = $anchor->sub(new DateInterval($step))->format('Y-m-d');
$nextDate = $anchor->add(new DateInterval($step))->format('Y-m-d');
$plannerUrl = static function (string $targetView, string $date) use ($timezone): string {
    return '/planner?' . http_build_query([
        'view' => $targetView,
        'date' => $date,
        'timezone' => $timezone,
    ]);
};
$eventStart = static function (array $event) use ($timezoneObject): ?DateTimeImmutable {
    $value = (string) ($event['occurrence_start'] ?? $event['starts_at'] ?? '');
    if ($value === '') {
        return null;
    }

    return (new DateTimeImmutable($value))->setTimezone($timezoneObject);
};
$eventEnd = static function (array $event) use ($timezoneObject): ?DateTimeImmutable {
    $value = (string) ($event['occurrence_end'] ?? $event['ends_at'] ?? '');
    if ($value === '') {
        return null;
    }

    return (new DateTimeImmutable($value))->setTimezone($timezoneObject);
};
$eventsForDate = static function (string $date) use ($appointments, $eventStart): array {
    return array_values(array_filter($appointments, static function (array $event) use ($date, $eventStart): bool {
        $startsAt = $eventStart($event);
        return $startsAt !== null && $startsAt->format('Y-m-d') === $date;
    }));
};
$formatEventTime = static function (array $event) use ($eventStart, $eventEnd): string {
    if ((bool) ($event['all_day'] ?? false)) {
        return 'All day';
    }

    $startsAt = $eventStart($event);
    if ($startsAt === null) {
        return 'No time set';
    }

    $label = $startsAt->format('g:i A');
    $endsAt = $eventEnd($event);
    if ($endsAt !== null) {
        $label .= ' - ' . $endsAt->format('g:i A');
    }

    return $label;
};
$calendarStyle = static function (array $event): string {
    $color = trim((string) ($event['calendar_color'] ?? ''));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? ' style="border-left-color: ' . html($color) . '"' : '';
};
$legacyEventEditUrl = static function (array $event): ?string {
    $uri = (string) ($event['uri'] ?? '');
    if (preg_match('/^event-(\\d+)\\.ics$/', $uri, $matches) !== 1) {
        return null;
    }

    return '/events/' . $matches[1] . '/edit';
};
$renderAppointment = static function (array $event) use ($formatEventTime, $calendarStyle, $legacyEventEditUrl): void {
    $source = is_array($event['source'] ?? null) ? $event['source'] : null;
    $editUrl = $legacyEventEditUrl($event);
    ?>
    <article class="time-appointment"<?= $calendarStyle($event) ?>>
        <p class="time-appointment-time"><?= html($formatEventTime($event)) ?></p>
        <h3><?= html((string) ($event['title'] ?? 'Calendar event')) ?></h3>
        <p class="time-meta">
            <?= html((string) ($event['calendar_name'] ?? 'Calendar')) ?>
            <?php if (($event['location'] ?? null) !== null): ?>
                <span aria-hidden="true">/</span>
                <?= html((string) $event['location']) ?>
            <?php endif; ?>
        </p>
        <?php if ($source !== null): ?>
            <span class="time-badge">Imported from <?= html((string) ($source['service'] ?? 'source')) ?></span>
        <?php endif; ?>
        <?php if ($editUrl !== null): ?>
            <div class="time-card-actions">
                <a class="button button-secondary" href="<?= html($editUrl) ?>">Edit</a>
            </div>
        <?php endif; ?>
    </article>
    <?php
};
$renderTask = static function (array $task) use ($timezoneObject): void {
    $dueAt = (string) ($task['due_at'] ?? '');
    $completedAt = (string) ($task['completed_at'] ?? '');
    $dueLabel = 'No due date';
    if ($dueAt !== '') {
        $dueLabel = (new DateTimeImmutable($dueAt))->setTimezone($timezoneObject)->format('M j, Y g:i A');
    }
    ?>
    <article class="time-task-row">
        <div>
            <h3><?= html((string) ($task['title'] ?? 'Task')) ?></h3>
            <p class="time-meta"><?= html((string) ($task['calendar_name'] ?? 'Calendar')) ?> / <?= html($dueLabel) ?></p>
        </div>
        <span class="time-task-state"><?= $completedAt === '' ? 'Open' : 'Completed' ?></span>
    </article>
    <?php
};
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Planner</p>
        <h1><?= html($views[$view] ?? 'Planner') ?></h1>
        <p class="time-copy">
            <?= html($rangeStart->format('M j, Y')) ?> - <?= html($rangeEnd->modify('-1 second')->format('M j, Y')) ?>
            <span aria-hidden="true">/</span>
            <?= html($timezone) ?>
        </p>
    </div>
    <div class="time-actions">
        <a class="button button-secondary" href="<?= html($plannerUrl($view, $previousDate)) ?>">Previous</a>
        <a class="button button-secondary" href="<?= html($plannerUrl($view, (new DateTimeImmutable('today', $timezoneObject))->format('Y-m-d'))) ?>">Today</a>
        <a class="button button-secondary" href="<?= html($plannerUrl($view, $nextDate)) ?>">Next</a>
        <a class="button" href="/events/new">New event</a>
    </div>
</section>

<nav class="time-tabs" aria-label="Planner views">
    <?php foreach ($views as $viewId => $label): ?>
        <a href="<?= html($plannerUrl($viewId, $anchorDate)) ?>" <?= $viewId === $view ? 'aria-current="page"' : '' ?>>
            <?= html($label) ?>
        </a>
    <?php endforeach; ?>
</nav>

<form class="time-filter" method="get" action="/planner">
    <label for="planner-view">View</label>
    <select id="planner-view" name="view">
        <?php foreach ($views as $viewId => $label): ?>
            <option value="<?= html($viewId) ?>" <?= $viewId === $view ? 'selected' : '' ?>><?= html($label) ?></option>
        <?php endforeach; ?>
    </select>
    <label for="planner-date">Date</label>
    <input id="planner-date" name="date" type="date" value="<?= html($anchorDate) ?>">
    <label for="planner-timezone">Timezone</label>
    <input id="planner-timezone" name="timezone" list="timezones" value="<?= html($timezone) ?>">
    <datalist id="timezones">
        <option value="America/Los_Angeles"></option>
        <option value="America/Denver"></option>
        <option value="America/Chicago"></option>
        <option value="America/New_York"></option>
        <option value="UTC"></option>
    </datalist>
    <button class="button button-secondary" type="submit">Apply</button>
</form>

<?php if ($view === 'month'): ?>
    <?php
    $monthStart = $anchor->modify('first day of this month');
    $gridStart = $monthStart->modify('monday this week');
    ?>
    <section class="time-month-grid" aria-label="Month calendar">
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName): ?>
            <div class="time-month-heading"><?= html($dayName) ?></div>
        <?php endforeach; ?>
        <?php for ($index = 0; $index < 42; $index++): ?>
            <?php
            $day = $gridStart->add(new DateInterval('P' . $index . 'D'));
            $date = $day->format('Y-m-d');
            $dayEvents = $eventsForDate($date);
            ?>
            <section class="time-month-day <?= $day->format('m') === $anchor->format('m') ? '' : 'time-month-day-muted' ?>">
                <a class="time-month-date" href="<?= html($plannerUrl('day', $date)) ?>"><?= html($day->format('j')) ?></a>
                <?php foreach (array_slice($dayEvents, 0, 3) as $event): ?>
                    <p><?= html((string) ($event['title'] ?? 'Calendar event')) ?></p>
                <?php endforeach; ?>
                <?php if (count($dayEvents) > 3): ?>
                    <span><?= (int) (count($dayEvents) - 3) ?> more</span>
                <?php endif; ?>
            </section>
        <?php endfor; ?>
    </section>
<?php elseif ($view === 'tasks'): ?>
    <section class="time-stack" aria-labelledby="planner-tasks-heading">
        <div class="time-section-heading">
            <div>
                <p class="time-kicker">Tasks</p>
                <h2 id="planner-tasks-heading"><?= (int) count($tasks) ?> shown</h2>
            </div>
        </div>
        <?php if ($tasks === []): ?>
            <p class="time-empty">No tasks found.</p>
        <?php else: ?>
            <?php foreach ($tasks as $task): ?>
                <?php $renderTask($task); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php else: ?>
    <?php
    $days = [];
    $dayCursor = $rangeStart;
    while ($dayCursor < $rangeEnd && count($days) < 90) {
        $days[] = $dayCursor;
        $dayCursor = $dayCursor->add(new DateInterval('P1D'));
    }
    ?>
    <section class="time-planner-days" aria-label="Calendar events">
        <?php foreach ($days as $day): ?>
            <?php
            $date = $day->format('Y-m-d');
            $dayEvents = $eventsForDate($date);
            ?>
            <section class="time-day-column">
                <div class="time-day-heading">
                    <p class="time-kicker"><?= html($day->format('D')) ?></p>
                    <h2><?= html($day->format('M j')) ?></h2>
                </div>
                <?php if ($dayEvents === []): ?>
                    <p class="time-empty">No calendar events.</p>
                <?php else: ?>
                    <?php foreach ($dayEvents as $event): ?>
                        <?php $renderAppointment($event); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($view !== 'tasks' && $tasks !== []): ?>
    <section class="time-stack" aria-labelledby="planner-task-summary-heading">
        <div class="time-section-heading">
            <div>
                <p class="time-kicker">Tasks</p>
                <h2 id="planner-task-summary-heading"><?= (int) count($tasks) ?> due in view</h2>
            </div>
            <a href="<?= html($plannerUrl('tasks', $anchorDate)) ?>">View tasks</a>
        </div>
        <?php foreach (array_slice($tasks, 0, 5) as $task): ?>
            <?php $renderTask($task); ?>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($calendars !== []): ?>
    <section class="time-calendar-strip" aria-label="Calendars in planner">
        <?php foreach ($calendars as $calendar): ?>
            <span>
                <?= html((string) ($calendar['name'] ?? 'Calendar')) ?>
            </span>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
