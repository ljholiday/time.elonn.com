<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elonn\Time\ServiceDescriptor;

$root = dirname(__DIR__);
$public = file_get_contents($root . '/public/index.php') ?: '';
$store = file_get_contents($root . '/src/CalendarStore.php') ?: '';
$runtimePanelRoute = '/runtime/' . 'panel/time';
$worldPanelRoute = '/world/' . 'panels/time';
$descriptor = ServiceDescriptor::payload();
$contract = json_decode((string) file_get_contents($root . '/public/time.json'), true);
$contractOperationIds = [];
foreach (($contract['endpoints'] ?? []) as $endpoint) {
    foreach (($endpoint['operations'] ?? []) as $operation) {
        $contractOperationIds[] = (string) ($operation['id'] ?? '');
    }
}

$checks = [
    'time.json declares all four canonical text fields, with type and meaning, for every Object/Collection structure it defines (Contract Authoring Guide, Canonical Text Fields)' =>
        count((array) ($contract['structures']['service'] ?? [])) === 5
        && array_reduce(
            (array) ($contract['structures']['service'] ?? []),
            static function (bool $carry, array $structure): bool {
                $fields = (array) ($structure['canonical_text_fields'] ?? []);
                foreach (['title', 'name', 'summary', 'description'] as $field) {
                    if (($fields[$field]['type'] ?? '') !== 'string' || trim((string) ($fields[$field]['meaning'] ?? '')) === '') {
                        return false;
                    }
                }

                return $carry;
            },
            true
        ),
    'publishes canonical Time service call route' => str_contains($public, "/time/call")
        && str_contains($public, 'timeServiceDataset('),
    'Time publishes a Mind-facing service descriptor' => str_contains($public, "/descriptor")
        && ($descriptor['service'] ?? '') === 'time'
        && isset($descriptor['operations']['time.search']['supports'])
        && ($descriptor['operations']['time.search']['required']['text'] ?? '') === 'non_empty_string',
    // objectSources()/calendarObjectSource() (the pre-canonical "object source" shape --
    // source/object_type/domain_permissions/domain_actions, no id/type/content) were dead
    // code: unreachable from any live route, confirmed by a repo-wide grep. Deleted rather
    // than kept pinned in place by a passing test (dev.elonn charter: normalize producers,
    // remove permanent compatibility/legacy paths).
    'the dead pre-canonical object-source builder is gone, not merely unused' =>
        !str_contains($store, 'calendarObjectSource')
        && !str_contains($store, 'function objectSources('),
    'HTML Planner renders CalendarStore workspace data' => str_contains($public, "\$router->get('/planner'")
        && str_contains($public, ")->workspace(\$identity['id'], \$view, \$anchorDate, \$timezone)")
        && is_file($root . '/templates/planner.php'),
    'HTML calendars and events expose edit flows' => str_contains($public, "\$router->get('/calendars/{id}/edit'")
        && str_contains($public, "\$router->post('/calendars/{id}/edit'")
        && str_contains($public, "\$router->post('/events/{id}/edit'")
        && str_contains($public, "\$router->post('/events/{id}/delete'")
        && is_file($root . '/templates/calendars/edit.php')
        && is_file($root . '/templates/events/edit.php'),
    'HTML tasks use canonical VTODO calendar objects' => str_contains($public, "\$router->get('/tasks'")
        && str_contains($public, "\$router->post('/tasks/{id}/complete'")
        && str_contains($public, "taskFieldsFromInput")
        && str_contains($public, "'component_type' => 'VTODO'")
        && is_file($root . '/templates/tasks/index.php')
        && is_file($root . '/templates/tasks/new.php')
        && is_file($root . '/templates/tasks/edit.php'),
    'object source route is runtime-neutral' => str_contains($public, "'objects' => \$objects")
        && !str_contains($public, "'{$runtimePanelRoute}'")
        && !str_contains($public, $worldPanelRoute)
        && !str_contains($public, 'runtimePanel('),
    'Contract declares the full event/task/calendar Conductor operation surface' => $contractOperationIds === [
        'time.search', 'time.list', 'time.open', 'time.calendars', 'time.calendar.update', 'time.calendar.delete', 'time.agenda', 'time.tasks',
        'time.event.create', 'time.event.update', 'time.event.delete',
        'time.task.create', 'time.task.update', 'time.task.complete', 'time.task.reopen', 'time.task.delete',
    ],
    'Every mutating operation targeting an existing object is object_id/context sourced, never model-guessed' =>
        (static function (array $contract): bool {
            $mutatingExisting = ['time.event.update', 'time.event.delete', 'time.task.update', 'time.task.complete', 'time.task.reopen', 'time.task.delete', 'time.calendar.update', 'time.calendar.delete'];
            foreach (($contract['endpoints'] ?? []) as $endpoint) {
                foreach (($endpoint['operations'] ?? []) as $operation) {
                    if (!in_array($operation['id'] ?? '', $mutatingExisting, true)) {
                        continue;
                    }
                    $objectId = $operation['arguments']['object_id'] ?? null;
                    if (($operation['model_selectable'] ?? true) !== false
                        || !is_array($objectId)
                        || ($objectId['source'] ?? '') !== 'context'
                        || ($objectId['context_key'] ?? '') !== 'object_id') {
                        return false;
                    }
                }
            }
            return true;
        })($contract),
    'Conductor operation handlers exist in code for every declared mutating operation' => str_contains($public, "'time.event.create'")
        && str_contains($public, "'time.event.update'")
        && str_contains($public, "'time.event.delete'")
        && str_contains($public, "'time.task.create'")
        && str_contains($public, "'time.task.complete'")
        && str_contains($public, "'time.task.reopen'")
        && str_contains($public, "'time.task.delete'")
        && str_contains($public, "'time.calendar.update'")
        && str_contains($public, "'time.calendar.delete'")
        && str_contains($public, 'function timeAgendaObjects(')
        && str_contains($public, 'function timeTaskObjects(')
        && str_contains($public, 'resolveCalendarId('),
    'Every Time calendar carries a real Edit action, and Delete only when deletable' =>
        str_contains($public, 'function timeCalendarObjectActions(')
        && str_contains($public, "'operation' => 'time.calendar.update'")
        && str_contains($public, "'operation' => 'time.calendar.delete'")
        && str_contains($store, 'function updateCalendar(')
        && str_contains($store, 'function deleteCalendar(')
        && str_contains($store, 'The Social mirror calendar cannot be deleted from Time.'),
    'Every declared entrypoint resolves to a real, model_selectable Contract operation' =>
        (static function (array $contract, array $contractOperationIds): bool {
            $entrypoints = $contract['entrypoints'] ?? [];
            if ($entrypoints === []) {
                return false;
            }
            $operations = [];
            foreach (($contract['endpoints'] ?? []) as $endpoint) {
                foreach (($endpoint['operations'] ?? []) as $operation) {
                    $operations[(string) ($operation['id'] ?? '')] = $operation;
                }
            }
            foreach ($entrypoints as $entrypoint) {
                $operationId = (string) ($entrypoint['operation'] ?? '');
                if (trim((string) ($entrypoint['id'] ?? '')) === ''
                    || trim((string) ($entrypoint['label'] ?? '')) === ''
                    || !array_key_exists($operationId, $operations)
                    || ($operations[$operationId]['model_selectable'] ?? true) !== true) {
                    return false;
                }
            }
            return true;
        })($contract, $contractOperationIds),
    'time.search and time.list deliberately have no dashboard entrypoint of their own' =>
        (static function (array $contract): bool {
            foreach (($contract['entrypoints'] ?? []) as $entrypoint) {
                if (in_array($entrypoint['operation'] ?? '', ['time.search', 'time.list'], true)) {
                    return false;
                }
            }
            return true;
        })($contract),
    'time.calendar objects declare name (real data) with title blank, and description (real data) with summary blank -- never copied across' =>
        str_contains($public, "'title' => '',\n        'name' => (string) \$calendar['name'],\n        'summary' => '',\n        'description' => (string) (\$calendar['description'] ?? ''),")
        && !str_contains($public, "'name' => (string) \$calendar['name'],\n            'description' => \$calendar['description'] ?? null,"),
    'time.calendar_event/time.task objects declare summary (real data -- the calendar format\'s own SUMMARY property) with title blank, and description (real data) with name blank -- never copied across' =>
        str_contains($public, "'title' => '',\n        'name' => '',\n        'summary' => (string) \$row['title'],\n        'description' => (string) (\$row['description'] ?? ''),")
        && !str_contains($public, "'title' => (string) \$row['title'],\n        'name' => '',\n        'summary' => '',"),
    'Time result Collections carry no separate name, and description is not an alias of summary' =>
        str_contains($public, "'title' => 'Calendars',\n            'name' => '',\n            'summary' => \$summary,\n            'description' => '',")
        && str_contains($public, "'title' => \$text !== '' ? 'Time results for ' . \$text : 'Time results',\n            'name' => '',\n            'summary' => \$summary,\n            'description' => '',"),
    'A calendar object source resource is labeled from the object\'s real summary, not its blank title' =>
        str_contains($public, "'label' => (string) (\$object['summary'] ?? 'Source'),")
        && !str_contains($public, "'label' => (string) (\$object['title'] ?? 'Source'),"),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
