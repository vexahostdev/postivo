<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('domain_id')->constrained();
            $t->uuid('api_key_id')->nullable();
            $t->string('source', 8);
            $t->string('from_address', 191);
            $t->string('from_name', 100)->nullable();
            $t->string('reply_to', 191)->nullable();
            $t->string('subject', 255);
            $t->mediumText('html_body')->nullable();
            $t->mediumText('text_body')->nullable();
            $t->json('headers')->nullable();
            $t->json('tags')->nullable();
            $t->json('metadata')->nullable();
            $t->boolean('track_opens')->default(false);
            $t->boolean('track_clicks')->default(false);
            $t->string('idempotency_key', 191)->nullable();
            $t->char('request_hash', 64)->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('canceled_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'idempotency_key']);
            $t->index(['organization_id', 'created_at']);
            $t->index(['domain_id', 'created_at']);
        });

        Schema::create('email_attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('email_id')->constrained()->cascadeOnDelete();
            $t->string('filename', 255);
            $t->string('content_type', 127);
            $t->unsignedInteger('size_bytes');
            $t->string('disposition', 10)->default('attachment');
            $t->string('content_id', 191)->nullable();
            $t->string('storage_path', 255);
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('email_dispatches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('email_id')->constrained()->cascadeOnDelete();
            $t->uuid('organization_id');
            $t->uuid('domain_id');
            $t->string('recipient', 191);
            $t->char('recipient_hash', 64);
            $t->string('recipient_type', 4)->default('to');
            $t->string('status', 12)->default('QUEUED');
            $t->unsignedTinyInteger('status_rank')->default(0);
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('relay', 10)->nullable();
            $t->string('message_id', 255)->nullable()->index();
            $t->string('smtp_response', 512)->nullable();
            $t->string('bounce_type', 20)->nullable();
            $t->char('open_token', 22)->unique();
            $t->char('unsubscribe_token', 22)->unique();
            $t->string('raw_mime_path', 255)->nullable();
            $t->timestamp('queued_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('first_opened_at')->nullable();
            $t->timestamp('first_clicked_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status', 'created_at']);
            $t->index(['organization_id', 'recipient_hash']);
            $t->index(['status', 'next_attempt_at']);
        });

        Schema::create('email_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->uuid('organization_id');
            $t->uuid('email_id')->index();
            $t->uuid('dispatch_id');
            $t->string('type', 16);
            $t->dateTime('occurred_at', 3);
            $t->json('data')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->boolean('is_bot')->default(false);
            $t->timestamp('created_at')->nullable();
            $t->index(['dispatch_id', 'type']);
            $t->index(['organization_id', 'occurred_at']);
            $t->index(['organization_id', 'type', 'occurred_at']);
        });

        Schema::create('tracking_links', function (Blueprint $t) {
            $t->id();
            $t->char('token', 22)->unique();
            $t->uuid('dispatch_id')->index();
            $t->uuid('email_id');
            $t->text('url');
            $t->unsignedInteger('click_count')->default(0);
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('suppressions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('email', 191);
            $t->char('email_hash', 64);
            $t->string('reason', 20);
            $t->uuid('source_dispatch_id')->nullable();
            $t->string('note', 255)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['organization_id', 'email_hash']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('url', 2048);
            $t->text('secret');
            $t->json('events');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestamp('disabled_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['organization_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->uuid('endpoint_id');
            $t->unsignedBigInteger('event_id');
            $t->uuid('event_public_id');
            $t->unsignedTinyInteger('attempt')->default(0);
            $t->string('status', 10)->default('PENDING');
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('response_snippet', 500)->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamps();
            $t->index(['endpoint_id', 'created_at']);
            $t->index(['status', 'next_attempt_at']);
        });

        Schema::create('unmatched_relay_events', function (Blueprint $t) {
            $t->id();
            $t->string('relay', 10);
            $t->json('payload');
            $t->string('reason', 50);
            $t->timestamp('created_at')->nullable()->index();
        });

        Schema::create('usage_counters', function (Blueprint $t) {
            $t->uuid('organization_id');
            $t->char('period', 7);
            $t->unsignedInteger('sent')->default(0);
            $t->unsignedInteger('delivered')->default(0);
            $t->unsignedInteger('bounced')->default(0);
            $t->unsignedInteger('complained')->default(0);
            $t->timestamp('updated_at')->nullable();
            $t->primary(['organization_id', 'period']);
        });
    }

    public function down(): void
    {
        foreach (['usage_counters', 'unmatched_relay_events', 'webhook_deliveries', 'webhook_endpoints', 'suppressions', 'tracking_links', 'email_events', 'email_dispatches', 'email_attachments', 'emails'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
