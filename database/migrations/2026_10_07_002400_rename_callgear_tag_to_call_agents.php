<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Nayan (2026-10-07): the employee tag "Callgear" is now "Call agents" (dashboard names stay as they are).
return new class extends Migration
{
    public function up(): void
    {
        $old = DB::table('tags')->whereRaw('LOWER(name) = ?', ['callgear'])->first();
        $new = DB::table('tags')->whereRaw('LOWER(name) = ?', ['call agents'])->first();
        if ($old && $new) {
            // Both exist: move everyone onto "Call agents" and drop the old tag.
            $already = DB::table('employee_tag')->where('tag_id', $new->id)->pluck('employee_id')->all();
            DB::table('employee_tag')->where('tag_id', $old->id)->whereIn('employee_id', $already ?: [0])->delete();
            DB::table('employee_tag')->where('tag_id', $old->id)->update(['tag_id' => $new->id]);
            DB::table('tags')->where('id', $old->id)->delete();
        } elseif ($old) {
            DB::table('tags')->where('id', $old->id)->update(['name' => 'Call agents']);
        }
        DB::table('dashboards')->whereRaw('LOWER(employee_tag) = ?', ['callgear'])->update(['employee_tag' => 'Call agents']);
        DB::table('dashboards')->where('description', 'like', 'Agents tagged Callgear%')
            ->update(['description' => DB::raw("REPLACE(description, 'Agents tagged Callgear', 'Agents tagged Call agents')")]);
    }

    public function down(): void {}
};
