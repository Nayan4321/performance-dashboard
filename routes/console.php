<?php

use Illuminate\Support\Facades\Schedule;

/*
| Hostinger: add ONE cron job in hPanel (Advanced > Cron Jobs), every minute. Admin > Integrations shows the
| exact command for this server (it needs the PHP 8.3 binary, e.g. /opt/alt/php83/usr/bin/php):
|   /opt/alt/php83/usr/bin/php /home/USER/domains/YOUR-DOMAIN/public_html/dashboard/artisan schedule:run
| (no "cd ... &&" or ">> log": that form never ran on Hostinger; hPanel shows the output instead)
| Webhooks give near-instant updates; these runs catch anything a webhook missed.
*/
// Heartbeat for the "cron isn't running" check on Admin › Integrations.
Schedule::call(fn () => \App\Console\Commands\RunSyncRequests::beat())->everyMinute()->name('scheduler-heartbeat');
// Automatic sync on the server, every 5 minutes, whether or not anyone has the site open:
// the Call agents' data first, then everything else (see AutoSyncAll for the order and intervals).
Schedule::command('integrations:auto-sync')->everyFiveMinutes()->withoutOverlapping(180);
// "Sync now" clicks from Admin › Integrations; runs in the background so the browser doesn't time out.
Schedule::command('integrations:run-requests')->everyMinute()->withoutOverlapping(240);
