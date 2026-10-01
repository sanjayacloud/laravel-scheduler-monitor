<?php

declare(strict_types=1);

namespace SanjayaCloud\SchedulerMonitor;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SanjayaCloud\SchedulerMonitor\Console\ListScheduledTasksCommand;

class SchedulerMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scheduler-monitor.php', 'scheduler-monitor');

        $this->app->singleton(ScheduleRunStore::class, function (): ScheduleRunStore {
            return new ScheduleRunStore((string) config('scheduler-monitor.path'));
        });
        $this->app->singleton(ScheduledTaskRecorder::class);
        $this->app->singleton(ScheduleCallbackHooks::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/scheduler-monitor.php' => config_path('scheduler-monitor.php'),
        ], 'scheduler-monitor-config');

        $this->commands([ListScheduledTasksCommand::class]);

        $this->listenToSchedulerEvents();

        if (! class_exists(ScheduledBackgroundTaskFinished::class)) {
            $this->hookIntoScheduleCallbacks(! class_exists(ScheduledTaskStarting::class));
        }
    }

    private function listenToSchedulerEvents(): void
    {
        $listeners = [
            ScheduledTaskStarting::class => 'starting',
            ScheduledTaskFinished::class => 'finished',
            ScheduledTaskFailed::class => 'failed',
            ScheduledTaskSkipped::class => 'skipped',
            ScheduledBackgroundTaskFinished::class => 'backgroundFinished',
        ];

        foreach ($listeners as $event => $method) {
            if (class_exists($event)) {
                Event::listen($event, RecordScheduledTaskRun::class.'@'.$method);
            }
        }
    }

    /**
     * Laravel 5.4 to 5.7 define the schedule when the app has booted, and
     * later versions when the schedule is first resolved.
     */
    private function hookIntoScheduleCallbacks(bool $allTasks): void
    {
        $attach = function (Schedule $schedule) use ($allTasks): void {
            $this->app->make(ScheduleCallbackHooks::class)->attach($schedule, $allTasks);
        };

        $this->app->afterResolving(Schedule::class, $attach);

        $this->app->booted(function () use ($attach): void {
            if ($this->app->resolved(Schedule::class)) {
                $attach($this->app->make(Schedule::class));
            }
        });
    }
}
