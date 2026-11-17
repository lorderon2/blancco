<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('blancco:expire')->everyMinute()->withoutOverlapping();

Schedule::command('blancco:sync bc')->when(fn () => config('blancco.sync_enabled') && config('blancco.bc.enabled'))->everyTwoMinutes()->withoutOverlapping();
Schedule::command('blancco:sync departments')->when(fn () => config('blancco.sync_enabled') && config('blancco.departments.enabled'))->everyTwoMinutes()->withoutOverlapping();

Schedule::command('blancco:sync-idosell')->when(fn () => config('blancco.sync_enabled') && config('blancco.idosell.enabled'))->everyTwoMinutes()->withoutOverlapping();

Schedule::command('blancco:detect-stalled')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('blancco:dispatch-bitrix')->when(fn () => config('blancco.sync_enabled') && config('blancco.bitrix.enabled'))->everyTwoMinutes()->withoutOverlapping();
