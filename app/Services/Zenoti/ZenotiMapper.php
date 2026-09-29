<?php

namespace App\Services\Zenoti;

use Carbon\Carbon;

/**
 * Converts Zenoti API / webhook objects into our column layout. Field names
 * are read defensively (several fallbacks) because list, detail and webhook
 * payloads name things slightly differently.
 */
class ZenotiMapper
{
    public static function pick(array $row, array $paths, $default = null)
    {
        foreach ($paths as $p) {
            $v = data_get($row, $p);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        return $default;
    }

    public static function date($value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Last resort: the first field whose name mentions "date" and holds a real date (checks one level deep). */
    public static function anyDate(array $r): ?Carbon
    {
        foreach ([$r, ...array_filter($r, 'is_array')] as $level) {
            foreach ($level as $k => $v) {
                if (is_string($k) && is_string($v) && str_contains(strtolower($k), 'date') && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) && ($d = self::date($v))) {
                    return $d;
                }
            }
        }

        return null;
    }

    public static function center(array $r): array
    {
        return [
            'zenoti_center_id' => (string) self::pick($r, ['id', 'center_id']),
            'name' => self::pick($r, ['name', 'display_name'], 'Unnamed center'),
            'code' => self::pick($r, ['code']),
            'address' => self::pick($r, ['address.address_info.address_1', 'address.address1', 'address_1']),
            'phone' => self::pick($r, ['contact_info.phone_1.number', 'phone.number', 'phone']),
        ];
    }

    public static function employee(array $r): array
    {
        return [
            'zenoti_id' => (string) self::pick($r, ['id', 'employee_id']),
            'first_name' => self::pick($r, ['personal_info.first_name', 'first_name'], 'Unknown'),
            'last_name' => self::pick($r, ['personal_info.last_name', 'last_name']),
            'email' => self::pick($r, ['personal_info.email', 'email']),
            'phone' => self::pick($r, ['personal_info.mobile_phone.number', 'mobile_phone.number', 'mobile.number', 'mobile_phone.display_number', 'mobile.display_number', 'phone']),
            'job_title' => self::pick($r, ['job_info.name', 'job.name', 'job_name', 'designation']),
        ];
    }

    public static function guest(array $r): array
    {
        return [
            'zenoti_id' => (string) self::pick($r, ['id', 'guest_id']),
            'first_name' => self::pick($r, ['personal_info.first_name', 'first_name']),
            'last_name' => self::pick($r, ['personal_info.last_name', 'last_name']),
            'email' => self::pick($r, ['personal_info.email', 'email']),
            'phone' => self::pick($r, ['personal_info.mobile_phone.number', 'mobile_phone.number', 'mobile.number', 'mobile_phone.display_number', 'mobile.display_number', 'phone']),
            'gender' => self::pick($r, ['personal_info.gender', 'gender']),
            'registered_at' => self::date(self::pick($r, ['created_date', 'registration_date', 'created_on'])),
        ];
    }

    /**
     * Who made a change, when Zenoti includes it. Returns [zenoti employee id, name].
     * $kind: created | changed. Field names are guesses until confirmed with "Test a Zenoti call".
     */
    public static function actor(array $r, string $kind = 'changed'): array
    {
        $created = ['created_by', 'creation_user', 'booked_by', 'created_user', 'creator'];
        $changed = ['cancelled_by', 'canceled_by', 'last_updated_by', 'last_modified_by', 'modified_by', 'updated_by'];
        foreach ($kind === 'created' ? $created : array_merge($changed, $created) as $key) {
            $v = $r[$key] ?? $r[$key.'_id'] ?? null;
            if (is_array($v)) {
                $id = self::pick($v, ['id', 'user_id', 'employee_id']);
                $name = self::pick($v, ['name', 'full_name', 'display_name']) ?? trim((string) self::pick($v, ['first_name']).' '.(string) self::pick($v, ['last_name']));
                if ($id || $name) {
                    return [$id ? (string) $id : null, $name ?: null];
                }
            } elseif (filled($v)) {
                return [(string) $v, is_string($r[$key.'_name'] ?? null) ? $r[$key.'_name'] : null];
            }
        }

        return [null, null];
    }

    public static function appointment(array $r): array
    {
        return [
            'zenoti_id' => (string) self::pick($r, ['appointment_id', 'id']),
            'service_name' => self::pick($r, ['service.name', 'service_name']),
            'status' => self::appointmentStatus(self::pick($r, ['status', 'appointment_status'])),
            'raw_status' => (string) self::pick($r, ['status', 'appointment_status'], ''),
            'price' => self::money($r, ['price.final', 'price.sales', 'price.final_price', 'price.sale_price', 'price.amount', 'price.price',
                'service.price.final', 'service.price.sales', 'service.price', 'final_price', 'sale_price', 'service_price', 'amount', 'price']),
            'start_time' => self::date(self::pick($r, ['start_time', 'start_time_utc'])),
            'end_time' => self::date(self::pick($r, ['end_time', 'end_time_utc'])),
            'booked_at' => self::date(self::pick($r, ['creation_date', 'created_date', 'booked_on', 'creation_date_utc'])),
            '_employee' => self::pick($r, ['therapist.id', 'employee.id', 'therapist_id']),
            '_guest' => self::pick($r, ['guest.id', 'guest_id']),
            '_center' => self::pick($r, ['center_id', 'center.id']),
        ];
    }

    public static function sale(array $r): array
    {
        return [
            'zenoti_id' => self::saleKey($r),
            'invoice_no' => self::pick($r, ['invoice_no', 'invoice_number', 'invoice.invoice_number', 'invoice.invoice_no', 'receipt_no', 'receipt_number', 'invoice_id', 'invoice.id']),
            'item_type' => self::pick($r, ['item.type', 'item_type', 'type']),
            'category' => self::saleCategory(self::pick($r, ['item.type', 'item_type', 'type'])),
            'item_name' => self::pick($r, ['item.name', 'item_name', 'name']),
            'status' => (string) self::pick($r, ['status', 'invoice_status'], ''),
            'quantity' => (float) self::pick($r, ['quantity', 'qty'], 1),
            'gross_amount' => (float) self::pick($r, ['sale_price', 'gross_amount', 'price'], 0),
            'discount' => (float) self::pick($r, ['discount', 'discount_amount'], 0),
            'net_amount' => (float) self::pick($r, ['final_sale_price', 'net_amount', 'sales_ex_tax', 'sales_exc_tax', 'sales_excluding_tax', 'net_sales', 'sale_price_ex_tax', 'collected', 'total'], 0),
            'sold_at' => self::date(self::pick($r, ['sale_date', 'invoice_date', 'invoice.invoice_date', 'invoice_closed_date', 'closed_date', 'transaction_date', 'sold_on', 'created_date', 'date', 'invoice.created_date']))
                ?? self::anyDate($r),
            '_employee' => self::pick($r, ['employee.id', 'sold_by.id', 'sold_by_id', 'employee_id', 'therapist.id', 'therapist_id', 'serviced_by.id', 'provider.id', 'employees.0.id', 'therapists.0.id']),
            '_employee_code' => self::scalar(self::pick($r, ['employee.code', 'employee_code', 'sold_by.code', 'sold_by_code', 'therapist.code', 'therapist_code', 'employee.employee_code'])),
            '_employee_name' => self::personName($r, ['employee', 'sold_by', 'therapist', 'serviced_by', 'provider', 'employee_name', 'sold_by_name', 'therapist_name', 'serviced_by_name', 'employees.0', 'therapists.0']),
            '_guest' => self::pick($r, ['guest.id', 'guest_id']),
            // Who entered the invoice (the accrual report sends it); Callgear staff are credited this way.
            '_created_by' => self::scalar(self::pick($r, ['created_by_id', 'created_by.id'])),
            '_created_by_name' => self::personName($r, ['created_by']),
        ];
    }

    /**
     * One key per sales line. The sales report's "id" is not unique per line (it can repeat for the
     * same service), which made lines overwrite each other, so the key also uses invoice, item,
     * employee, date and amount. A real line id, when Zenoti sends one, is used as is.
     */
    public static function saleKey(array $r): string
    {
        $line = self::scalar(self::pick($r, ['invoice_item_id', 'invoice_item.id', 'sale_item_id', 'item_line_id', 'line_id']));
        if ($line) {
            return $line;
        }
        $parts = array_map(fn ($p) => self::pick($r, (array) $p), [
            'id', ['invoice_no', 'invoice_number', 'invoice.invoice_number', 'invoice_id', 'invoice.id', 'receipt_no'],
            ['item.id', 'item_id', 'item.name', 'item_name', 'name'], ['employee.id', 'employee_id', 'employee_name', 'employee.name', 'sold_by', 'therapist_id'],
            ['sale_date', 'invoice_date', 'invoice.invoice_date', 'date', 'created_date'], ['final_sale_price', 'net_amount', 'sale_price', 'price'], ['quantity', 'qty'],
        ]);

        return 'L:'.md5(json_encode($parts));
    }

    /** First path holding a number (objects such as {"currency_id":148,"sales":0} are skipped, not read as 1). */
    public static function money(array $r, array $paths): float
    {
        foreach ($paths as $p) {
            $v = data_get($r, $p);
            if (is_numeric($v) && (float) $v != 0.0) {
                return (float) $v;
            }
        }

        return 0.0;
    }

    /**
     * The fields a Zenoti row carries, for the "Fields Zenoti sends" check on Integrations.
     * Numbers, dates and statuses are shown; other text is masked so no personal data is kept.
     */
    public static function fieldMap(array $r, string $prefix = '', int $depth = 0): array
    {
        $out = [];
        foreach ($r as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            if (is_array($v)) {
                if ($depth < 3 && $v) {
                    $out += self::fieldMap(array_is_list($v) ? [$v[0]] : $v, array_is_list($v) ? $key.'[0]' : $key, $depth + 1);
                } else {
                    $out[$key] = $v ? 'list' : 'empty';
                }
                continue;
            }
            $out[$key] = match (true) {
                $v === null => 'null',
                is_bool($v) => $v ? 'true' : 'false',
                is_int($v) || is_float($v) => (string) $v,
                (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v) => (string) $v,
                (bool) preg_match('/(status|type|category|currency|code)$/i', (string) $k) && mb_strlen((string) $v) <= 40 => '"'.$v.'"',
                default => 'text('.mb_strlen((string) $v).')',
            };
        }

        return array_slice($out, 0, 150, true);
    }

    private static function scalar($v): ?string
    {
        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }

    /** A person's name from a plain string field or an object with name / first_name + last_name. */
    private static function personName(array $r, array $paths): ?string
    {
        foreach ($paths as $p) {
            $v = data_get($r, $p);
            if (is_array($v)) {
                $v = self::pick($v, ['name', 'full_name', 'display_name'])
                    ?? trim(self::pick($v, ['first_name', 'personal_info.first_name'], '').' '.self::pick($v, ['last_name', 'personal_info.last_name'], ''));
            }
            if (is_string($v) && trim($v) !== '' && ! preg_match('/^[0-9a-f-]{20,}$/i', trim($v))) {
                return trim($v);
            }
        }

        return null;
    }

    public static function lead(array $r): array
    {
        return [
            'external_id' => (string) self::pick($r, ['id', 'opportunity_id', 'lead_id']),
            'name' => self::pick($r, ['guest.name', 'name', 'title']),
            'phone' => self::pick($r, ['guest.mobile_phone.number', 'phone']),
            'email' => self::pick($r, ['guest.email', 'email']),
            'channel' => self::pick($r, ['source.name', 'source', 'channel']),
            'stage' => strtolower((string) self::pick($r, ['status', 'stage', 'opportunity_status'], 'new')),
            'value' => (float) self::pick($r, ['value', 'amount', 'expected_revenue'], 0),
            'lead_at' => self::date(self::pick($r, ['created_date', 'created_on'])),
            '_employee' => self::pick($r, ['owner.id', 'employee.id', 'assigned_to.id']),
        ];
    }

    /**
     * Group Zenoti appointment statuses the way the Zenoti Admin Dashboard does.
     * Zenoti sends either names or numeric codes; the numeric codes below are
     * the documented ones as we understand them — check `raw_status` if a
     * bucket looks wrong and adjust here.
     */
    public static function appointmentStatus($status): string
    {
        $s = strtolower(trim((string) $status));

        return match (true) {
            in_array($s, ['1', 'closed', 'serviced', 'completed', 'checkout', 'checked out'], true) => 'Serviced',
            in_array($s, ['2', 'checkin', 'check in', 'checked in', 'in progress', 'in-progress', 'inprogress'], true) => 'In-progress',
            in_array($s, ['11', 'noshow', 'no show', 'no-show'], true) => 'No-show',
            in_array($s, ['10', '-1', 'cancelled', 'canceled', 'deleted', 'void'], true) => 'Cancelled',
            default => 'Open & confirmed', // 0 new/open, 4 confirmed, awaiting ...
        };
    }

    public static function saleCategory($type): string
    {
        $t = strtolower(trim((string) $type));

        return match (true) {
            in_array($t, ['0', 'service', 'services'], true) => 'Service',
            in_array($t, ['2', 'product', 'products', 'retail'], true) => 'Product',
            in_array($t, ['3', 'package', 'packages', 'series package'], true) => 'Package',
            in_array($t, ['4', 'gift card', 'giftcard', 'gift cards', 'gift certificate'], true) => 'Gift card',
            in_array($t, ['1', 'membership', 'memberships'], true) => 'Membership',
            in_array($t, ['5', '6', 'prepaid card', 'prepaidcard', 'prepaid'], true) => 'Prepaid card',
            default => 'Other',
        };
    }

    public static function collection(array $r): array
    {
        return [
            'zenoti_id' => (string) self::pick($r, ['id', 'payment_id', 'collection_id'], md5(json_encode($r))),
            'invoice_no' => self::pick($r, ['invoice_no', 'invoice_number', 'invoice.invoice_number', 'invoice.invoice_no', 'receipt_no', 'receipt_number', 'invoice_id', 'invoice.id']),
            'payment_type' => self::pick($r, ['payment_type', 'payment_option.name', 'payment_mode', 'type']),
            'amount' => (float) self::pick($r, ['amount', 'collected', 'total_collection', 'payment_amount'], 0),
            'collected_at' => self::date(self::pick($r, ['payment_date', 'collection_date', 'invoice_date', 'date'])),
            '_employee' => self::pick($r, ['employee.id', 'employee_id']),
            '_guest' => self::pick($r, ['guest.id', 'guest_id']),
        ];
    }
}
