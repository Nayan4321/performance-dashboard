<?php

namespace App\Services\Zenoti;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZenotiClient
{
    /** Short sample of the last response that yielded no rows, so an empty sync can say what Zenoti sent. */
    public ?string $lastEmptyResponse = null;
    public function isConfigured(): bool
    {
        return (bool) config('zenoti.enabled') && filled(config('zenoti.api_key'));
    }

    protected function http(): PendingRequest
    {
        if (! filled(config('zenoti.api_key'))) {
            throw new RuntimeException('ZENOTI_API_KEY is not set in .env');
        }

        return Http::baseUrl(rtrim(config('zenoti.base_url'), '/'))
            ->withHeaders(['Authorization' => 'apikey '.config('zenoti.api_key')])
            ->acceptJson()
            ->timeout(30)
            // Retry blips, but not 429: another call straight away only uses up more of the quota.
            ->retry(2, 1000, fn ($e) => ! ($e instanceof \Illuminate\Http\Client\RequestException && $e->response->status() === 429), throw: false);
    }

    public function endpoint(string $name, array $replace = []): string
    {
        $path = (string) config("zenoti.endpoints.$name");
        foreach ($replace as $k => $v) {
            $path = str_replace('{'.$k.'}', urlencode((string) $v), $path);
        }

        return ltrim($path, '/');
    }

