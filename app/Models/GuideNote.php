<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One sticky note on a CallGear guide page. */
class GuideNote extends Model
{
    protected $fillable = ['guide', 'title', 'important', 'body', 'position', 'updated_by'];

    /** @return array<int, string> */
    public function importantLines(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->important))));
    }

    public function html(): string
    {
        return trim((string) $this->body) === '' ? '' : Str::markdown(trim($this->body), ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /**
     * First-time import from resources/guides/{guide}.md: each "## " heading is a note,
     * lines starting with "!" are its Important banners. Skipped when the guide already has notes.
     */
    public static function importFile(string $guide): int
    {
        if (static::where('guide', $guide)->exists()) {
            return 0;
        }
        $raw = (string) @file_get_contents(resource_path("guides/$guide.md"));
        $n = 0;
        foreach (array_slice(preg_split('/^## /m', $raw), 1) as $chunk) {
            [$title, $body] = array_pad(explode("\n", $chunk, 2), 2, '');
            preg_match_all('/^! ?(.+)$/m', $body, $m);
            static::create([
                'guide' => $guide, 'title' => mb_substr(trim($title), 0, 200), 'position' => ++$n,
                'important' => implode("\n", array_map('trim', $m[1])) ?: null,
                'body' => trim(preg_replace('/^! ?.+\R?/m', '', $body)) ?: null,
            ]);
        }

        return $n;
    }
}
