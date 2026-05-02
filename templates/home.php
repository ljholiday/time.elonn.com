<?php
/** @var array<string, mixed> $data */
$calendarCount = count($data['calendars'] ?? []);
$eventCount = count($data['events'] ?? []);
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Calendar server</p>
        <h1>Time</h1>
        <p class="time-copy">Calendars and events backed by the Elonn Time service.</p>
    </div>
    <div class="time-nav">
        <a class="button" href="/calendars/new">Create calendar</a>
        <a class="button button-secondary" href="/events/new">Create event</a>
    </div>
</section>

<section class="time-grid">
    <article class="time-card">
        <h2><?= (int) $calendarCount ?></h2>
        <p class="time-meta">Calendars</p>
    </article>
    <article class="time-card">
        <h2><?= (int) $eventCount ?></h2>
        <p class="time-meta">Events</p>
    </article>
</section>
