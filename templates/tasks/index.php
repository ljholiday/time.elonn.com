<?php
/** @var array<string, mixed> $data */
$workspace = is_array($data['workspace'] ?? null) ? $data['workspace'] : [];
$tasks = is_array($workspace['tasks'] ?? null) ? $workspace['tasks'] : [];
$openTasks = [];
$completedTasks = [];
foreach ($tasks as $task) {
    if (($task['completed_at'] ?? null) !== null || ($task['status'] ?? '') === 'completed') {
        $completedTasks[] = $task;
        continue;
    }
    $openTasks[] = $task;
}
$formatDue = static function (array $task): string {
    $dueAt = (string) ($task['due_at'] ?? '');
    if ($dueAt === '') {
        return 'No due date';
    }

    return (new DateTimeImmutable($dueAt))->format('M j, Y g:i A');
};
$priorityLabel = static function (array $task): string {
    $priority = $task['priority'] ?? null;
    return $priority === null ? 'No priority' : 'Priority ' . (int) $priority;
};
$renderTask = static function (array $task) use ($formatDue, $priorityLabel): void {
    $completed = ($task['completed_at'] ?? null) !== null || ($task['status'] ?? '') === 'completed';
    ?>
    <article class="time-task-row <?= $completed ? 'time-task-row-completed' : '' ?>">
        <div>
            <h3><?= html((string) ($task['title'] ?? 'Task')) ?></h3>
            <p class="time-meta">
                <?= html((string) ($task['calendar_name'] ?? 'Calendar')) ?>
                <span aria-hidden="true">/</span>
                <?= html($formatDue($task)) ?>
                <span aria-hidden="true">/</span>
                <?= html($priorityLabel($task)) ?>
            </p>
            <?php if (($task['description'] ?? null) !== null): ?>
                <p class="time-copy"><?= html((string) $task['description']) ?></p>
            <?php endif; ?>
        </div>
        <div class="time-task-actions">
            <form method="post" action="/tasks/<?= (int) $task['id'] ?>/complete">
                <button class="button button-secondary" type="submit"><?= $completed ? 'Reopen' : 'Complete' ?></button>
            </form>
            <a class="button button-secondary" href="/tasks/<?= (int) $task['id'] ?>/edit">Edit</a>
        </div>
    </article>
    <?php
};
?>
<section class="time-header">
    <div>
        <p class="time-kicker">Tasks</p>
        <h1>Tasks</h1>
        <p class="time-copy">Time-owned VTODO items from active calendars.</p>
    </div>
    <a class="button" href="/tasks/new">New task</a>
</section>

<section class="time-stack" aria-labelledby="open-tasks-heading">
    <div class="time-section-heading">
        <div>
            <p class="time-kicker">Open</p>
            <h2 id="open-tasks-heading"><?= (int) count($openTasks) ?> active</h2>
        </div>
    </div>
    <?php if ($openTasks === []): ?>
        <p class="time-empty">No open tasks.</p>
    <?php else: ?>
        <?php foreach ($openTasks as $task): ?>
            <?php $renderTask($task); ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="time-stack" aria-labelledby="completed-tasks-heading">
    <div class="time-section-heading">
        <div>
            <p class="time-kicker">Completed</p>
            <h2 id="completed-tasks-heading"><?= (int) count($completedTasks) ?> done</h2>
        </div>
    </div>
    <?php if ($completedTasks === []): ?>
        <p class="time-empty">No completed tasks.</p>
    <?php else: ?>
        <?php foreach ($completedTasks as $task): ?>
            <?php $renderTask($task); ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
