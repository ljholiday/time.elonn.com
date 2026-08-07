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

$checks = [
    'publishes canonical Time service call route' => str_contains($public, "/time/call")
        && str_contains($public, 'timeServiceDataset('),
    'Time publishes a Mind-facing service descriptor' => str_contains($public, "/descriptor")
        && ($descriptor['service'] ?? '') === 'time'
        && isset($descriptor['operations']['time.search']['supports'])
        && ($descriptor['operations']['time.search']['required']['text'] ?? '') === 'non_empty_string',
    'object sources include appointments and tasks' => str_contains($store, "'appointments'")
        && str_contains($store, "'tasks'")
        && str_contains($store, 'calendarObjectSource'),
    'object sources follow object source contract' => str_contains($store, "'domain_actions'")
        && str_contains($store, "'domain_permissions'")
        && str_contains($store, "'source'")
        && str_contains($store, "'object_type'"),
    'object source route is runtime-neutral' => str_contains($public, "'objects' => \$objects")
        && !str_contains($public, "'{$runtimePanelRoute}'")
        && !str_contains($public, $worldPanelRoute)
        && !str_contains($public, 'runtimePanel('),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
