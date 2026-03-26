<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix the unique constraint on ledger_gateway_transactions.
 *
 * The original constraint (gateway_reference_id, type) — created by migration
 * 000008 — is too narrow. Stripe fires multiple events (e.g. payment_intent.created
 * and payment_intent.succeeded) that share the same gateway_reference_id (pi_xxx)
 * and the same type ('webhook_event'). This causes a UniqueConstraintViolationException
 * on the second event, returning a 500 to Stripe.
 *
 * This migration:
 *   1. Drops the old (gateway_reference_id, type) unique index.
 *   2. Adds a new (gateway_reference_id, type, event_type) unique index so each
 *      distinct Stripe event type gets its own row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_gateway_transactions', function (Blueprint $table) {
            // Drop the old narrow unique index if it exists
            try {
                $table->dropUnique('unique_gateway_ref_type');
            } catch (\Throwable) {
                // Index may not exist (e.g. already dropped) — ignore
            }

            // Ensure event_type column exists (it should from migration 000008,
            // but guard in case of a partial install)
            if (!Schema::hasColumn('ledger_gateway_transactions', 'event_type')) {
                $table->string('event_type', 100)->nullable()->after('type');
            }

            // Add the new wider unique index
            try {
                $table->unique(
                    ['gateway_reference_id', 'type', 'event_type'],
                    'unique_gateway_ref_type_event'
                );
            } catch (\Throwable) {
                // Index already exists — ignore
            }
        });
    }

    public function down(): void
    {
        Schema::table('ledger_gateway_transactions', function (Blueprint $table) {
            // Revert to the original narrow unique index
            try {
                $table->dropUnique('unique_gateway_ref_type_event');
            } catch (\Throwable) {
            }
            try {
                $table->unique(
                    ['gateway_reference_id', 'type'],
                    'unique_gateway_ref_type'
                );
            } catch (\Throwable) {
            }
        });
    }
};
