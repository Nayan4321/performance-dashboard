<?php

use App\Services\Zenoti\ZenotiMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Sales synced before the wider date mapping have no sold_at; re-read it from the stored Zenoti row. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales')->whereNull('sold_at')->whereNotNull('raw')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $raw = json_decode((string) $row->raw, true);
                if (! is_array($raw)) {
                    continue;
                }
                $data = ZenotiMapper::sale($raw);
                $update = array_filter([
                    'sold_at' => $data['sold_at'],
                    'invoice_no' => $row->invoice_no ?? $data['invoice_no'],
                    'net_amount' => (float) $row->net_amount ?: $data['net_amount'],
                ], fn ($v) => $v !== null);
                if ($update) {
                    DB::table('sales')->where('id', $row->id)->update($update);
                }
            }
        });
    }

    public function down(): void {}
};
