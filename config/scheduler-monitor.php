<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    |
    | When disabled, scheduled task runs are not recorded. Tasks are still
    | listed by the scheduler-monitor:list command, without run details.
    |
    */

    'enabled' => env('SCHEDULER_MONITOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | The JSON file that holds the last run of each scheduled task. It keeps
    | one entry per task, so it stays small and needs no pruning. The
    | monitoring agent reads it through scheduler-monitor:list --json.
    |
    */

    'path' => env('SCHEDULER_MONITOR_PATH', storage_path('framework/scheduler-monitor.json')),

];
