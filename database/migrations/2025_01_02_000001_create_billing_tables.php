<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->timestamp('plan_expires_at')->nullable()->after('daily_limit');
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $t->uuid('user_id')->nullable();
            $t->string('plan', 30);
            $t->unsignedInteger('months')->default(1);
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('IDR');
            $t->string('status', 12)->default('PENDING'); // PENDING|PAID|EXPIRED|FAILED
            $t->string('external_id', 100)->unique();      // PSV-<ulid>
            $t->string('xendit_invoice_id', 64)->nullable()->index();
            $t->text('invoice_url')->nullable();
            $t->string('payment_method', 40)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->json('raw_payload')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'created_at']);
        });

        // Jejak forensik + idempotensi webhook.
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 20);
            $t->string('event_id', 100);
            $t->string('event_type', 30)->nullable();
            $t->string('processing_status', 12)->default('received'); // received|processed|ignored|failed
            $t->text('processing_error')->nullable();
            $t->string('ip', 45)->nullable();
            $t->json('payload');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'event_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('orders');
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('plan_expires_at'));
    }
};
