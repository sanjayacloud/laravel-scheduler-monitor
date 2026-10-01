<?php

/**
 * Checks scheduler-monitor:list --json output against what the given Laravel
 * version can report. Usage: php check.php <laravel-version> < output.json
 */
$version = isset($argv[1]) ? $argv[1] : '';
$lines = array_values(array_filter(array_map('trim', explode("\n", (string) stream_get_contents(STDIN)))));
$decoded = $lines === [] ? null : json_decode($lines[count($lines) - 1], true);

if (! is_array($decoded) || ! isset($decoded['tasks'])) {
    fwrite(STDERR, "No JSON output from scheduler-monitor:list\n");
    exit(1);
}

$tasks = [];

foreach ($decoded['tasks'] as $task) {
    $tasks[$task['command']] = $task;
}

$atLeast = function ($minimum) use ($version) {
    return version_compare($version, $minimum, '>=');
};

$expectations = [
    'ok-command' => [
        'started' => true,
        'finished' => true,
        'exit_code' => $atLeast('5.8') ? 0 : null,
    ],
    'failing-command' => [
        'started' => true,
        'finished' => true,
        'exit_code' => $atLeast('5.8') ? 4 : null,
    ],
    'closure-ok' => [
        'started' => true,
        'finished' => true,
        'exit_code' => 0,
    ],
    'skipped' => [
        'started' => false,
        'skipped' => $atLeast('6.0'),
    ],
    'background' => [
        'started' => true,
        'finished' => true,
        'exit_code' => $atLeast('6.0') ? 0 : null,
    ],
    'closure-fail' => [
        'started' => true,
        'finished' => true,
        'exit_code' => 1,
        'error' => $atLeast('6.0') && ! $atLeast('7.0') ? 'stopped the scheduler' : 'closure exploded',
    ],
];

$failures = [];

foreach ($expectations as $name => $expected) {
    if (! isset($tasks[$name])) {
        $failures[] = "{$name}: not listed";

        continue;
    }

    $task = $tasks[$name];
    $checks = [
        'started' => function ($value) use ($task) {
            return ($task['started_at'] !== null) === $value;
        },
        'finished' => function ($value) use ($task) {
            return ($task['finished_at'] !== null) === $value;
        },
        'skipped' => function ($value) use ($task) {
            return ($task['skipped_at'] !== null) === $value;
        },
        'exit_code' => function ($value) use ($task) {
            return $task['exit_code'] === $value;
        },
        'error' => function ($value) use ($task) {
            return $value === null ? $task['error'] === null : strpos((string) $task['error'], $value) !== false;
        },
    ];

    foreach ($expected as $field => $value) {
        if (! $checks[$field]($value)) {
            $failures[] = "{$name}: expected {$field} ".json_encode($value).', got '.json_encode($task);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Laravel {$version} failed:\n  ".implode("\n  ", $failures)."\n");
    exit(1);
}

echo "Laravel {$version}: all ".count($expectations)." tasks recorded as expected\n";
