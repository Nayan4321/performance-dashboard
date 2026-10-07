<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Sale;
use App\Models\Tag;
use App\Support\WidgetQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Upload the client's Zenoti "Sales-Accrual" export and compare it, per Callgear agent and per
 * invoice, with the revenue the dashboard counts, so any gap shows the exact invoices behind it.
 * The file is read in memory and not stored.
 */
class RevenueCompareController extends Controller
{
    public function show()
    {
        return view('admin.integrations.compare', ['result' => null]);
    }

    public function compare(Request $request)
    {
        $request->validate(['file' => 'required|file|max:51200']);
        $fh = fopen($request->file('file')->getRealPath(), 'r');
        if (fread($fh, 3) !== "\xEF\xBB\xBF") {
            rewind($fh);
        }
        $head = fgetcsv($fh);
        if (! $head) {
            return back()->withErrors(['file' => 'The file is empty.']);
        }
        $head = array_map(fn ($h) => trim((string) $h, " \t\"'"), $head);
        $col = fn (array $names) => collect($names)->map(fn ($n) => array_search($n, $head, true))->first(fn ($i) => $i !== false);
        $c = [
            'creator' => $col(['Invoice created by']), 'type' => $col(['Item Type']), 'invoice' => $col(['Invoice No']),
            'item' => $col(['Item Name']), 'amount' => $col(['Sales(Inc. Tax)', 'Sales (Inc. Tax)']), 'pay' => $col(['Payment Type']),
            'status' => $col(['Invoice status']), 'closed' => $col(['Invoice Closed Date']), 'sale' => $col(['Sale Date']),
        ];
        foreach (['creator', 'invoice', 'amount'] as $need) {
            if ($c[$need] === null) {
                return back()->withErrors(['file' => 'This does not look like the Sales-Accrual export (missing the '.$need.' column).']);
            }
        }

        $agents = Employee::whereHas('tags', fn ($q) => $q->whereKey(Tag::idsFor(['Callgear'])))->get();
        $norm = fn ($s) => array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim((string) $s)))));
        $agentFor = function (string $name) use ($agents, $norm) {
            $w = $norm($name);
            foreach ($agents as $a) {
                $aw = $norm($a->full_name);
                [$short, $long] = count($w) <= count($aw) ? [$w, $aw] : [$aw, $w];
                if ($short && $short[0] === $long[0] && ! array_diff($short, $long)) {
                    return $a;
                }
            }

            return null;
        };

        $theirs = [];      // agent id => invoice => amount
        $dates = [];
        $creatorCache = [];
        while (($r = fgetcsv($fh)) !== false) {
            $creator = (string) ($r[$c['creator']] ?? '');
            $agent = $creatorCache[$creator] ??= ($agentFor($creator)?->id ?? 0);
            if (! $agent) {
                continue;
            }
            $line = (object) ['item_type' => $c['type'] !== null ? $r[$c['type']] : 'Service', 'status' => $c['status'] !== null ? $r[$c['status']] : 'Closed',
                'raw' => $c['pay'] !== null ? ['payment_type' => $r[$c['pay']]] : []];
            if (WidgetQuery::agentRevenueExclusion($line) !== null) {
                continue;
            }
            $d = $this->date($r[$c['closed'] ?? $c['sale'] ?? -1] ?? null);
            if ($d) {
                $dates[] = $d;
            }
            $inv = trim((string) $r[$c['invoice']]);
            $theirs[$agent][$inv] = ($theirs[$agent][$inv] ?? 0) + (float) str_replace(',', '', (string) $r[$c['amount']]);
        }
        fclose($fh);
        if (! $dates) {
            return back()->withErrors(['file' => 'No Callgear agent lines found in the file. Check that the agents are tagged "Callgear".']);
        }
        $from = min($dates)->startOfDay();
        $to = max($dates)->endOfDay();

        // Ours: every line our agents created, plus any line on the same invoices (to see who we credited instead).
        $ours = [];
        $why = [];
        $invoices = collect($theirs)->flatMap(fn ($x) => array_keys($x))->unique()->values();
        Sale::query()->where(fn ($q) => $q->whereIn('created_by_employee_id', $agents->pluck('id'))->orWhereIn('invoice_no', $invoices))
            ->where('sold_at', '>=', $from->subDays(WidgetQuery::CLOSE_LAG_DAYS))->where('sold_at', '<=', $to)
            ->chunkById(1000, function ($lines) use (&$ours, &$why, $from, $to, $agents) {
                foreach ($lines as $l) {
                    $inv = trim((string) $l->invoice_no);
                    $reason = ! WidgetQuery::closedWithin($l, $from, $to) ? 'closed '.WidgetQuery::closedAt($l)->toDateString().' (outside the file dates)'
                        : (! $agents->contains('id', $l->created_by_employee_id) ? 'credited to '.($l->created_by_employee_id ? 'another employee' : 'nobody (creator not matched)')
                        : WidgetQuery::agentRevenueExclusion($l));
                    if ($reason === null) {
                        $ours[$l->created_by_employee_id][$inv] = ($ours[$l->created_by_employee_id][$inv] ?? 0) + WidgetQuery::agentRevenueAmount($l)[0];
                    } else {
                        $why[$inv][] = $reason;
                    }
                }
            });

        $rows = $agents->map(function ($a) use ($theirs, $ours, $why) {
            $t = $theirs[$a->id] ?? [];
            $o = $ours[$a->id] ?? [];
            $diffs = collect(array_unique(array_merge(array_keys($t), array_keys($o))))
                ->map(fn ($inv) => ['invoice' => $inv, 'theirs' => round($t[$inv] ?? 0, 2), 'ours' => round($o[$inv] ?? 0, 2),
                    'note' => isset($o[$inv]) || isset($why[$inv]) ? implode('; ', array_unique($why[$inv] ?? [])) : 'not in our data (not synced)'])
                ->filter(fn ($d) => abs($d['theirs'] - $d['ours']) >= 0.01)->sortByDesc(fn ($d) => abs($d['theirs'] - $d['ours']))->values();

            return ['agent' => $a->full_name, 'theirs' => round(array_sum($t), 2), 'ours' => round(array_sum($o), 2), 'diffs' => $diffs->take(40)->all(), 'diff_count' => $diffs->count()];
        })->filter(fn ($r) => $r['theirs'] || $r['ours'])->sortByDesc('theirs')->values();

        return view('admin.integrations.compare', ['result' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows,
            'column' => WidgetQuery::REVENUE_COLUMNS[WidgetQuery::revenueColumn()][0]]]);
    }

    private function date(?string $v): ?CarbonImmutable
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        foreach (['d/m/Y', 'd/m/Y H:i', 'd/m/Y H:i:s', 'Y-m-d', 'Y-m-d H:i:s', 'm/d/Y'] as $f) {
            $d = CarbonImmutable::createFromFormat('!'.$f, $v) ?: null;
            if ($d && $d->format($f) === $v) {
                return $d;
            }
        }

        return rescue(fn () => CarbonImmutable::parse($v), null, false);
    }
}
