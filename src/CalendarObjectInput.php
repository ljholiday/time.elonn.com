<?php

declare(strict_types=1);

namespace Elonn\Time;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns a time.event.* or time.task.* Call's raw `content` into the field set
 * CalendarStore::create()/update() expect, including everything a route handler needs beyond
 * the bare per-field parsing: resolving (and, on update, optionally moving) the target
 * calendar, applying its timezone as the default for an offset-less value, preserving an
 * event's duration when only its start moves, and validating the complete effective interval.
 * Kept separate from CalendarObjectPresenter (which shapes data going back *out*) so the
 * Contract's input-handling half is independently callable and testable against real data,
 * not a copy of this logic pasted into a test.
 */
final class CalendarObjectInput
{
    /**
     * The full pipeline for time.event.create: resolve the target calendar first (its own
     * declared timezone is the documented default, help.timezone, when the Call doesn't name
     * one explicitly, so the calendar must be known before that default can be applied), build
     * the fields, then validate the resulting interval.
     *
     * @param array<string, mixed> $content
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    public static function resolveEventCreateFields(CalendarStore $store, string $identityUserId, array $content): array
    {
        $calendarId = $store->resolveCalendarId($identityUserId, self::cleanOptionalString($content['calendar'] ?? null));
        $defaultTimezone = $store->calendarTimezone($identityUserId, $calendarId) ?? 'UTC';
        [$fields, $error] = self::eventFieldsFromContent($content, true, $defaultTimezone);
        if ($error !== null) {
            return [[], $error];
        }
        $intervalError = self::validateEventInterval($fields, null, $defaultTimezone);
        if ($intervalError !== null) {
            return [[], $intervalError];
        }
        $fields['calendar_id'] = $calendarId;
        return [$fields, null];
    }

    /**
     * The full pipeline for time.event.update: build the fields (interpreting any offset-less
     * value in the event's own current zone, never a freshly-resolved calendar default --
     * changing which calendar an event lives in must not silently retime it), preserve the
     * event's duration when starts_at moves without an explicit ends_at, validate the complete
     * effective interval, then resolve an explicitly named calendar move -- a blank or omitted
     * `calendar` preserves membership (CalendarStore::update()'s merge already does this as
     * long as $fields doesn't set calendar_id at all); a named calendar must already exist.
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $existing
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    public static function resolveEventUpdateFields(CalendarStore $store, string $identityUserId, array $content, array $existing): array
    {
        $defaultTimezone = self::validTimezone((string) ($existing['timezone'] ?? '')) ?? 'UTC';
        [$fields, $error] = self::eventFieldsFromContent($content, false, $defaultTimezone);
        if ($error !== null) {
            return [[], $error];
        }

        if (array_key_exists('starts_at', $fields) && !array_key_exists('ends_at', $fields)
            && $existing['starts_at'] !== null && $existing['ends_at'] !== null) {
            // $existing's instants are always ISO 8601 with an explicit UTC offset
            // (CalendarStore::find()), so they're unambiguous; $fields['starts_at'] is a bare
            // wall-clock string paired with $fields['timezone'] (set by eventFieldsFromContent()
            // whenever starts_at is), so it must be parsed with that same zone, not left to
            // PHP's ambient default.
            $durationZone = new DateTimeZone((string) ($fields['timezone'] ?? $defaultTimezone));
            $oldStart = new DateTimeImmutable((string) $existing['starts_at']);
            $oldEnd = new DateTimeImmutable((string) $existing['ends_at']);
            $newStart = new DateTimeImmutable($fields['starts_at'], $durationZone);
            $fields['ends_at'] = $newStart->add($oldStart->diff($oldEnd))->format('Y-m-d H:i:s');
        }

        $intervalError = self::validateEventInterval($fields, $existing, $defaultTimezone);
        if ($intervalError !== null) {
            return [[], $intervalError];
        }

        $calendarName = self::cleanOptionalString($content['calendar'] ?? null);
        if ($calendarName !== null) {
            $calendarId = $store->findCalendarIdByName($identityUserId, $calendarName);
            if ($calendarId === null) {
                return [[], 'No calendar named "' . $calendarName . '" exists.'];
            }
            $fields['calendar_id'] = $calendarId;
        }

        return [$fields, null];
    }

    /**
     * The full pipeline for time.task.create -- see resolveEventCreateFields() for why the
     * calendar is resolved before its timezone default is applied.
     *
     * @param array<string, mixed> $content
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    public static function resolveTaskCreateFields(CalendarStore $store, string $identityUserId, array $content): array
    {
        $calendarId = $store->resolveCalendarId($identityUserId, self::cleanOptionalString($content['calendar'] ?? null));
        $defaultTimezone = $store->calendarTimezone($identityUserId, $calendarId) ?? 'UTC';
        [$fields, $error] = self::taskFieldsFromContent($content, true, $defaultTimezone);
        if ($error !== null) {
            return [[], $error];
        }
        $fields['component_type'] = 'VTODO';
        $fields['calendar_id'] = $calendarId;
        return [$fields, null];
    }

    /**
     * The full pipeline for time.task.update -- see resolveEventUpdateFields() for the
     * blank/omitted-preserves, named-must-exist calendar-move rule (tasks have no interval to
     * validate: due_at/starts_at are independent, not a start/end pair).
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $existing
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    public static function resolveTaskUpdateFields(CalendarStore $store, string $identityUserId, array $content, array $existing): array
    {
        $defaultTimezone = self::validTimezone((string) ($existing['timezone'] ?? '')) ?? 'UTC';
        [$fields, $error] = self::taskFieldsFromContent($content, false, $defaultTimezone);
        if ($error !== null) {
            return [[], $error];
        }

        $calendarName = self::cleanOptionalString($content['calendar'] ?? null);
        if ($calendarName !== null) {
            $calendarId = $store->findCalendarIdByName($identityUserId, $calendarName);
            if ($calendarId === null) {
                return [[], 'No calendar named "' . $calendarName . '" exists.'];
            }
            $fields['calendar_id'] = $calendarId;
        }

        return [$fields, null];
    }

    /**
     * Builds the field set CalendarStore::create()/update() expect from a time.event.* Call's
     * content, validating along the way. $requireCore is true for time.event.create (title and
     * starts_at must be present; ends_at defaults to starts_at + 1 hour) and false for
     * time.event.update (every field is optional; only the keys present in $content are
     * returned, so CalendarStore::update()'s merge leaves everything else untouched).
     *
     * Date/timezone contract: starts_at/ends_at are ISO 8601 and may carry their own UTC offset
     * (help.starts_at's documented shape); that offset is always honored over everything else,
     * per "preserve offsets or convert explicitly." $defaultTimezone is the zone used only when
     * a value carries no offset of its own -- the caller resolves this *after* picking the
     * target calendar (its own declared zone, or UTC) for a create, or the existing object's
     * current zone for an update, never a guess made before the calendar is known.
     * CalendarObject::build() expects a bare wall-clock string paired with a 'timezone' field
     * naming the zone those digits are in, so every parsed instant is explicitly converted into
     * $defaultTimezone before formatting, rather than formatted in whatever zone happened to
     * survive parsing.
     *
     * @param array<string, mixed> $content
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    public static function eventFieldsFromContent(array $content, bool $requireCore, string $defaultTimezone): array
    {
        $fields = [];

        if (array_key_exists('title', $content)) {
            $title = trim((string) $content['title']);
            if ($title === '') {
                return [[], 'title cannot be empty.'];
            }
            $fields['title'] = $title;
        } elseif ($requireCore) {
            return [[], 'title is required.'];
        }

        $timezoneInput = self::cleanOptionalString($content['timezone'] ?? null);
        if ($timezoneInput !== null && self::validTimezone($timezoneInput) === null) {
            return [[], 'timezone is not valid.'];
        }
        $effectiveTimezone = $timezoneInput ?? $defaultTimezone;
        if ($timezoneInput !== null || $requireCore) {
            // An explicit override always sticks; a brand-new object must always declare a zone
            // (its own default or UTC) since none exists yet to fall back on.
            $fields['timezone'] = $effectiveTimezone;
        }
        $effectiveZone = new DateTimeZone($effectiveTimezone);

        $allDay = array_key_exists('all_day', $content) ? self::truthy($content['all_day']) : null;
        if ($allDay !== null) {
            $fields['all_day'] = $allDay ? 1 : 0;
        }

        $startsAt = null;
        if (array_key_exists('starts_at', $content)) {
            $startsAt = self::parseDateTime($content['starts_at'], $effectiveTimezone);
            if ($startsAt === null) {
                return [[], 'starts_at is not a valid date/time.'];
            }
            $fields['starts_at'] = $startsAt->setTimezone($effectiveZone)->format('Y-m-d H:i:s');
        } elseif ($requireCore) {
            return [[], 'starts_at is required.'];
        }

        $endsAtRaw = self::cleanOptionalString($content['ends_at'] ?? null);
        if ($endsAtRaw !== null) {
            $endsAt = self::parseDateTime($endsAtRaw, $effectiveTimezone);
            if ($endsAt === null) {
                return [[], 'ends_at is not a valid date/time.'];
            }
            $fields['ends_at'] = $endsAt->setTimezone($effectiveZone)->format('Y-m-d H:i:s');
        } elseif ($requireCore && $startsAt !== null) {
            $fields['ends_at'] = $startsAt->modify('+1 hour')->setTimezone($effectiveZone)->format('Y-m-d H:i:s');
        }

        foreach (['location', 'description', 'recurrence_rule', 'attendees'] as $key) {
            if (array_key_exists($key, $content)) {
                $value = trim((string) $content[$key]);
                $fields[$key] = $value === '' ? null : $value;
            }
        }

        return [$fields, null];
    }

    /**
     * Same shape as eventFieldsFromContent(), for time.task.create / time.task.update. Tasks
     * have no 'timezone' Contract argument of their own, but due_at/starts_at still need a zone
     * to interpret an offset-less value and to pair with the naive string
     * CalendarObject::build() expects -- see eventFieldsFromContent()'s doc comment for the
     * full contract.
     *
     * @param array<string, mixed> $content
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    public static function taskFieldsFromContent(array $content, bool $requireCore, string $defaultTimezone): array
    {
        $fields = [];

        if (array_key_exists('title', $content)) {
            $title = trim((string) $content['title']);
            if ($title === '') {
                return [[], 'title cannot be empty.'];
            }
            $fields['title'] = $title;
        } elseif ($requireCore) {
            return [[], 'title is required.'];
        }

        if ($requireCore) {
            $fields['timezone'] = $defaultTimezone;
        }
        $effectiveZone = new DateTimeZone($defaultTimezone);

        foreach (['due_at', 'starts_at'] as $key) {
            if (!array_key_exists($key, $content)) {
                continue;
            }
            $raw = self::cleanOptionalString($content[$key] ?? null);
            if ($raw === null) {
                $fields[$key] = null;
                continue;
            }
            $parsed = self::parseDateTime($raw, $defaultTimezone);
            if ($parsed === null) {
                return [[], $key . ' is not a valid date/time.'];
            }
            $fields[$key] = $parsed->setTimezone($effectiveZone)->format('Y-m-d H:i:s');
        }

        if (array_key_exists('priority', $content)) {
            if ($content['priority'] === null || $content['priority'] === '') {
                $fields['priority'] = null;
            } else {
                $priority = (int) $content['priority'];
                if ($priority < 0 || $priority > 9) {
                    return [[], 'priority must be between 0 and 9.'];
                }
                $fields['priority'] = $priority;
            }
        }

        foreach (['description', 'recurrence_rule'] as $key) {
            if (array_key_exists($key, $content)) {
                $value = trim((string) $content[$key]);
                $fields[$key] = $value === '' ? null : $value;
            }
        }

        return [$fields, null];
    }

    /**
     * Validates the *complete* effective interval of a VEVENT: starts_at/ends_at after
     * combining whatever the Call actually submits ($fields, from eventFieldsFromContent())
     * with whatever the object already has ($existing, from CalendarStore::find() -- null for
     * a create, which has nothing to combine with since eventFieldsFromContent() with
     * requireCore=true always already fills in both sides). Validating $fields in isolation
     * would miss a partial update that only moves one side of the interval past the other --
     * e.g. submitting only a new ends_at that falls before the event's existing, untouched
     * starts_at.
     *
     * Deliberately does not change how a missing end is filled in: create's own
     * starts_at+1-hour default and update's own duration-preserving logic (when starts_at moves
     * without an explicit ends_at) both run first and already guarantee ends_at > starts_at in
     * their own cases, so this never rejects either of those intended behaviors -- it only
     * catches genuinely inverted intervals.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed>|null $existing
     */
    public static function validateEventInterval(array $fields, ?array $existing, string $defaultTimezone): ?string
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

    public static function parseDateTime(mixed $value, ?string $timezone): ?DateTimeImmutable
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        try {
            $zone = ($timezone !== null && $timezone !== '') ? new DateTimeZone($timezone) : null;
            return new DateTimeImmutable($text, $zone);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cleanOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function validTimezone(string $value): ?string
    {
        $value = trim($value);
        return $value !== '' && in_array($value, DateTimeZone::listIdentifiers(), true) ? $value : null;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
