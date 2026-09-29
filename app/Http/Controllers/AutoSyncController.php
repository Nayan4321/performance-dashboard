<?php

namespace App\Http\Controllers;

use App\Support\AutoSync;
use Illuminate\Http\Request;

/** Starts the automatic syncs after the reply is sent, so the caller never waits. */
class AutoSyncController extends Controller
{
    /** GET/POST /cron/run?token=... for an outside pinger or hPanel cron with curl. */
    public function run(Request $request)
    {
        abort_unless(hash_equals(AutoSync::token(), (string) $request->query('token')), 403);
        $this->later();

        return response()->json(['started' => true]);
    }

    /** POST /auto-sync from pages people have open; only when a run is due. */
    public function nudge()
    {
        if (AutoSync::due()) {
            $this->later();
        }

        return response()->json(['ok' => true]);
    }

    private function later(): void
    {
        app()->terminating(fn () => AutoSync::tick());
    }
}
