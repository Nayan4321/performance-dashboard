<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Free-form labels managers put on employees, used to filter lists and dashboards. */
class Tag extends Model
{
    protected $fillable = ['name', 'color'];

    public const COLORS = ['primary', 'success', 'info', 'warning', 'danger', 'secondary', 'purple', 'pink'];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class);
    }

    /** Tag ids for names typed by a user, creating the new ones. */
    public static function idsFor(array $names): array
    {
        return collect($names)->map(fn ($n) => trim(preg_replace('/\s+/', ' ', (string) $n)))
            ->filter(fn ($n) => $n !== '' && mb_strlen($n) <= 60)
            ->unique(fn ($n) => mb_strtolower($n))
            ->map(fn ($n) => (static::whereRaw('LOWER(name) = ?', [mb_strtolower($n)])->first()
                ?? static::create(['name' => $n, 'color' => self::COLORS[crc32(mb_strtolower($n)) % count(self::COLORS)]]))->id)
            ->values()->all();
    }

    /** Tags nobody uses any more are removed so the pickers stay short. */
    public static function prune(): void
    {
        static::doesntHave('employees')->delete();
    }
}
