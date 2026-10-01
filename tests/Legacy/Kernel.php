<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use RuntimeException;

/**
 * Copied over app/Console/Kernel.php of a Laravel 5.4 to 10 app by smoke.sh.
 * The throwing closure is last because before Laravel 7 it ends schedule:run.
 */
class Kernel extends ConsoleKernel
{
    protected $commands = [];

    protected function schedule(Schedule $schedule)
    {
        $schedule->exec('true')->everyMinute()->description('ok-command');
        $schedule->exec('exit 4')->everyMinute()->description('failing-command');
        $schedule->call(function () {
            return null;
        })->everyMinute()->description('closure-ok');
        $schedule->exec('echo skipped')->everyMinute()->when(function () {
            return false;
        })->description('skipped');
        $schedule->exec('sleep 1')->everyMinute()->runInBackground()->description('background');
        $schedule->call(function () {
            throw new RuntimeException('closure exploded');
        })->everyMinute()->description('closure-fail');
    }

    protected function commands()
    {
        //
    }
}
