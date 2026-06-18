<?php

declare(strict_types=1);

$index = file_get_contents(dirname(__DIR__) . '/public/index.php') ?: '';
$checks = [
    'Canonical CalDAV path is accepted' =>
        str_contains($index, "\$path === '/caldav'")
        && str_contains($index, "str_starts_with(\$path, '/caldav/')"),
    'Legacy DAV path remains compatible' =>
        str_contains($index, "\$path === '/dav'")
        && str_contains($index, "str_starts_with(\$path, '/dav/')"),
    'SabreDAV base URI follows the public path' =>
        str_contains($index, 'davBaseUri($requestPath)')
        && str_contains($index, '$server->setBaseUri($baseUri)'),
    'Missing PHP DOM is reported explicitly' =>
        str_contains($index, "class_exists('DOMDocument')")
        && str_contains($index, 'CalDAV requires the PHP DOM extension.'),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
