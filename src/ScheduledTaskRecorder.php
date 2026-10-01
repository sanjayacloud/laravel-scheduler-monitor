<?php

declare(strict_types=1);

namespace SanjayaCloud\SchedulerMonitor;

use Carbon\Carbon;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Event;
use Throwable;

/**
 * Writes task runs to the store, whichever hook reported them. Recording
 * errors are swallowed so monitoring never breaks the scheduler.
 */
class ScheduledTaskRecorder
{
    private const CRASH_MESSAGE = 'The task stopped the scheduler before it finished. Check the app log for the error.';

    private ScheduleRunStore $store;

    /**
     * Foreground tasks started in this process that have not finished yet.
     *
     * @var array<string, array{task: Event, started: float}>
     */
    private array $running = [];

    private bool $watchingShutdown = false;

    public function __construct(ScheduleRunStore $store)
    {
        $this->store = $store;
    }

    public function started(Event $task): void
    {
        if (! $task->runInBackground) {
            $this->running[spl_object_hash($task)] = ['task' => $task, 'started' => microtime(true)];
            $this->recordCrashesOnShutdown();
        }

        $this->record($task, function (array $run): array {
            return array_merge($run, [
                'started_at' => $this->now(),
                'finished_at' => null,
                'exit_code' => null,
                'runtime_ms' => null,
                'error' => null,
            ]);
        });
    }

    public function isRunning(Event $task): bool
    {
        return isset($this->running[spl_object_hash($task)]);
    }

    /**
     * A null exit code means the Laravel version did not report one.
     */
    public function finished(Event $task, ?int $exitCode, ?int $runtimeMs = null): void
    {
        $runtimeMs = $runtimeMs ?? $this->localRuntime($task);
        unset($this->running[spl_object_hash($task)]);

        $this->record($task, function (array $run) use ($exitCode, $runtimeMs): array {
            return array_merge($run, [
                'finished_at' => $this->now(),
                'exit_code' => $exitCode,
                'runtime_ms' => $runtimeMs ?? $this->storedRuntime($run),
            ]);
        });
    }

    public function failed(Event $task, int $exitCode, string $error): void
    {
        unset($this->running[spl_object_hash($task)]);

        $this->record($task, function (array $run) use ($exitCode, $error): array {
            return array_merge($run, [
                'finished_at' => $this->now(),
                'exit_code' => $exitCode,
                'error' => function_exists('mb_substr') ? mb_substr($error, 0, 500) : substr($error, 0, 500),
            ]);
        });
    }

    public function skipped(Event $task): void
    {
        $this->record($task, function (array $run): array {
            return array_merge($run, ['skipped_at' => $this->now()]);
        });
    }

    /**
     * Before Laravel 7 an exception thrown by a task ends schedule:run without
     * any event, so tasks still running at shutdown are recorded as failed.
     */
    private function recordCrashesOnShutdown(): void
    {
        if ($this->watchingShutdown || class_exists(ScheduledTaskFailed::class)) {
            return;
        }

        $this->watchingShutdown = true;

        register_shutdown_function(function (): void {
            foreach ($this->running as $running) {
                $this->failed($running['task'], 1, self::CRASH_MESSAGE);
            }
        });
    }

    private function localRuntime(Event $task): ?int
    {
        $running = $this->running[spl_object_hash($task)] ?? null;

        return $running !== null ? (int) round((microtime(true) - $running['started']) * 1000) : null;
    }

    /**
     * Background tasks finish in another process, so the runtime comes from
     * the stored start time.
     *
     * @param  array<string, mixed>  $run
     */
    private function storedRuntime(array $run): ?int
    {
        if (! is_string($run['started_at'] ?? null)) {
            return null;
        }

        return max(0, (int) round((Carbon::now()->getTimestamp() - Carbon::parse($run['started_at'])->getTimestamp()) * 1000));
    }

    private function now(): string
    {
        return Carbon::now()->toIso8601String();
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function record(Event $task, callable $change): void
    {
        if (! config('scheduler-monitor.enabled')) {
            return;
        }

        try {
            $this->store->update($task->mutexName(), $change);
        } catch (Throwable $exception) {
            //
        }
    }
}
