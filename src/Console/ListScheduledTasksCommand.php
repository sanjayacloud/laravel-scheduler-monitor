<?php

declare(strict_types=1);

namespace SanjayaCloud\SchedulerMonitor\Console;

use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use SanjayaCloud\SchedulerMonitor\ScheduleRunStore;
use Symfony\Component\Console\Output\OutputInterface;

class ListScheduledTasksCommand extends Command
{
    /**
     * Bumped when the JSON shape changes so readers can tell versions apart.
     */
    public const FORMAT_VERSION = 1;

    /**
     * Matches the quoted PHP binary and artisan that Laravel puts in front
     * of scheduled artisan commands, such as '/usr/bin/php8.2' 'artisan'.
     */
    private const QUOTED_ARTISAN = '/^([\'"]?)[^\'" ]*php[^\'" ]*\1\s+([\'"]?)artisan\2\s+/';

    /**
     * @var string
     */
    protected $signature = 'scheduler-monitor:list {--json : Output the tasks as JSON}';

    /**
     * @var string
     */
    protected $description = 'List scheduled tasks with their last recorded run';

    public function handle(Schedule $schedule, ScheduleRunStore $store): int
    {
        $stored = $store->all();
        $tasks = $this->tasks($schedule, $stored['runs']);

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'version' => self::FORMAT_VERSION,
                'since' => $stored['since'],
                'tasks' => $tasks,
            ], JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);

            return 0;
        }

        $this->table(
            ['Task', 'Schedule', 'Last started', 'Exit code', 'Runtime', 'Last skipped'],
            array_map(function (array $task): array {
                return [
                    $task['command'],
                    $task['expression'].' ('.$task['timezone'].')',
                    $task['started_at'] ?? 'Never',
                    $this->exitCodeLabel($task),
                    $task['runtime_ms'] !== null ? $task['runtime_ms'].' ms' : '-',
                    $task['skipped_at'] ?? '-',
                ];
            }, $tasks),
        );

        return 0;
    }

    /**
     * @param  array<string, array<string, mixed>>  $runs
     * @return list<array{key: string, expression: string, command: string, timezone: string, started_at: string|null, finished_at: string|null, exit_code: int|null, runtime_ms: int|null, error: string|null, skipped_at: string|null}>
     */
    private function tasks(Schedule $schedule, array $runs): array
    {
        $environment = (string) $this->laravel->environment();
        $tasks = [];

        foreach ($schedule->events() as $event) {
            if (! $event->runsInEnvironment($environment)) {
                continue;
            }

            $key = $event->mutexName();
            $run = $runs[$key] ?? [];

            $tasks[] = [
                'key' => $key,
                'expression' => $this->expression($event),
                'command' => $this->label($event),
                'timezone' => $this->timezone($event),
                'started_at' => $this->stringOrNull($run, 'started_at'),
                'finished_at' => $this->stringOrNull($run, 'finished_at'),
                'exit_code' => is_int($run['exit_code'] ?? null) ? $run['exit_code'] : null,
                'runtime_ms' => is_int($run['runtime_ms'] ?? null) ? $run['runtime_ms'] : null,
                'error' => $this->stringOrNull($run, 'error'),
                'skipped_at' => $this->stringOrNull($run, 'skipped_at'),
            ];
        }

        return $tasks;
    }

    /**
     * @param  array{started_at: string|null, finished_at: string|null, exit_code: int|null}  $task
     */
    private function exitCodeLabel(array $task): string
    {
        if ($task['exit_code'] !== null) {
            return (string) $task['exit_code'];
        }

        if ($task['finished_at'] !== null) {
            return 'Not reported';
        }

        return $task['started_at'] !== null ? 'Running' : '-';
    }

    /**
     * Laravel 5.4 adds a sixth, year field that is always "*". It is dropped
     * so every version reports a standard five-field expression.
     */
    private function expression(Event $event): string
    {
        $fields = preg_split('/\s+/', trim($event->expression)) ?: [];

        if (count($fields) === 6 && $fields[5] === '*') {
            array_pop($fields);
        }

        return implode(' ', $fields);
    }

    private function label(Event $event): string
    {
        if (is_string($event->description) && $event->description !== '') {
            return $event->description;
        }

        if ($event instanceof CallbackEvent) {
            return (string) $event->getSummaryForDisplay();
        }

        return (string) preg_replace(self::QUOTED_ARTISAN, 'php artisan ', (string) $event->command);
    }

    /**
     * Older Laravel versions leave the timezone null when none is set.
     */
    private function timezone(Event $event): string
    {
        $timezone = $event->timezone;

        if ($timezone instanceof DateTimeZone) {
            return $timezone->getName();
        }

        return in_array($timezone, [null, ''], true) ? (string) config('app.timezone', 'UTC') : $timezone;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function stringOrNull(array $run, string $field): ?string
    {
        return is_string($run[$field] ?? null) ? $run[$field] : null;
    }
}
