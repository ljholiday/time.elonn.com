<?php

declare(strict_types=1);

// Regression coverage for timeValidateEventInterval() (public/index.php), which rejects a
// VEVENT create/update whose *effective* starts_at/ends_at -- after combining whatever the
// Call actually submits with whatever the object already has -- would end before (or exactly
// at) its start. public/index.php executes side effects on require and cannot be required
// directly in a test -- matching this repo's existing convention (tests/time-contract-test.php
// reads it as a string; tests/action-initial-payload-test.php reproduces small functions
// verbatim) -- so the function under test is reproduced here verbatim.
//
// Each fixture below models the $fields shape timeEventFieldsFromContent() actually produces
// (a bare 'Y-m-d H:i:s' wall-clock string paired with a 'timezone' name) and the $existing
// shape CalendarStore::find() actually produces (an ISO 8601 string with an explicit offset),
// not a simplified stand-in -- so a passing test here means the real two representations
// compare correctly, not just two identical strings.

require dirname(__DIR__) . '/vendor/autoload.php';

/** Reproduces timeValidateEventInterval() (public/index.php) verbatim. */
function timeValidateEventInterval(array $fields, ?array $existing, string $defaultTimezone): ?string
{
    $zone = new DateTimeZone((string) ($fields['timezone'] ?? $defaultTimezone));
    $resolve = static function (string $key) use ($fields, $existing, $zone): ?DateTimeImmutable {
        if (array_key_exists($key, $fields)) {
            return new DateTimeImmutable((string) $fields[$key], $zone);
        }
        $existingValue = $existing[$key] ?? null;
        return is_string($existingValue) && trim($existingValue) !== '' ? new DateTimeImmutable($existingValue) : null;
    };

    $effectiveStartsAt = $resolve('starts_at');
    $effectiveEndsAt = $resolve('ends_at');
    if ($effectiveStartsAt === null || $effectiveEndsAt === null) {
        return null;
    }

    return $effectiveEndsAt <= $effectiveStartsAt ? 'Event ends_at must be after starts_at.' : null;
}

$checks = [];

// --- Create (no $existing to combine with; timeEventFieldsFromContent() with requireCore=true
// always already fills in both sides, whether explicit or via its own missing-end default). ---

$checks['create: a valid explicit interval passes'] = timeValidateEventInterval([
    'starts_at' => '2026-09-18 09:00:00', 'ends_at' => '2026-09-18 10:00:00', 'timezone' => 'America/New_York',
], null, 'UTC') === null;

$checks['create: an explicit end before the start is rejected'] = timeValidateEventInterval([
    'starts_at' => '2026-09-18 10:00:00', 'ends_at' => '2026-09-18 09:00:00', 'timezone' => 'America/New_York',
], null, 'UTC') === 'Event ends_at must be after starts_at.';

$checks['create: an end exactly equal to the start is rejected (must be strictly after)'] = timeValidateEventInterval([
    'starts_at' => '2026-09-18 09:00:00', 'ends_at' => '2026-09-18 09:00:00', 'timezone' => 'America/New_York',
], null, 'UTC') === 'Event ends_at must be after starts_at.';

// The existing intended behavior for a missing end (create defaults to starts_at + 1 hour,
// timeEventFieldsFromContent()'s own job, not this function's) always produces a later end --
// pinned here explicitly so this validator is confirmed to never reject that default.
$checks['create: the missing-end default (starts_at + 1 hour) always passes validation'] = timeValidateEventInterval([
    'starts_at' => '2026-09-18 09:00:00', 'ends_at' => '2026-09-18 10:00:00', 'timezone' => 'UTC',
], null, 'UTC') === null;

// --- Update: unchanged edit (neither starts_at nor ends_at submitted) combines entirely with
// the existing, already-valid interval -- never rejected. ---

$existingValidEvent = ['starts_at' => '2026-09-18T13:00:00+00:00', 'ends_at' => '2026-09-18T14:00:00+00:00'];

$checks['update: an edit that touches neither field validates the untouched existing interval, and passes'] =
    timeValidateEventInterval(['title' => 'Renamed'], $existingValidEvent, 'UTC') === null;

// --- Update: partial update of *only* ends_at, combined with the existing (unchanged)
// starts_at -- the exact gap this fix closes. ---

$checks['update: moving only ends_at before the existing starts_at is rejected'] = timeValidateEventInterval(
    ['ends_at' => '2026-09-18 08:00:00', 'timezone' => 'UTC'],
    $existingValidEvent,
    'UTC'
) === 'Event ends_at must be after starts_at.';

$checks['update: moving only ends_at to something still after the existing starts_at is allowed'] = timeValidateEventInterval(
    ['ends_at' => '2026-09-18 16:00:00', 'timezone' => 'UTC'],
    $existingValidEvent,
    'UTC'
) === null;

// --- Update: partial update of *only* starts_at, combined with the existing (unchanged)
// ends_at -- e.g. a caller that (unlike the route handler's own duration-preserving logic)
// supplied no ends_at and whose starts_at move alone would invert the interval. ---

$checks['update: moving only starts_at past the existing ends_at is rejected'] = timeValidateEventInterval(
    ['starts_at' => '2026-09-18 15:00:00', 'timezone' => 'UTC'],
    $existingValidEvent,
    'UTC'
) === 'Event ends_at must be after starts_at.';

// --- Update: the route handler's own duration-preserving logic (moving starts_at without an
// explicit ends_at) always resolves $fields['ends_at'] itself before this function ever runs,
// so by the time it's called here, both keys are already present and already consistent --
// pinned explicitly as the "missing end" intended behavior on the update side. ---

$checks['update: starts_at moved with its duration-preserving ends_at already filled in always passes'] = timeValidateEventInterval(
    ['starts_at' => '2026-09-20 09:00:00', 'ends_at' => '2026-09-20 10:00:00', 'timezone' => 'UTC'],
    $existingValidEvent,
    'UTC'
) === null;

// --- Update: both sides submitted explicitly. ---

$checks['update: both fields submitted with end before start is rejected regardless of existing values'] = timeValidateEventInterval(
    ['starts_at' => '2026-09-19 10:00:00', 'ends_at' => '2026-09-19 09:00:00', 'timezone' => 'UTC'],
    $existingValidEvent,
    'UTC'
) === 'Event ends_at must be after starts_at.';

$checks['update: both fields submitted with a valid new interval is allowed'] = timeValidateEventInterval(
    ['starts_at' => '2026-09-19 09:00:00', 'ends_at' => '2026-09-19 10:00:00', 'timezone' => 'UTC'],
    $existingValidEvent,
    'UTC'
) === null;

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$passed) {
        $failed++;
    }
}

exit($failed === 0 && $checks !== [] ? 0 : 1);
