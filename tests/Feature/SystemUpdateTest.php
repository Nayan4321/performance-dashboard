<?php

namespace Tests\Feature;

use App\Models\SystemUpdate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class SystemUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const EXISTING = 'resources/views/_update_test_existing.txt';
    private const ADDED = 'resources/views/_update_test_added.txt';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        file_put_contents(base_path(self::EXISTING), 'old');
    }

    protected function tearDown(): void
    {
        @unlink(base_path(self::EXISTING));
        @unlink(base_path(self::ADDED));
        parent::tearDown();
    }

    private function zip(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upd').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'update.zip', 'application/zip', null, true);
    }

    private function admin(): User
    {
        return User::role('super-admin')->first();
    }

    public function test_only_super_admin_can_open_it(): void
    {
        $u = User::create(['name' => 'M', 'email' => 'm@x.test', 'password' => 'password']);
        $u->assignRole('management');
        $this->actingAs($u)->get(route('admin.system-update.index'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.system-update.index'))->assertOk();
    }

    public function test_upload_review_apply_and_rollback(): void
    {
        $zip = $this->zip([
            'performance-dashboard/'.self::EXISTING => 'new',
            'performance-dashboard/'.self::ADDED => 'added',
            'performance-dashboard/update.json' => json_encode(['version' => '1.1', 'seeders' => ['AdminDashboardSeeder']]),
        ]);
        $this->actingAs($this->admin())->post(route('admin.system-update.upload'), ['zip' => $zip])->assertRedirect();
        $update = SystemUpdate::latest('id')->first();
        $this->assertSame('1.1', $update->version);
        $this->assertEqualsCanonicalizing([self::EXISTING, self::ADDED], $update->files);
        $this->assertSame('old', file_get_contents(base_path(self::EXISTING)), 'nothing written before confirm');

        $this->actingAs($this->admin())->post(route('admin.system-update.apply', $update))->assertSessionHasErrors('confirm');
        $this->actingAs($this->admin())->post(route('admin.system-update.apply', $update), ['confirm' => 1])->assertRedirect();
        $update->refresh();
        $this->assertSame('applied', $update->status, (string) $update->output);
        $this->assertSame('new', file_get_contents(base_path(self::EXISTING)));
        $this->assertSame('added', file_get_contents(base_path(self::ADDED)));

        $this->actingAs($this->admin())->post(route('admin.system-update.rollback', $update))->assertRedirect();
        $this->assertSame('old', file_get_contents(base_path(self::EXISTING)));
        $this->assertFileDoesNotExist(base_path(self::ADDED));
        $this->assertSame('rolled_back', $update->fresh()->status);
    }

    public function test_rejects_zip_slip_and_protected_paths(): void
    {
        foreach (['../evil.php' => 'x', '.env' => 'APP_KEY=', 'storage/logs/x.log' => 'x', 'bootstrap/cache/config.php' => 'x', '/etc/passwd' => 'x'] as $name => $c) {
            $this->actingAs($this->admin())->post(route('admin.system-update.upload'), ['zip' => $this->zip([$name => $c, 'README.md' => 'x'])])
                ->assertSessionHasErrors('zip');
        }
        $this->assertSame(0, SystemUpdate::count());
    }

    public function test_finish_install_runs_migrations_and_seeders(): void
    {
        \App\Models\Dashboard::where('name', 'Admin dashboard')->delete();
        $this->actingAs($this->admin())->post(route('admin.system-update.finish'), ['seeders' => 'AdminDashboardSeeder'])->assertSessionHasNoErrors();
        $this->assertTrue(\App\Models\Dashboard::where('name', 'Admin dashboard')->exists());
    }
}
