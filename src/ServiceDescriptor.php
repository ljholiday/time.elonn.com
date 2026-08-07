<?php

declare(strict_types=1);

namespace Elonn\Time;

final class ServiceDescriptor
{
    /** @return array<string, mixed> */
    public static function payload(): array
    {
        return [
            'service' => 'time',
            'description' => 'Find and show calendar events, tasks, schedules, and time-based commitments for the member.',
            'supports' => ['calendar events', 'tasks', 'schedule', 'deadlines', 'time-based commitments'],
            'returns' => ['objects' => ['Calendar Event', 'Task']],
            'cost' => 'low',
            'side_effects' => [],
            'operations' => [
                'time.search' => [
                    'description' => 'Search member calendar events and tasks by topic or time.',
                    'supports' => ['calendar search', 'task search', 'schedule lookup', 'deadline lookup'],
                    'returns' => ['objects' => ['Calendar Event', 'Task']],
                    'cost' => 'low',
                    'side_effects' => [],
                    'required' => ['text' => 'non_empty_string'],
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => ['text'],
                        'additionalProperties' => false,
                    ],
                ],
                'time.list' => [
                    'description' => 'Show recent calendar events and tasks for the member.',
                    'supports' => ['recent calendar events', 'recent tasks', 'schedule overview'],
                    'returns' => ['objects' => ['Calendar Event', 'Task']],
                    'cost' => 'low',
                    'side_effects' => [],
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }
}
