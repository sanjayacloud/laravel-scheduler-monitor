<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-01 10:00:30');
});

it('records a successful run with its runtime', function (): void {
    app(Schedule::class)->call(fn (): null => null)->everyMinute()->description('sync-orders');

    $this->artisan('schedule:run')->assertSuccessful();

    $task = listedTasks()['tasks'][0];

    expect($task)
        ->command->toBe('sync-orders')
        ->expression->toBe('* * * * *')
        ->started_at->toBe('2026-10-01T10:00:30+00:00')
        ->finished_at->toBe('2026-10-01T10:00:30+00:00')
        ->exit_code->toBe(0)
        ->runtime_ms->toBeInt()
        ->error->toBeNull()
        ->and(listedTasks()['since'])->toBe('2026-10-01T10:00:30+00:00');
});

it('records the exit code and error of a failing command', function (): void {
    app(Schedule::class)->exec('exit 3')->everyMinute();

    $this->artisan('schedule:run');

    expect(listedTasks()['tasks'][0])
        ->exit_code->toBe(3)
        ->error->toContain('failed with exit code [3]');
});

it('records an exception thrown by a callback task', function (): void {
    app(Schedule::class)->call(function (): never {
        throw new RuntimeException('Payment gateway unreachable');
    })->everyMinute()->description('charge-cards');

    $this->artisan('schedule:run');

    expect(listedTasks()['tasks'][0])
        ->exit_code->toBe(1)
        ->error->toBe('Payment gateway unreachable');
});

it('records a skipped run without touching the last run', function (): void {
    $schedule = app(Schedule::class);
    $schedule->call(fn (): null => null)->everyMinute()->description('reports');

    $this->artisan('schedule:run');

    Carbon::setTestNow('2026-10-01 10:01:30');
    $schedule->events()[0]->skip(fn (): bool => true);

    $this->artisan('schedule:run');

    expect(listedTasks()['tasks'][0])
        ->started_at->toBe('2026-10-01T10:00:30+00:00')
        ->exit_code->toBe(0)
        ->skipped_at->toBe('2026-10-01T10:01:30+00:00');
});

it('clears the previous failure when the task runs again', function (): void {
    $flag = $this->storePath.'.flag';
    app(Schedule::class)->exec('test -f '.escapeshellarg($flag))->everyMinute();

    $this->artisan('schedule:run');

    Carbon::setTestNow('2026-10-01 10:01:30');
    touch($flag);
    $this->artisan('schedule:run');
    unlink($flag);

    expect(listedTasks()['tasks'][0])
        ->started_at->toBe('2026-10-01T10:01:30+00:00')
        ->exit_code->toBe(0)
        ->error->toBeNull();
});

it('does not record runs when disabled', function (): void {
    config(['scheduler-monitor.enabled' => false]);
    app(Schedule::class)->call(fn (): null => null)->everyMinute();

    $this->artisan('schedule:run');

    expect(listedTasks())
        ->since->toBeNull()
        ->tasks->{0}->started_at->toBeNull()
        ->and(file_exists($this->storePath))->toBeFalse();
});
