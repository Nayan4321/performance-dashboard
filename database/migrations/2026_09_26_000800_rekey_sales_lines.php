<?php

use App\Services\Zenoti\ZenotiMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Sales lines get the new per-line key so the next sync adds the lines that used to overwrite each other. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales')->whereNotNull('raw')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $raw = json_decode((string) $row->raw, true);
                if (! is_array($raw)) {
                    continue;
                }
                $key = ZenotiMapper::saleKey($raw);
                if ($key === $row->zenoti_id) {
                    continue;
                }
                if (DB::table('sales')->where('zenoti_id', $key)->exists()) {
                    DB::table('sales')->where('id', $row->id)->delete(); // same line saved twice
                } else {
                    DB::table('sales')->where('id', $row->id)->update(['zenoti_id' => $key]);
                }
            }
        });
    }

    public function down(): void {}
};
