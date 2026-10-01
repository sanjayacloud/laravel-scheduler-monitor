<?php

declare(strict_types=1);

namespace SanjayaCloud\SchedulerMonitor;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;

/**
 * Listens to the scheduler events of Laravel 6 and later. Each event class
 * is only listened to on the Laravel versions that have it.
 */
class RecordScheduledTaskRun
{
    private ScheduledTaskRecorder $recorder;

    public function __construct(ScheduledTaskRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        $this->recorder->started($event->task);
    }

    /**
     * Background tasks report their exit code later, through schedule:finish.
     */
    public function finished(ScheduledTaskFinished $event): void
    {
        if ($event->task->runInBackground) {
            return;
        }

        $this->recorder->finished(
            $event->task,
            (int) ($event->task->exitCode ?? 0),
            (int) round((float) $event->runtime * 1000),
        );
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->recorder->failed(
            $event->task,
            (int) ($event->task->exitCode ?: 1),
            $event->exception->getMessage(),
        );
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->recorder->skipped($event->task);
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->recorder->finished($event->task, (int) ($event->task->exitCode ?? 0));
    }
}
