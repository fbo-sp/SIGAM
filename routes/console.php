<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sigam:charges:generate')->dailyAt('01:10')->withoutOverlapping();
Schedule::command('sigam:charges:mark-overdue')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('sigam:notifications:generate')->hourly()->withoutOverlapping();
Schedule::command('sigam:notifications:send --limit=50')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sigam:teacher-invitations:send --limit=50')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sigam:imports:purge-files --days=30')->dailyAt('02:10')->withoutOverlapping();
Schedule::command('sigam:sessions:generate')->hourly()->withoutOverlapping();
