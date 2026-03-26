<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the ledger_gateway_transactions table.
 *
 * This table is the audit log and idempotency store for all interactions
 * with payment gateways (purchases, refunds, webhook events).
 *
 * Note: The unique constraint on (gateway_reference_id, type) was the original
 * design. Migration 000024 later widens this to (gateway_reference_id, type,
 * event_type) to handle Stripe firing multiple events with the same reference ID.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ledger_gateway_transactions')) {
            return;
        }

        Schema::create('ledger_gateway_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('public_id', 191)->unique()->nullable();

            $table->char('company_uuid', 36)->nullable()->index();
            $table->char('gateway_uuid', 36)->nullable()->index();
            $table->char('transaction_uuid', 36)->nullable()->index();

            // The gateway's own identifier for this event (e.g. pi_xxx, cs_xxx, ch_xxx)
            $table->string('gateway_reference_id', 255)->nullable()->index();

            // 'webhook_event' | 'purchase' | 'refund' | 'setup'
            $table->string('type', 50)->notNull();

            // Normalised event type (e.g. payment.succeeded, payment.failed)
            $table->string('event_type', 100)->nullable();

            $table->unsignedBigInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();

            // 'pending' | 'succeeded' | 'failed' | 'refunded'
            $table->string('status', 50)->notNull()->default('pending');

            $table->text('message')->nullable();
            $table->json('raw_response')->nullable();

            // Idempotency seal — set when the event has been fully processed
            $table->timestamp('processed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Composite indexes
            $table->index(['company_uuid', 'status'], 'ledger_gateway_transactions_company_uuid_status_index');
            $table->index(['gateway_uuid', 'type', 'status'], 'ledger_gateway_transactions_gateway_uuid_type_status_index');

            // Original unique constraint — widened by migration 000024
            $table->unique(
                ['gateway_reference_id', 'type'],
                'unique_gateway_ref_type'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_gateway_transactions');
    }
};
