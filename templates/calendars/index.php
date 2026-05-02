<?php
/** @var array<string, mixed> $data */
$calendars = $data['calendars'] ?? [];
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Calendars</p>
        <h1>Your calendars</h1>
    </div>
    <a class="button" href="/calendars/new">New calendar</a>
</section>

<?php if ($calendars === []): ?>
    <p class="time-empty">No calendars yet.</p>
<?php else: ?>
    <section class="time-grid">
        <?php foreach ($calendars as $calendar): ?>
            <article class="time-card">
                <h2><?= htmlspecialchars((string) $calendar['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="time-meta">
                    <?= htmlspecialchars((string) ($calendar['timezone'] ?? 'No timezone set'), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
