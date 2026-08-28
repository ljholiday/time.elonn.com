<?php

declare(strict_types=1);

/*
 * The published Contract's label wiring is complete and self-consistent: every
 * model-sourced argument names a resolvable label_ref, every enum value has a
 * resolvable option label, no help_ref dangles, and no `labels` entry is
 * unreferenced. Mirrors conductor.elonn's ServiceRegistry::contractLabelIssues so
 * a gap fails here, in time's own suite, before it ever reaches the registry.
 */

$contract = json_decode((string) file_get_contents(dirname(__DIR__) . '/public/time.json'), true);
$publication = json_decode((string) file_get_contents(dirname(__DIR__) . '/public/time-publication.json'), true);
$labels = is_array($contract['labels'] ?? null) ? $contract['labels'] : [];

$issues = [];
$referenced = [];

foreach (($contract['endpoints'] ?? []) as $endpoint) {
    foreach (($endpoint['operations'] ?? []) as $operation) {
        $operationId = (string) ($operation['id'] ?? '');
        foreach (($operation['arguments'] ?? []) as $field => $spec) {
            if (!is_array($spec) || ($spec['source'] ?? 'model') !== 'model') {
                continue;
            }
            $where = $operationId . ' argument ' . $field;

            $labelRef = (string) ($spec['label_ref'] ?? '');
            if ($labelRef === '') {
                $issues[] = $where . ' is missing label_ref.';
            } elseif (!is_string($labels[$labelRef] ?? null)) {
                $issues[] = $where . ' label_ref "' . $labelRef . '" does not resolve.';
            } else {
                $referenced[$labelRef] = true;
            }

            $helpRef = (string) ($spec['help_ref'] ?? '');
            if ($helpRef !== '') {
                if (!is_string($labels[$helpRef] ?? null)) {
                    $issues[] = $where . ' help_ref "' . $helpRef . '" does not resolve.';
                } else {
                    $referenced[$helpRef] = true;
                }
            }

            if (array_key_exists('enum', $spec)) {
                $enumRefs = is_array($spec['enum_label_refs'] ?? null) ? $spec['enum_label_refs'] : [];
                foreach (($spec['enum'] ?? []) as $value) {
                    $value = (string) $value;
                    $ref = (string) ($enumRefs[$value] ?? '');
                    if ($ref === '') {
                        $issues[] = $where . ' enum value "' . $value . '" is missing an enum_label_refs entry.';
                    } elseif (!is_string($labels[$ref] ?? null)) {
                        $issues[] = $where . ' enum label_ref "' . $ref . '" does not resolve.';
                    } else {
                        $referenced[$ref] = true;
                    }
                }
            }
        }
    }
}

foreach ($labels as $ref => $text) {
    if (!is_string($text) || trim($text) === '') {
        $issues[] = 'labels["' . $ref . '"] must be a non-empty string.';
    } elseif (!isset($referenced[(string) $ref])) {
        $issues[] = 'labels["' . $ref . '"] is never referenced.';
    }
}

$checks = [
    'Every model argument and enum value has a resolvable label; no orphan labels' => $issues === [],
    'the shared search field labels are authored' =>
        ($labels['field.search_text'] ?? null) === 'Search'
        && ($labels['field.result_limit'] ?? null) === 'How many to show',
    'Contract and Publication declare the same revision' =>
        ($contract['contract']['revision'] ?? null) === ($publication['contract']['revision'] ?? null),
];

if ($issues !== []) {
    echo "label issues:\n  " . implode("\n  ", $issues) . "\n";
}

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
