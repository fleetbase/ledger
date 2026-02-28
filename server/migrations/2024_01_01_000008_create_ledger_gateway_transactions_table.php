<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the ledger_gateway_transactions table.
 *
 * This table serves as both an audit log and an idempotency guard for all
 * interactions with payment gateways. Every purchase, refund, and webhook
 * event is recorded here.
 *
 * The gateway_reference_id is the unique identifier from the gateway itself
 * (e.g., Stripe's pi_xxx or ch_xxx, QPay's invoice_id). The combination of
 * gateway_reference_id + type is used to prevent duplicate processing.
 *
 * Monetary amounts are stored as integers in the smallest currency unit
 * (e.g., cents for USD, tögrög for MNT) per the Fleetbase monetary standard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_gateway_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->string('public_id', 191)->nullable()->unique()->index();
            $table->char('company_uuid', 36)->nullable()->index();

            // Relations
            $table->char('gateway_uuid', 36)->nullable()->index();
            $table->char('transaction_uuid', 36)->nullable()->index();  // Links to core-api transactions

            // Gateway reference — the gateway's own unique ID for this event
            $table->string('gateway_reference_id')->nullable()->index();

            // Transaction classification
            $table->string('type', 50);                     // 'purchase', 'refund', 'webhook_event', 'setup_intent'
            $table->string('event_type', 100)->nullable();  // Normalized: 'payment.succeeded', 'refund.processed'

            // Monetary values — stored as integers (smallest currency unit)
            $table->unsignedBigInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();

            // Status
            $table->string('status', 50)->default('pending')->index();  // 'pending', 'succeeded', 'failed', 'refunded'
            $table->text('message')->nullable();

            // Full raw response from the gateway for debugging
            $table->json('raw_response')->nullable();

            // Idempotency timestamp — set when the event has been fully processed
            $table->timestamp('processed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Idempotency index — prevents duplicate processing of the same gateway event
            $table->unique(['gateway_reference_id', 'type'], 'unique_gateway_ref_type');

            // Compound indexes for common queries
            $table->index(['company_uuid', 'status']);
            $table->index(['gateway_uuid', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_gateway_transactions');
    }
};