    public function get(string $path, array $query = []): array
    {
        $response = $this->http()->get($path, $query);
        if ($response->status() === 429) {
            throw new ZenotiQuotaExceeded("Zenoti GET $path failed (429): ".mb_substr($response->body(), 0, 300));
        }
        if ($response->failed()) {
            throw new RuntimeException("Zenoti GET $path failed ({$response->status()}): ".mb_substr($response->body(), 0, 500));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    public function post(string $path, array $body = []): array
    {
        $response = $this->http()->post($path, $body);
        if ($response->status() === 429) {
            throw new ZenotiQuotaExceeded("Zenoti POST $path failed (429): ".mb_substr($response->body(), 0, 300));
        }
        if ($response->failed()) {
            throw new RuntimeException("Zenoti POST $path failed ({$response->status()}): ".mb_substr($response->body(), 0, 500));
        }
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /** Rows from a list response: a bare JSON list, one of the named keys, or the first list found. */
    public function rows(array $data, array $keys, string $what = ''): array
    {
        if (array_is_list($data)) {
            $rows = $data;
        } else {
            $rows = null;
            foreach ($keys as $k) {
                if (is_array($v = data_get($data, $k))) {
                    $rows = $v;
                    break;
                }
            }
            $rows ??= $this->firstList($data);
        }
        if (! $rows) {
            $this->lastEmptyResponse = trim($what.' '.mb_substr(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 400));
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Fetch every page of a list endpoint. Zenoti list responses carry the rows
     * under a named key (e.g. "employees") plus a "page_info" block.
     */
    public function paginate(string $path, string $rowsKey, array $query = []): array
    {
        $size = (int) config('zenoti.page_size', 100);
        $rows = [];

        for ($page = 1; $page <= (int) config('zenoti.max_pages', 200); $page++) {
            $data = $this->get($path, $query + ['page' => $page, 'size' => $size]);
            $batch = $this->rows($data, [$rowsKey], $path);
            $rows = array_merge($rows, $batch);

            $total = (int) data_get($data, 'page_info.total', 0);
            if (count($batch) < $size || ($total && count($rows) >= $total)) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Report endpoints (sales) page their rows too; keep asking until a short or repeated page.
     * Stops if the API ignores paging and sends the same rows again.
     */
    private function pagedRows(string $path, array $query, array $keys, string $what): array
    {
        $size = (int) config('zenoti.page_size', 100);
        $rows = [];
        $previous = null;
        for ($page = 1; $page <= (int) config('zenoti.max_pages', 200); $page++) {
            $data = $this->get($path, $query + ['page' => $page, 'size' => $size]);
            $batch = $this->rows($data, $keys, $what);
            $sig = $batch ? md5(json_encode($batch[0]).count($batch)) : null;
            if (! $batch || $sig === $previous) {
                break;
            }
            $rows = array_merge($rows, $batch);
            $previous = $sig;
            $total = (int) (data_get($data, 'page_info.total') ?? data_get($data, 'page_Info.total') ?? 0);
            if (count($batch) < $size || ($total && count($rows) >= $total)) {
                break;
            }
        }

        return $rows;
    }

    /** Fallback when the rows key differs from what we expect: take the first list in the response. */
    private function firstList(array $data): array
    {
        foreach ($data as $value) {
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        return [];
    }

    public function centers(): array
    {
        return $this->paginate($this->endpoint('centers'), 'centers');
    }

    public function employees(string $centerId): array
    {
        return $this->paginate($this->endpoint('employees', ['center_id' => $centerId]), 'employees');
    }

    public function guests(string $centerId, string $from, string $to): array
    {
        return $this->paginate($this->endpoint('guests', ['center_id' => $centerId]), 'guests', [
            'center_id' => $centerId, 'created_date_from' => $from, 'created_date_to' => $to,
        ]);
    }

    /** One guest's full profile, the same record Zenoti's guest profile page shows. */
    public function guest(string $guestId): array
    {
        $data = $this->get($this->endpoint('guest', ['guest_id' => $guestId]));

        return is_array($data['guest'] ?? null) ? $data['guest'] : $data;
    }

    public function appointments(string $centerId, string $from, string $to): array
    {
        // Zenoti treats end_date as exclusive (start = end returns nothing), so ask up to the next day.
        $data = $this->get($this->endpoint('appointments', ['center_id' => $centerId]), [
            'center_id' => $centerId, 'start_date' => $from, 'end_date' => \Carbon\Carbon::parse($to)->addDay()->toDateString(),
        ]);

        return $this->rows($data, ['appointments', 'appointment_list'], "appointments $from..$to:");
    }

    public function sales(string $centerId, string $from, string $to): array
    {
        $path = $this->endpoint('sales', ['center_id' => $centerId]);
        $query = ['center_id' => $centerId, 'start_date' => $from, 'end_date' => $to];
        $types = array_filter(explode(',', (string) config('zenoti.sales_item_types')), 'strlen');

        if (! $types) {
            try {
                return $this->pagedRows($path, $query, ['center_sales_report', 'sales'], "sales $from..$to:");
            } catch (RuntimeException $e) {
                if (! str_contains(strtolower($e->getMessage()), 'item_typ')) {
                    throw $e;
                }
                $types = range(0, 7); // Zenoti wants one item type per call
            }
        }

        // One call per item type; types this tenant rejects are skipped.
        $rows = [];
        $errors = [];
        foreach ($types as $type) {
            try {
                $rows = array_merge($rows, $this->pagedRows($path, $query + ['item_type' => $type], ['center_sales_report', 'sales'], "sales $from..$to type $type:"));
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if (count($errors) === count($types)) {
            throw new RuntimeException('Every sales item type failed. '.$errors[0]);
        }

        return $rows;
    }

    /** Why the last accrual pull stopped short of Zenoti's reported total, if it did. */
    public ?string $lastShortfall = null;

    /**
     * Every sales line for one branch from Zenoti's accrual-basis sales report, page by page.
     * Rows from other branches (should the filter be ignored) are dropped.
     */
    public function salesAccrual(string $centerId, string $from, string $to): array
    {
        $path = 'reports/sales/accrual_basis/flat_file';
        $size = (int) config('zenoti.page_size', 100);
        $body = ['center_ids' => [$centerId], 'start_date' => "$from 00:00:00", 'end_date' => "$to 23:59:59"];
        $rows = [];
        $fetched = 0;
        $previous = null;
        $total = 0;
        for ($page = 1; $page <= (int) config('zenoti.max_pages', 200); $page++) {
            $data = $this->post($path.'?'.http_build_query(['page' => $page, 'size' => $size]), $body);
            if ($error = data_get($data, 'error.message')) {
                throw new RuntimeException("Zenoti sales report: $error");
            }
            $batch = array_values(array_filter((array) ($data['sales'] ?? []), 'is_array'));
            $sig = $batch ? md5(json_encode($batch[0]).count($batch)) : null;
            if (! $batch || $sig === $previous) {
                break;
            }
            $previous = $sig;
            $fetched += count($batch);
            foreach ($batch as $row) {
                if (empty($row['center_id']) || (string) $row['center_id'] === $centerId) {
                    $rows[] = $row;
                }
            }
            $total = (int) (data_get($data, 'total') ?? data_get($data, 'page_info.total') ?? 0);
            if ($total ? $fetched >= $total : count($batch) < $size) {
                break;
            }
        }
        $this->lastShortfall = $total && $fetched < $total ? "sales $from..$to: got $fetched of $total lines" : null;

        return $rows;
    }

    public function collections(string $centerId, string $from, string $to): array
    {
        if (! config('zenoti.endpoints.collections')) {
            return [];
        }
        $data = $this->get($this->endpoint('collections', ['center_id' => $centerId]), [
            'center_id' => $centerId, 'start_date' => $from, 'end_date' => $to,
        ]);

        return $this->rows($data, ['collections', 'payments'], "collections $from..$to:");
    }

    public function leads(string $centerId, string $from, string $to): array
    {
        if (! config('zenoti.endpoints.leads')) {
            return [];
        }

        return $this->paginate($this->endpoint('leads', ['center_id' => $centerId]), 'opportunities', [
            'center_id' => $centerId, 'from_date' => $from, 'to_date' => $to,
        ]);
    }
}
