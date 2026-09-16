<?php
/** @var array<string, mixed> $data */
$calendars = $data['calendars'] ?? [];
$activeCalendars = array_values(array_filter($calendars, static fn (array $c): bool => (string) ($c['status'] ?? 'active') !== 'archived'));
$archivedCalendars = array_values(array_filter($calendars, static fn (array $c): bool => (string) ($c['status'] ?? 'active') === 'archived'));
$renderCalendarCard = static function (array $calendar): void {
    $source = is_array($calendar['source'] ?? null) ? $calendar['source'] : null;
    if ($source === null && ($calendar['source_service'] ?? null) !== null) {
        $source = ['service' => $calendar['source_service']];
    }
    $archived = (string) ($calendar['status'] ?? 'active') === 'archived';
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
            <?php if ($source !== null): ?>
                <div>
                    <dt>Source</dt>
                    <dd><?= html((string) ($source['service'] ?? 'external')) ?></dd>
                </div>
            <?php endif; ?>
        </dl>
        <div class="time-card-actions">
            <a class="button button-secondary" href="/calendars/<?= (int) $calendar['id'] ?>/edit">Edit</a>
            <?php if ($source !== null): ?>
                <?php if ($archived): ?>
                    <form method="post" action="/calendars/<?= (int) $calendar['id'] ?>/unarchive">
                        <button class="button button-secondary" type="submit">Show in Planner</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="/calendars/<?= (int) $calendar['id'] ?>/archive">
                        <button class="button button-secondary" type="submit">Hide from Planner</button>
                    </form>
                <?php endif; ?>
            <?php elseif ($archived): ?>
                <form method="post" action="/calendars/<?= (int) $calendar['id'] ?>/unarchive">
                    <button class="button button-secondary" type="submit">Unarchive</button>
                </form>
            <?php else: ?>
                <form method="post" action="/calendars/<?= (int) $calendar['id'] ?>/archive">
                    <button class="button button-secondary" type="submit">Archive</button>
                </form>
            <?php endif; ?>
        </div>
    </article>
    <?php
};
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
        <?php foreach ($activeCalendars as $calendar): ?>
            <?php $renderCalendarCard($calendar); ?>
        <?php endforeach; ?>
    </section>

    <?php if ($archivedCalendars !== []): ?>
        <section class="time-header">
            <div>
                <p class="time-kicker">Not shown</p>
                <h2>Archived &amp; hidden calendars</h2>
                <p class="time-copy">Not shown in the Planner, Dashboard, or CalDAV sync. Native calendars here are archived; mirrored calendars are hidden, but their source keeps sending events in the background.</p>
            </div>
        </section>
        <section class="time-grid">
            <?php foreach ($archivedCalendars as $calendar): ?>
                <?php $renderCalendarCard($calendar); ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
