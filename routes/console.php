<?php

use App\Models\SyncRequest;
use Illuminate\Support\Facades\Schedule;

/*
| Hostinger: add ONE cron job in hPanel (Advanced > Cron Jobs), every minute:
|   cd /home/USER/domains/YOUR-DOMAIN/app && php artisan schedule:run >> /dev/null 2>&1
| Webhooks give near-instant updates; these runs catch anything a webhook missed.
*/
// "Sync now" clicks from Admin › Integrations; runs in the background so the browser doesn't time out.
// Heartbeat for the "cron isn't running" check on Admin › Integrations.
Schedule::call(fn () => \App\Console\Commands\RunSyncRequests::beat())->everyMinute()->name('scheduler-heartbeat');
Schedule::command('integrations:run-requests')->everyMinute()->withoutOverlapping(240);
Schedule::command('integrations:sync zenoti --entity=appointments')->everyFiveMinutes()->withoutOverlapping(30)
    ->skip(fn () => SyncRequest::where('status', 'running')->exists());
// Only a running sales "Sync now" holds this back; a long guest backfill must not starve revenue.
Schedule::command('integrations:sync zenoti --entity=sales')->everyFiveMinutes()->withoutOverlapping(30)
    ->skip(fn () => SyncRequest::where('status', 'running')->where(fn ($q) => $q->whereNull('entity')->orWhere('entity', 'sales'))->exists());
// Invoices close days after the sale (and closing decides what counts), so re-read two weeks every few hours.
Schedule::command('integrations:sync zenoti --entity=sales --days=14')->everyThreeHours()->withoutOverlapping(120);
Schedule::command('integrations:sync zenoti --entity=guests')->everyFifteenMinutes()->withoutOverlapping(30)
    ->skip(fn () => SyncRequest::where('status', 'running')->exists());
Schedule::command('integrations:sync zenoti --entity=leads')->everyFifteenMinutes()->withoutOverlapping(30)
    ->skip(fn () => SyncRequest::where('status', 'running')->exists());
Schedule::command('integrations:sync zenoti --entity=employees')->hourly()->withoutOverlapping(60)
    ->skip(fn () => SyncRequest::where('status', 'running')->exists());
Schedule::command('integrations:sync zenoti --entity=centers')->daily()->withoutOverlapping(60)
    ->skip(fn () => SyncRequest::where('status', 'running')->exists());
Schedule::command('integrations:sync callgear')->everyFiveMinutes()->withoutOverlapping(30);
