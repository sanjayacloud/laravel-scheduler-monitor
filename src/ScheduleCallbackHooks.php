<?php

declare(strict_types=1);

namespace SanjayaCloud\SchedulerMonitor;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use ReflectionProperty;
use SplObjectStorage;
use Throwable;

/**
 * Records runs through each task's before and after callbacks, for the
 * Laravel versions that do not fire the matching scheduler events.
 */
class ScheduleCallbackHooks
{
    private ScheduledTaskRecorder $recorder;

    /**
     * @var SplObjectStorage<Event, null>
     */
    private SplObjectStorage $hooked;

    /**
     * Closure tasks that threw in this process, so their after callbacks,
     * which Laravel 5.5 to 5.8 still call, do not record a success.
     *
     * @var SplObjectStorage<Event, null>
     */
    private SplObjectStorage $failed;

    public function __construct(ScheduledTaskRecorder $recorder)
    {
        $this->recorder = $recorder;
        $this->hooked = new SplObjectStorage;
        $this->failed = new SplObjectStorage;
    }

    /**
     * With $allTasks false only background tasks are hooked, to catch their
     * finish on Laravel 6 and 7.
     */
    public function attach(Schedule $schedule, bool $allTasks): void
    {
        foreach ($schedule->events() as $task) {
            if ($this->hooked->contains($task) || (! $allTasks && ! $task->runInBackground)) {
                continue;
            }

            $this->hooked->attach($task);

            if ($allTasks) {
                $task->before(function () use ($task): void {
                    $this->recorder->started($task);
                });

                if ($task instanceof CallbackEvent) {
                    $this->wrapCallback($task);
                }
            }

            $task->after(function () use ($task): void {
                if ($this->failed->contains($task)) {
                    $this->failed->detach($task);

                    return;
                }

                $this->recorder->finished($task, $this->exitCode($task));
            });
        }
    }

    /**
     * Before Laravel 6 a closure task's exception is not reported anywhere,
     * and Laravel 5.4 skips its before callbacks, so the closure itself is
     * wrapped. Its mutex name depends only on the description, not on the
     * callback, so the task key does not change.
     */
    private function wrapCallback(CallbackEvent $task): void
    {
        $callback = $this->accessibleProperty('callback');
        $parameters = $this->accessibleProperty('parameters');
        $original = $callback->getValue($task);
        $originalParameters = $parameters->getValue($task);

        $parameters->setValue($task, []);
        $callback->setValue($task, function () use ($task, $original, $originalParameters) {
            if (! $this->recorder->isRunning($task)) {
                $this->recorder->started($task);
            }

            try {
                return Container::getInstance()->call($original, $originalParameters);
            } catch (Throwable $exception) {
                $this->failed->attach($task);
                $this->recorder->failed($task, 1, $exception->getMessage());

                throw $exception;
            }
        });
    }

    private function accessibleProperty(string $name): ReflectionProperty
    {
        $property = new ReflectionProperty(CallbackEvent::class, $name);

        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property;
    }

    /**
     * Laravel 5.4 to 5.7 never record a command's exit code. A closure task
     * that reaches its after callbacks without throwing succeeded.
     */
    private function exitCode(Event $task): ?int
    {
        if ($task instanceof CallbackEvent) {
            return 0;
        }

        return isset($task->exitCode) ? (int) $task->exitCode : null;
    }
}
