<?php

namespace App\Services;

use App\Enums\MemberRole;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\Plans;
use Illuminate\Support\Str;

/**
 * Staf platform juga pengguna biasa: setiap staf punya organisasi internal sendiri (owner)
 * sehingga bisa memakai console pelanggan (kirim email, domain, API key) seperti user lain.
 */
class StaffOrganization
{
    public static function ensure(User $user): Organization
    {
        $existing = Member::where('user_id', $user->id)->first();
        if ($existing) {
            return Organization::findOrFail($existing->organization_id);
        }

        // Plan tertinggi tanpa masa berlaku: org internal tidak ditagih dan tidak turun ke Free.
        $plans = Plans::all();
        $top = collect($plans)->sortByDesc('monthly_quota')->first() ?? ['key' => 'free', 'monthly_quota' => 3000, 'daily_limit' => 100];

        $org = Organization::create([
            'name' => 'Postivo Internal — '.$user->name,
            'slug' => 'internal-'.Str::slug($user->name).'-'.Str::lower(Str::random(5)),
            'plan' => $top['key'],
            'monthly_quota' => $top['monthly_quota'],
            'daily_limit' => $top['daily_limit'],
        ]);
        Member::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => MemberRole::OWNER]);

        return $org;
    }
}
