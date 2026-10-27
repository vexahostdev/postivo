<?php

namespace App\Http\Controllers\Admin;

use App\Services\AdminAudit;
use App\Services\PlatformSettings;
use App\Support\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandingController extends AdminController
{
    public function index()
    {
        return view('admin.branding.index', ['s' => PlatformSettings::all()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->need('settings.manage');
        $d = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:40'],
            'brand_tagline' => ['nullable', 'string', 'max:120'],
            'brand_footer' => ['nullable', 'string', 'max:160'],
            'brand_accent' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'favicon' => ['nullable', 'image', 'mimes:png,ico,webp', 'max:256'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $changes = [];
        foreach (['brand_name', 'brand_tagline', 'brand_footer'] as $k) {
            $this->change($k, trim((string) ($d[$k] ?? '')), $changes);
        }
        $this->change('brand_accent', strtoupper((string) ($d['brand_accent'] ?? '')), $changes);

        foreach (['logo' => 'brand_logo', 'favicon' => 'brand_favicon'] as $field => $key) {
            if ($request->boolean("reset_$field")) {
                $this->forgetUpload($key);
                $this->change($key, '', $changes);
            } elseif ($request->hasFile($field)) {
                $this->forgetUpload($key);
                $path = $request->file($field)->storeAs('brand', $field.'-'.now()->timestamp.'.'.$request->file($field)->extension(), 'public');
                $this->change($key, $path, $changes);
            }
        }

        AdminAudit::log('branding.update', 'settings', null, null, $d['reason'], $changes);

        return back()->with('status', 'Branding disimpan. Perubahan tampil ≤ 60 detik di semua halaman.');
    }

    private function change(string $key, string $value, array &$changes): void
    {
        $before = (string) (PlatformSettings::all()[$key] ?? '');
        if ($before !== $value) {
            $changes[$key] = ['from' => $before, 'to' => $value];
            PlatformSettings::set($key, $value);
        }
    }

    private function forgetUpload(string $key): void
    {
        $old = (string) PlatformSettings::get($key, '');
        if ($old !== '' && ! str_contains($old, '..')) {
            Storage::disk('public')->delete($old);
        }
    }
}
