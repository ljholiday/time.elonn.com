<?php
/** @var array<string, mixed> $data */
$calendars = $data['calendars'] ?? [];
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Calendars</p>
        <h1>Your calendars</h1>
        <p class="time-copy">Native Time calendars and mirrored Social calendars visible to this member.</p>
    </div>
    <a class="button" href="/calendars/new">New calendar</a>
</section>

<?php if ($calendars === []): ?>
    <p class="time-empty">No calendars yet.</p>
<?php else: ?>
    <section class="time-grid">
        <?php foreach ($calendars as $calendar): ?>
            <?php
            $source = is_array($calendar['source'] ?? null) ? $calendar['source'] : null;
            if ($source === null && ($calendar['source_service'] ?? null) !== null) {
                $source = ['service' => $calendar['source_service']];
            }
            ?>
            <article class="time-card time-calendar-card">
                <div class="time-card-topline">
                    <p class="time-kicker"><?= $source === null ? 'Native' : 'Mirror' ?></p>
                    <?php if (($calendar['color'] ?? null) !== null): ?>
                        <code><?= html((string) $calendar['color']) ?></code>
                    <?php endif; ?>
                </div>
                <h2><?= html((string) $calendar['name']) ?></h2>
                <?php if (($calendar['description'] ?? null) !== null): ?>
                    <p class="time-copy"><?= html((string) $calendar['description']) ?></p>
                <?php endif; ?>
                <dl class="time-facts">
                    <div>
                        <dt>Timezone</dt>
                        <dd><?= html((string) (($calendar['timezone'] ?? null) ?: 'Not set')) ?></dd>
                    </div>
                    <div>
                        <dt>Components</dt>
                        <dd><?= html((string) ($calendar['components'] ?? 'VEVENT,VTODO')) ?></dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd><?= html((string) ($calendar['status'] ?? 'active')) ?></dd>
                    </div>
                    <?php if ($source !== null): ?>
                        <div>
                            <dt>Source</dt>
                            <dd><?= html((string) ($source['service'] ?? 'external')) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
                <div class="time-card-actions">
                    <a class="button button-secondary" href="/calendars/<?= (int) $calendar['id'] ?>/edit">Edit</a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
