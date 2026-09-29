<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phone number without country code / leading 0, so a CallGear caller (9715XXXXXXXX) matches a Zenoti guest (55 655 5675). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('phone_key', 16)->nullable()->index()->after('phone');
        });
        Schema::table('calls', function (Blueprint $table) {
            $table->foreignId('guest_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
        });

        DB::table('guests')->whereNotNull('phone')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $g) {
                DB::table('guests')->where('id', $g->id)->update(['phone_key' => \App\Models\Guest::phoneKey($g->phone)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('calls', fn (Blueprint $t) => $t->dropConstrainedForeignId('guest_id'));
        Schema::table('guests', fn (Blueprint $t) => $t->dropColumn('phone_key'));
    }
};
