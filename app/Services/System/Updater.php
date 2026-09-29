<?php

namespace App\Services\System;

use App\Models\SystemUpdate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Installs an update zip from the browser (no SSH):
 *  1. inspect: every path is checked (no "..", no absolute paths, nothing under
 *     .env / storage / bootstrap/cache / .git) before anything is written;
 *  2. apply: files about to be replaced are copied to storage/app/update-backups/{id},
 *     the zip is extracted over the app, then migrations, optional seeders and
 *     cache clearing run;
 *  3. rollback: restores the backup and removes files the update added.
 *
 * A zip may contain an optional update.json: {"version": "...", "seeders": ["AdminDashboardSeeder"]}.
 * If every entry sits under one top-level folder (e.g. "performance-dashboard/"), that folder is stripped.
 */
class Updater
{
    /** Paths an update may never touch. */
    public const PROTECTED = ['.env', 'storage/', 'bootstrap/cache/', '.git/', 'public/storage'];

    public const MANIFEST = 'update.json';

    public function upload(UploadedFile $file, User $user): SystemUpdate
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is not enabled on this server.');
        }

        $dir = storage_path('app/updates');
        File::ensureDirectoryExists($dir);
        $name = now()->format('Ymd-His').'-'.Str::random(6).'.zip';
        $file->move($dir, $name);
        $path = $dir.'/'.$name;

        try {
            [$files, $manifest] = $this->inspect($path);
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return SystemUpdate::create([
            'user_id' => $user->id,
            'filename' => $file->getClientOriginalName(),
            'version' => $manifest['version'] ?? null,
            'status' => 'uploaded',
            'files' => array_values($files),
            'zip_path' => $path,
        ]);
    }

    /**
     * @return array{0: array<string,string>, 1: array} map of zip entry => relative target path, and the manifest
     */
    public function inspect(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('This is not a valid zip file.');
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $manifestRaw = null;

        // Strip a single shared top-level folder, if there is one.
        $prefix = '';
        $tops = array_unique(array_map(fn ($n) => explode('/', $n)[0], $names));
        if (count($tops) === 1 && collect($names)->every(fn ($n) => str_contains($n, '/'))) {
            $prefix = $tops[0].'/';
        }

        $files = [];
        foreach ($names as $name) {
            if (str_ends_with($name, '/')) {
                continue; // directory entry
            }
            $rel = substr($name, strlen($prefix));
            if ($rel === self::MANIFEST) {
                $manifestRaw = $zip->getFromName($name);

                continue;
            }
            $this->assertSafe($rel);
            $files[$name] = $rel;
        }
        $zip->close();

        if (! $files) {
            throw new RuntimeException('The zip does not contain any files.');
        }

        $manifest = $manifestRaw ? (json_decode($manifestRaw, true) ?: []) : [];

        return [$files, $manifest];
    }

    public function assertSafe(string $rel): void
    {
        $bad = $rel === ''
            || str_contains($rel, "\0")
            || str_contains($rel, '\\')
            || str_starts_with($rel, '/')
            || preg_match('/^[a-zA-Z]:/', $rel)
            || in_array('..', explode('/', $rel), true);
        if ($bad) {
            throw new RuntimeException("Unsafe path in zip: {$rel}");
        }
        foreach (self::PROTECTED as $p) {
            if ($rel === rtrim($p, '/') || str_starts_with($rel, $p) || ($p === '.env' && str_starts_with($rel, '.env'))) {
                throw new RuntimeException("The zip tries to change a protected file ({$rel}). Updates never touch .env, storage or caches.");
            }
        }
    }

    public function apply(SystemUpdate $update): SystemUpdate
    {
        if ($update->status !== 'uploaded' || ! is_file((string) $update->zip_path)) {
            throw new RuntimeException('This update has already been applied or its file is missing.');
        }
        @set_time_limit(600);

        [$files, $manifest] = $this->inspect($update->zip_path); // re-check before writing
        $backup = storage_path('app/update-backups/'.$update->id);
        File::ensureDirectoryExists($backup);

        $new = [];
        foreach ($files as $rel) {
            $target = base_path($rel);
            if (is_file($target)) {
                File::ensureDirectoryExists(dirname($backup.'/'.$rel));
                File::copy($target, $backup.'/'.$rel);
            } else {
                $new[] = $rel;
            }
        }
        $update->update(['backup_path' => $backup, 'new_files' => $new]);

        $log = [];
        try {
            $zip = new ZipArchive;
            $zip->open($update->zip_path);
            foreach ($files as $entry => $rel) {
                $target = base_path($rel);
                File::ensureDirectoryExists(dirname($target));
                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    throw new RuntimeException("Could not read {$entry} from the zip.");
                }
                file_put_contents($target, $stream);
                fclose($stream);
            }
            $zip->close();
            $log[] = count($files).' file(s) written, '.count($new).' of them new.';
            $this->resetOpcache();

            $log[] = $this->finish($manifest['seeders'] ?? []);
            $update->update(['status' => 'applied', 'applied_at' => now(), 'output' => implode("\n", $log)]);
        } catch (Throwable $e) {
            report($e);
            $log[] = 'FAILED: '.$e->getMessage();
            $log[] = $this->restore($update) ? 'Previous files restored from backup.' : 'Could not restore automatically; use Roll back.';
            $update->update(['status' => 'failed', 'output' => implode("\n", $log)]);
        }

        return $update->fresh();
    }

    /** Migrations, optional seeders and cache refresh. Also used by the "Finish install" button after a manual upload. */
    public function finish(array $seeders = []): string
    {
        $out = [];
        Artisan::call('migrate', ['--force' => true]);
        $out[] = trim(Artisan::output());

        foreach ($seeders as $seeder) {
            $class = 'Database\\Seeders\\'.class_basename((string) $seeder);
            if (class_exists($class)) {
                Artisan::call('db:seed', ['--class' => $class, '--force' => true]);
                $out[] = "Seeded {$seeder}.";
            }
        }

        Artisan::call('optimize:clear');
        $out[] = trim(Artisan::output());
        $this->resetOpcache();

        return trim(implode("\n", array_filter($out)));
    }

    public function rollback(SystemUpdate $update): SystemUpdate
    {
        if (! in_array($update->status, ['applied', 'failed'], true)) {
            throw new RuntimeException('Only an applied update can be rolled back.');
        }
        $this->restore($update);
        Artisan::call('optimize:clear');
        $this->resetOpcache();
        $update->update(['status' => 'rolled_back', 'output' => trim($update->output."\nRolled back at ".now())]);

        return $update;
    }

    protected function restore(SystemUpdate $update): bool
    {
        if (! $update->backup_path || ! is_dir($update->backup_path)) {
            return false;
        }
        foreach (File::allFiles($update->backup_path, true) as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            File::ensureDirectoryExists(dirname(base_path($rel)));
            File::copy($file->getPathname(), base_path($rel));
        }
        foreach ((array) $update->new_files as $rel) {
            $this->assertSafe($rel);
            @unlink(base_path($rel));
        }
        $this->resetOpcache();

        return true;
    }

    protected function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}
