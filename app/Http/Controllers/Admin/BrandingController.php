<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Branding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/** Super admin: app name, login tagline and custom logos. */
class BrandingController extends Controller
{
    public function index()
    {
        return view('admin.branding', [
            'name' => Setting::get('brand_name'),
            'tagline' => Setting::get('brand_tagline'),
        ]);
    }

    public function update(Request $request)
    {
        $rules = ['brand_name' => 'nullable|string|max:60', 'brand_tagline' => 'nullable|string|max:120'];
        foreach (array_keys(Branding::LOGOS) as $kind) {
            $rules[$kind] = 'nullable|file|max:2048|mimes:png,jpg,jpeg,svg,webp,ico';
            $rules['remove_'.$kind] = 'nullable|boolean';
        }
        $data = $request->validate($rules);

        Setting::put('brand_name', $data['brand_name'] ?? null);
        Setting::put('brand_tagline', $data['brand_tagline'] ?? null);

        foreach (array_keys(Branding::LOGOS) as $kind) {
            $old = Setting::get('brand_'.$kind);
            if ($request->hasFile($kind)) {
                $file = $request->file($kind);
                $dir = storage_path('app/branding');
                File::ensureDirectoryExists($dir);
                $name = $kind.'-'.now()->format('YmdHis').'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension());
                $file->move($dir, $name);
                Setting::put('brand_'.$kind, 'branding/'.$name);
            } elseif ($request->boolean('remove_'.$kind)) {
                Setting::put('brand_'.$kind, null);
            } else {
                continue;
            }
            if ($old && $old !== Setting::get('brand_'.$kind)) {
                File::delete(storage_path('app/'.$old));
            }
        }

        return back()->with('status', 'Branding saved.');
    }

    /** Public: logos are needed on the login page. */
    public function asset(string $kind)
    {
        abort_unless(array_key_exists($kind, Branding::LOGOS), 404);
        $path = Branding::path($kind);
        abort_unless($path, 404);

        return response()->file(storage_path('app/'.$path), [
            'Cache-Control' => 'public, max-age=31536000',
            'Content-Type' => str_ends_with($path, '.svg') ? 'image/svg+xml' : (File::mimeType(storage_path('app/'.$path)) ?: 'image/png'),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }
}
