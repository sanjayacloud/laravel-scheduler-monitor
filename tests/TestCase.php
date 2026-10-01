<?php

declare(strict_types=1);

namespace SanjayaCloud\SchedulerMonitor\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SanjayaCloud\SchedulerMonitor\SchedulerMonitorServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $storePath;

    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir().'/scheduler-monitor-'.bin2hex(random_bytes(6)).'.json';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        @unlink($this->storePath);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [SchedulerMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('scheduler-monitor.path', $this->storePath);
    }
}
