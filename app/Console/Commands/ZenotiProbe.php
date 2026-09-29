<?php

namespace App\Console\Commands;

use App\Services\Zenoti\ZenotiClient;
use Illuminate\Console\Command;

/** Prints a raw Zenoti response so field mappings can be checked against real data. */
class ZenotiProbe extends Command
{
    protected $signature = 'zenoti:probe {endpoint=centers : centers|employees|guests|appointments|sales|leads or a raw path} {--center=}';

    protected $description = 'Show a raw Zenoti API response (first 3 rows) to verify field mappings';

    public function handle(ZenotiClient $client): int
    {
        $name = $this->argument('endpoint');
        $center = (string) $this->option('center');
        $path = config("zenoti.endpoints.$name") !== null ? $client->endpoint($name, ['center_id' => $center]) : $name;
        $today = now()->toDateString();

        $data = $client->get($path, array_filter([
            'center_id' => $center ?: null, 'start_date' => $today, 'end_date' => $today, 'page' => 1, 'size' => 3,
        ]));
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
