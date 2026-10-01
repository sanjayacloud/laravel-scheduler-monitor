#!/usr/bin/env bash
#
# Installs the package into an existing Laravel 5.4 to 10 app, runs a real
# schedule, and checks what was recorded.
#
# Usage: tests/Legacy/smoke.sh <path-to-laravel-app> [php-binary]

set -euo pipefail

APP="$(cd "$1" && pwd)"
PHP="${2:-php}"
PACKAGE="$(cd "$(dirname "$0")/../.." && pwd)"
COMPOSER="$(command -v composer)"

cd "$APP"

"$PHP" "$COMPOSER" config audit.block-insecure false
"$PHP" "$COMPOSER" config repositories.scheduler-monitor path "$PACKAGE"
"$PHP" "$COMPOSER" require "sanjayacloud/laravel-scheduler-monitor:*@dev" --no-interaction --no-progress --quiet --no-scripts
"$PHP" "$COMPOSER" dump-autoload --quiet

VERSION="$("$PHP" artisan --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"

# Package auto-discovery only exists from Laravel 5.5.
if ! "$PHP" -r "exit(version_compare('$VERSION', '5.5', '>=') ? 0 : 1);"; then
    grep -q 'SchedulerMonitorServiceProvider' config/app.php || \
        "$PHP" -r '$f = "config/app.php"; file_put_contents($f, preg_replace("/(App\\\\Providers\\\\RouteServiceProvider::class,)/", "$1\n        SanjayaCloud\\\\SchedulerMonitor\\\\SchedulerMonitorServiceProvider::class,", file_get_contents($f), 1));'
fi

cp "$PACKAGE/tests/Legacy/Kernel.php" app/Console/Kernel.php
rm -f storage/framework/scheduler-monitor.json

"$PHP" artisan schedule:run > /dev/null 2>&1 || true
sleep 3

"$PHP" artisan scheduler-monitor:list --json --no-ansi 2>/dev/null | "$PHP" "$PACKAGE/tests/Legacy/check.php" "$VERSION"
