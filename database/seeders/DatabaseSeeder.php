<?php

namespace Database\Seeders;

use App\Enums\MemberRole;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyService;
use App\Services\DnsAuditorService;
use App\Services\DomainService;
use Illuminate\Database\Seeder;

/** Data demo lokal: demo@postivo.test / password-demo-123 + domain aktif + dua API key. */
class DatabaseSeeder extends Seeder
{
    public function run(ApiKeyService $keys, DomainService $domains, DnsAuditorService $auditor): void
    {
        $user = User::firstOrCreate(['email' => 'demo@postivo.test'], ['name' => 'Demo User', 'password' => 'password-demo-123']);
        $user->forceFill(['email_verified_at' => now()])->save();

        // Staf platform demo (hanya non-production). 2FA tidak wajib di development.
        if (! app()->isProduction()) {
            foreach (['superadmin', 'support', 'finance'] as $role) {
                User::firstOrCreate(['email' => "{$role}@postivo.test"], ['name' => ucfirst($role).' Demo', 'password' => 'password-demo-123'])
                    ->forceFill(['platform_role' => $role, 'email_verified_at' => now()])->save();
            }
        }
        $org = Organization::firstOrCreate(['slug' => 'demo-org'], ['name' => 'Demo Org']);
        Member::firstOrCreate(['organization_id' => $org->id, 'user_id' => $user->id], ['role' => MemberRole::OWNER]);

        if ($org->domains()->doesntExist()) {
            $domain = $domains->create($org, 'mail.demo.test');
            config(['postivo.dns.assume_ok' => true]);
            $auditor->audit($domain->load('records'));
            [, $live] = $keys->create($org, 'Demo live', 'live', ['emails:send', 'emails:read', 'domains:manage', 'suppressions:manage', 'webhooks:manage'], null, $user);
            [, $test] = $keys->create($org, 'Demo sandbox', 'test', ['emails:send', 'emails:read'], null, $user);
            $this->command?->info("Live key    : {$live}");
            $this->command?->info("Sandbox key : {$test}");
        }
    }
}
