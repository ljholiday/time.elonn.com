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
    'RFC 6764 well-known CalDAV redirect is served' =>
        str_contains($index, "=== '/.well-known/caldav'")
        && str_contains($index, 'wellKnownCalDavRedirect($requestPath)')
        && str_contains($index, "true, 301"),
    'Unauthenticated OPTIONS probe is answered without a challenge' =>
        str_contains($index, "=== 'OPTIONS' && \$credentials === null")
        && str_contains($index, 'DAV: 1, 3, extended-mkcol, calendar-access'),
    'Missing PHP DOM is reported explicitly' =>
        str_contains($index, "class_exists('DOMDocument')")
        && str_contains($index, 'CalDAV requires the PHP DOM extension.'),
    'HTTPS redirects are canonical-host bounded' =>
        str_contains($index, 'timeHttpsRedirectTarget')
        && str_contains($index, "'time.elonn.local', 'time.elonn.com'")
        && !str_contains($index, "header('Location: https://' . \$host"),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
