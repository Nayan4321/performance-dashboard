<?php

use App\Services\Zenoti\EmployeeMatcher;
use App\Services\Zenoti\ZenotiMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Sales synced before employee matching by code / name have no employee; re-read it from the stored Zenoti row. */
return new class extends Migration
{
    public function up(): void
    {
        $matcher = new EmployeeMatcher;
        DB::table('sales')->whereNull('employee_id')->whereNotNull('raw')->orderBy('id')->chunkById(500, function ($rows) use ($matcher) {
            foreach ($rows as $row) {
                $raw = json_decode((string) $row->raw, true);
                if (is_array($raw) && ($id = $matcher->match(ZenotiMapper::sale($raw)))) {
                    DB::table('sales')->where('id', $row->id)->update(['employee_id' => $id]);
                }
            }
        });
    }

    public function down(): void {}
};
