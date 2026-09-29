<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemUpdate;
use App\Services\System\Updater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Super admin only: upload an update zip, review it, apply it, roll it back. */
class SystemUpdateController extends Controller
{
    public function __construct(private Updater $updater) {}

    public function index()
    {
        $ready = Schema::hasTable('system_updates');

        return view('admin.system-update.index', [
            'ready' => $ready,
            'updates' => $ready ? SystemUpdate::with('user')->latest('id')->limit(20)->get() : collect(),
            'maxUpload' => ini_get('upload_max_filesize'),
        ]);
    }

    public function upload(Request $request)
    {
        $request->validate(['zip' => 'required|file|mimes:zip|max:204800']);
        try {
            $update = $this->updater->upload($request->file('zip'), $request->user());
        } catch (Throwable $e) {
            return back()->withErrors(['zip' => $e->getMessage()]);
        }

        return redirect()->route('admin.system-update.show', $update);
    }

    /** Review screen: list every file the zip will write before confirming. */
    public function show(SystemUpdate $update)
    {
        return view('admin.system-update.show', ['update' => $update]);
    }

    public function apply(Request $request, SystemUpdate $update)
    {
        $request->validate(['confirm' => 'accepted']);
        try {
            $update = $this->updater->apply($update);
        } catch (Throwable $e) {
            return back()->withErrors(['apply' => $e->getMessage()]);
        }

        return redirect()->route('admin.system-update.show', $update)
            ->with('status', $update->status === 'applied' ? 'Update installed.' : 'Update failed and was undone. See the log below.');
    }

    public function rollback(SystemUpdate $update)
    {
        try {
            $this->updater->rollback($update);
        } catch (Throwable $e) {
            return back()->withErrors(['rollback' => $e->getMessage()]);
        }

        return back()->with('status', 'Files restored to how they were before this update. Database changes are kept.');
    }

    /** After copying files by hand (File Manager), run migrations + seeders + cache clear from here. */
    public function finish(Request $request)
    {
        $seeders = array_filter(array_map('trim', explode(',', (string) $request->input('seeders'))));
        try {
            $output = $this->updater->finish($seeders);
        } catch (Throwable $e) {
            return back()->withErrors(['finish' => $e->getMessage()]);
        }

        return back()->with('status', 'Database updated and caches cleared.')->with('finish_output', $output);
    }
}
