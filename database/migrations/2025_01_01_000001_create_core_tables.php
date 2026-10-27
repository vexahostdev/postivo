<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('email', 191)->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('user_id', 36)->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        Schema::create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('slug', 80)->unique();
            $t->string('plan', 30)->default('free');
            $t->unsignedInteger('monthly_quota')->default(3000);
            $t->unsignedInteger('daily_limit')->nullable()->default(100);
            $t->string('status', 20)->default('ACTIVE');
            $t->string('suspended_reason')->nullable();
            $t->timestamps();
        });

        Schema::create('organization_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 20);
            $t->timestamps();
            $t->unique(['organization_id', 'user_id']);
        });

        Schema::create('organization_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('email', 191);
            $t->string('role', 20);
            $t->char('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('api_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('prefix', 16);
            $t->char('key_hash', 64)->unique();
            $t->string('environment', 8)->default('live');
            $t->json('scopes');
            $t->json('domain_ids')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->string('last_used_ip', 45)->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'revoked_at']);
        });

        Schema::create('domains', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('domain_name', 191)->unique();
            $t->string('status', 20)->default('PENDING')->index();
            $t->string('dkim_selector', 50)->default('postivo');
            $t->text('dkim_private_key');
            $t->text('dkim_public_key');
            $t->string('return_path_host', 191);
            $t->string('tracking_host', 191)->nullable();
            $t->boolean('track_opens_default')->default(false);
            $t->boolean('track_clicks_default')->default(false);
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('last_audit_at')->nullable();
            $t->timestamp('audit_started_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('dns_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('domain_id')->constrained()->cascadeOnDelete();
            $t->string('purpose', 20);
            $t->string('type', 10);
            $t->string('name', 255);
            $t->text('value');
            $t->boolean('is_required')->default(true);
            $t->boolean('is_verified')->default(false);
            $t->timestamp('last_checked_at')->nullable();
            $t->text('last_seen_value')->nullable();
            $t->timestamps();
            $t->unique(['domain_id', 'purpose']);
        });
    }

    public function down(): void
    {
        foreach (['dns_records', 'domains', 'api_keys', 'organization_invitations', 'organization_members', 'organizations', 'sessions', 'password_reset_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
