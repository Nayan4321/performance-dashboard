<?php

namespace App\Services\Zenoti;

use App\Models\Employee;

/**
 * Finds our employee for a Zenoti row: by Zenoti id, then employee code, then full name.
 * The sales report does not always carry the employee id, only a code or a name.
 */
class EmployeeMatcher
{
    private ?array $byCode = null;

    private ?array $byName = null;

    private array $byId = [];

    public function match(array $data): ?int
    {
        if ($id = $data['_employee'] ?? null) {
            $found = $this->byId[(string) $id] ??= Employee::where('zenoti_id', (string) $id)->value('id');
            if ($found) {
                return $found;
            }
        }
        if ($code = $data['_employee_code'] ?? null) {
            $this->load();
            if ($found = $this->byCode[mb_strtolower(trim((string) $code))] ?? null) {
                return $found;
            }
        }
        if ($name = $data['_employee_name'] ?? null) {
            $this->load();

            return $this->byName[self::key($name)] ?? null;
        }

        return null;
    }

    public static function key(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    private function load(): void
    {
        if ($this->byName !== null) {
            return;
        }
        $this->byCode = $this->byName = [];
        Employee::query()->get(['id', 'first_name', 'last_name', 'raw'])->each(function (Employee $e) {
            $this->byName[self::key($e->full_name)] ??= $e->id;
            $code = ZenotiMapper::pick((array) $e->raw, ['code', 'employee_code', 'personal_info.code']);
            if ($code) {
                $this->byCode[mb_strtolower(trim((string) $code))] ??= $e->id;
            }
        });
    }
}
