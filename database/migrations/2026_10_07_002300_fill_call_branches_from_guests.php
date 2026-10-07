<?php

use App\Models\Guest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Calls without a branch get the home branch of the client on the call (matched by phone), so calls can be counted per branch.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('calls')->whereNull('branch_id')->orderBy('id')->select(['id', 'caller', 'guest_id'])->chunkById(500, function ($calls) {
            foreach ($calls as $c) {
                $guest = $c->guest_id ? DB::table('guests')->where('id', $c->guest_id)->first(['id', 'branch_id']) : null;
                if (! $guest && ($key = Guest::phoneKey($c->caller))) {
                    $guest = DB::table('guests')->where('phone_key', $key)->orderByDesc('id')->first(['id', 'branch_id']);
                }
                if ($guest) {
                    DB::table('calls')->where('id', $c->id)->update(['guest_id' => $c->guest_id ?? $guest->id, 'branch_id' => $guest->branch_id]);
                }
            }
        });
    }

    public function down(): void {}
};
