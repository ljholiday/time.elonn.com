<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$public = file_get_contents($root . '/public/index.php') ?: '';
$store = file_get_contents($root . '/src/CalendarStore.php') ?: '';

$checks = [
    'publishes Time object source route' => str_contains($public, "/objects")
        && str_contains($public, '->objectSources('),
    'object sources include appointments and tasks' => str_contains($store, "'appointments'")
        && str_contains($store, "'tasks'")
        && str_contains($store, 'calendarObjectSource'),
    'object sources follow object source contract' => str_contains($store, "'domain_actions'")
        && str_contains($store, "'domain_permissions'")
        && str_contains($store, "'source'")
        && str_contains($store, "'object_type'"),
    'object source route is not panel payload' => str_contains($public, "Response::json([\n        'objects'")
        && str_contains($public, "'/runtime/panel/time'"),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
