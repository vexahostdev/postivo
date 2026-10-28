<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('platform_role', 20)->nullable()->after('password')->index();
            $t->timestamp('disabled_at')->nullable()->after('platform_role');
            $t->text('two_factor_secret')->nullable()->after('disabled_at');
            $t->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
        });

        Schema::table('organizations', function (Blueprint $t) {
            $t->text('internal_notes')->nullable()->after('suspended_reason');
            $t->timestamp('suspended_at')->nullable()->after('internal_notes');
        });

        Schema::create('admin_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('admin_id')->nullable()->index();   // null = sistem (mis. abuse-scan)
            $t->string('action', 60)->index();
            $t->string('target_type', 40)->nullable();
            $t->string('target_id', 64)->nullable();
            $t->uuid('organization_id')->nullable()->index();
            $t->string('reason', 500)->nullable();
            $t->json('meta')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('plans', function (Blueprint $t) {
            $t->string('key', 30)->primary();
            $t->string('name', 60);
            $t->unsignedBigInteger('price')->default(0);
            $t->unsignedInteger('monthly_quota');
            $t->unsignedInteger('daily_limit')->nullable();
            $t->json('features');
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('blocked_recipients', function (Blueprint $t) {
            $t->id();
            $t->string('pattern', 191)->unique();  // "a@b.com" atau "@domain.com"
            $t->string('reason', 255)->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->json('value')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['platform_settings', 'blocked_recipients', 'plans', 'admin_audit_logs'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['internal_notes', 'suspended_at']));
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['platform_role']);
            $t->dropColumn(['platform_role', 'disabled_at', 'two_factor_secret', 'two_factor_confirmed_at']);
        });
    }
};
