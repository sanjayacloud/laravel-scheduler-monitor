<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SanjayaCloud\SchedulerMonitor\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * @return array{version: int, since: string|null, tasks: list<array<string, mixed>>}
 */
function listedTasks(): array
{
    Artisan::call('scheduler-monitor:list', ['--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}
