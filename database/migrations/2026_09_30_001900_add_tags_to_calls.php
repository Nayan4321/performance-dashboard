<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The result tags agents put on calls in CallGear ("Outgoing new sale", "Complaint"...), comma separated. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('calls', 'tags')) {
            Schema::table('calls', fn (Blueprint $table) => $table->string('tags', 500)->nullable()->after('status'));
        }
        // Calls synced since tags were first requested already carry them in raw.
        DB::table('calls')->where('raw', 'like', '%tag_name%')->orderBy('id')->chunkById(500, function ($calls) {
            foreach ($calls as $call) {
                $tags = \App\Services\CallGear\CallGearSource::tagNames(json_decode((string) $call->raw, true) ?: []);
                if ($tags !== null) {
                    DB::table('calls')->where('id', $call->id)->update(['tags' => $tags]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('calls', fn (Blueprint $table) => $table->dropColumn('tags'));
    }
};
