<?php
/** @var array<string, mixed> $data */
$events = $data['events'] ?? [];
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Events</p>
        <h1>Your events</h1>
    </div>
    <a class="button" href="/events/new">New event</a>
</section>

<?php if ($events === []): ?>
    <p class="time-empty">No events yet.</p>
<?php else: ?>
    <section class="time-grid">
        <?php foreach ($events as $event): ?>
            <article class="time-card">
                <h2><?= htmlspecialchars((string) $event['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="time-meta">
                    <?php if (($event['starts_at'] ?? null) !== null || ($event['ends_at'] ?? null) !== null): ?>
                        <?= htmlspecialchars((string) ($event['starts_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        to
                        <?= htmlspecialchars((string) ($event['ends_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    <?php else: ?>
                        No time set.
                    <?php endif; ?>
                </p>
                <?php if (($event['location'] ?? null) !== null): ?>
                    <p class="time-meta"><?= htmlspecialchars((string) $event['location'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if (($event['source'] ?? null) !== null): ?>
                    <p class="time-meta">Imported from <?= htmlspecialchars((string) ($event['source']['service'] ?? 'source'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
