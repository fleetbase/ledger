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
 * Unique constraint:
 *   (gateway_reference_id, type, event_type)
 *
 * Rationale: Stripe fires multiple events with the same gateway reference ID
 * (e.g. pi_xxx appears in both payment_intent.created AND payment_intent.succeeded).
 * Including event_type in the unique key ensures each distinct event type gets
 * its own row, preventing the duplicate-insert 500 errors.
 *
 * If the table already exists (created manually before this migration was added),
 * the migration will:
 *   1. Drop the old (gateway_reference_id, type) unique index if it exists.
 *   2. Add the new (gateway_reference_id, type, event_type) unique index.
 *   3. Add any missing columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ledger_gateway_transactions')) {
            Schema::create('ledger_gateway_transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('public_id', 64)->unique()->nullable();
                $table->string('_key', 64)->nullable()->index();

                $table->string('company_uuid', 191)->nullable()->index();
                $table->string('gateway_uuid', 191)->nullable()->index();
                $table->string('transaction_uuid', 191)->nullable()->index();

                // The gateway's own identifier for this event (e.g. pi_xxx, cs_xxx, ch_xxx)
                $table->string('gateway_reference_id', 191)->nullable()->index();

                // 'webhook_event' | 'purchase' | 'refund' | 'setup'
                $table->string('type', 64)->default('webhook_event');

                // Normalised event type (payment.succeeded, payment.failed, etc.)
                $table->string('event_type', 64)->nullable();

                $table->unsignedBigInteger('amount')->nullable();
                $table->char('currency', 3)->nullable();
                $table->string('status', 32)->nullable();
                $table->text('message')->nullable();
                $table->json('raw_response')->nullable();

                // Idempotency seal — set when the event has been fully processed
                $table->timestamp('processed_at')->nullable();

                $table->string('created_by_uuid', 191)->nullable();
                $table->string('updated_by_uuid', 191)->nullable();
                $table->timestamps();
                $table->softDeletes();

                // Unique per (reference, type, event_type) so multiple Stripe events
                // sharing the same pi_xxx reference ID can each be stored once.
                $table->unique(
                    ['gateway_reference_id', 'type', 'event_type'],
                    'unique_gateway_ref_type_event'
                );
            });
        } else {
            // Table already exists — fix the unique constraint and add missing columns.
            Schema::table('ledger_gateway_transactions', function (Blueprint $table) {
                // Drop the old narrow unique index if it exists
                try {
                    $table->dropUnique('unique_gateway_ref_type');
                } catch (\Throwable) {
                    // Index may not exist or may have a different name — ignore
                }

                // Add event_type column if missing
                if (!Schema::hasColumn('ledger_gateway_transactions', 'event_type')) {
                    $table->string('event_type', 64)->nullable()->after('type');
                }

                // Add the new wider unique index (idempotent — will fail silently if already exists)
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
    }

    public function down(): void
    {
        // We intentionally do not drop the table in down() to avoid data loss.
        // Only revert the unique index change.
        if (Schema::hasTable('ledger_gateway_transactions')) {
            Schema::table('ledger_gateway_transactions', function (Blueprint $table) {
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
    }
};
