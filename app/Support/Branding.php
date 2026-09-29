<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Name and logos shown in the sidebar, login page and browser tab.
 * Uploaded logos live in storage/app/branding (never touched by updates)
 * and are served through the branding.asset route.
 */
class Branding
{
    /** kind => [label, help] */
    public const LOGOS = [
        'logo' => ['Main logo', 'Wide logo for the sidebar and login page (PNG/SVG/JPG, transparent background works best).'],
        'logo_dark' => ['Logo for dark mode', 'Optional light-coloured version shown when dark mode is on.'],
        'logo_small' => ['Small logo / icon', 'Square icon shown when the sidebar is collapsed and as the browser tab icon.'],
    ];

    public static function name(): string
    {
        return Setting::get('brand_name') ?: config('app.name');
    }

    public static function tagline(): string
    {
        return Setting::get('brand_tagline') ?: 'Sign in to continue to '.static::name().'.';
    }

    public static function path(string $kind): ?string
    {
        $path = Setting::get('brand_'.$kind);

        return $path && is_file(storage_path('app/'.$path)) ? $path : null;
    }

    public static function url(string $kind): ?string
    {
        $path = static::path($kind);

        return $path ? route('branding.asset', ['kind' => $kind, 'v' => substr(md5($path), 0, 8)]) : null;
    }

    /** Wide logo for light backgrounds, falling back to nothing (the name is shown instead). */
    public static function logo(): ?string
    {
        return static::url('logo');
    }

    public static function logoDark(): ?string
    {
        return static::url('logo_dark') ?? static::logo();
    }

    public static function logoSmall(): ?string
    {
        return static::url('logo_small') ?? static::logo();
    }
}
