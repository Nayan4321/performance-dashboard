<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile', ['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'current_password' => 'nullable|required_with:password|current_password',
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'avatar' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'remove_avatar' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $user->fill(['name' => $data['name'], 'phone' => $data['phone'] ?? null]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        if ($request->hasFile('avatar') || $request->boolean('remove_avatar')) {
            if ($user->avatar_path) {
                File::delete(storage_path('app/'.$user->avatar_path));
            }
            $user->avatar_path = null;
            if ($request->hasFile('avatar')) {
                File::ensureDirectoryExists(storage_path('app/avatars'));
                $name = $user->id.'-'.now()->format('YmdHis').'.'.$request->file('avatar')->extension();
                $request->file('avatar')->move(storage_path('app/avatars'), $name);
                $user->avatar_path = 'avatars/'.$name;
            }
        }
        $user->save();

        return back()->with('status', 'Profile updated.');
    }

    /** Profile photos are visible to any signed-in user (shown in menus and lists). */
    public function avatar(User $user)
    {
        abort_unless($user->avatar_path && is_file($file = storage_path('app/'.$user->avatar_path)), 404);

        return response()->file($file, ['Cache-Control' => 'private, max-age=31536000', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function help()
    {
        return view('help');
    }
}
