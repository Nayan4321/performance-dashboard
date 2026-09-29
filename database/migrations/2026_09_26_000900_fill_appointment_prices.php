<?php

use App\Services\Zenoti\ZenotiMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Appointments saved with price 0: re-read the price from the stored Zenoti fields with the wider mapping. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('appointments')->where('price', 0)->whereNotNull('raw')->orderBy('id')->chunkById(1000, function ($rows) {
            foreach ($rows as $row) {
                $raw = json_decode((string) $row->raw, true);
                $price = is_array($raw) ? ZenotiMapper::appointment($raw)['price'] : 0;
                if ($price > 0) {
                    DB::table('appointments')->where('id', $row->id)->update(['price' => $price]);
                }
            }
        });
    }

    public function down(): void {}
};
