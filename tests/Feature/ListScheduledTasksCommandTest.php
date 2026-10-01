<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use SanjayaCloud\SchedulerMonitor\Console\ListScheduledTasksCommand;

it('lists tasks that never ran with empty run details', function (): void {
    app(Schedule::class)->command('inspire')->dailyAt('02:00')->timezone('Asia/Colombo');

    $listed = listedTasks();

    expect($listed)
        ->version->toBe(ListScheduledTasksCommand::FORMAT_VERSION)
        ->since->toBeNull()
        ->and($listed['tasks'])->toHaveCount(1)
        ->and($listed['tasks'][0])->toMatchArray([
            'expression' => '0 2 * * *',
            'timezone' => 'Asia/Colombo',
            'started_at' => null,
            'exit_code' => null,
        ])
        ->and($listed['tasks'][0]['command'])->toContain('inspire');
});

it('leaves out tasks limited to other environments', function (): void {
    $schedule = app(Schedule::class);
    $schedule->call(fn (): null => null)->hourly()->description('everywhere');
    $schedule->call(fn (): null => null)->hourly()->description('production-only')->environments('production');

    expect(array_column(listedTasks()['tasks'], 'command'))->toBe(['everywhere']);
});

it('shows a readable table without the json option', function (): void {
    app(Schedule::class)->call(fn (): null => null)->hourly()->description('sync-orders');

    expect(Artisan::call('scheduler-monitor:list'))->toBe(0)
        ->and(Artisan::output())->toContain('sync-orders')->toContain('Never');
});
