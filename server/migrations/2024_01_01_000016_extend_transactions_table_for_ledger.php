<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the core `transactions` table with the additional columns required
 * by the Ledger extension.
 *
 * The core-api `transactions` table only has the minimal set of columns
 * (owner_uuid, customer_uuid, gateway, amount, currency, type, status).
 * The Ledger extension needs a richer model to support:
 *
 *   - direction (credit/debit) for double-entry bookkeeping
 *   - polymorphic payer, payee, initiator, context, and subject roles
 *   - extended monetary fields (fee, tax, net, balance_after, settlement)
 *   - payment method details
 *   - failure info, reporting fields, and traceability
 *
 * All new columns are nullable so existing rows are not affected and the
 * migration is safe to run on a live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // ── Direction ────────────────────────────────────────────────────
            // 'credit' = money coming in, 'debit' = money going out
            if (!Schema::hasColumn('transactions', 'direction')) {
                $table->string('direction', 16)->nullable()->default(null)
                    ->comment('credit or debit')
                    ->after('status');
            }

            // ── Extended monetary fields ─────────────────────────────────────
            if (!Schema::hasColumn('transactions', 'fee_amount')) {
                $table->unsignedBigInteger('fee_amount')->nullable()->after('amount');
            }
            if (!Schema::hasColumn('transactions', 'tax_amount')) {
                $table->unsignedBigInteger('tax_amount')->nullable()->after('fee_amount');
            }
            if (!Schema::hasColumn('transactions', 'net_amount')) {
                $table->bigInteger('net_amount')->nullable()->after('tax_amount');
            }
            if (!Schema::hasColumn('transactions', 'balance_after')) {
                $table->bigInteger('balance_after')->nullable()->after('net_amount');
            }
            if (!Schema::hasColumn('transactions', 'exchange_rate')) {
                $table->decimal('exchange_rate', 18, 8)->nullable()->after('balance_after');
            }
            if (!Schema::hasColumn('transactions', 'settled_currency')) {
                $table->char('settled_currency', 3)->nullable()->after('exchange_rate');
            }
            if (!Schema::hasColumn('transactions', 'settled_amount')) {
                $table->unsignedBigInteger('settled_amount')->nullable()->after('settled_currency');
            }

            // ── Polymorphic subject (the resource this transaction is about) ─
            if (!Schema::hasColumn('transactions', 'subject_uuid')) {
                $table->string('subject_uuid', 191)->nullable()->index()->after('gateway_uuid');
            }
            if (!Schema::hasColumn('transactions', 'subject_type')) {
                $table->string('subject_type', 191)->nullable()->after('subject_uuid');
            }

            // ── Polymorphic payer (who paid) ─────────────────────────────────
            if (!Schema::hasColumn('transactions', 'payer_uuid')) {
                $table->string('payer_uuid', 191)->nullable()->index()->after('subject_type');
            }
            if (!Schema::hasColumn('transactions', 'payer_type')) {
                $table->string('payer_type', 191)->nullable()->after('payer_uuid');
            }

            // ── Polymorphic payee (who received) ─────────────────────────────
            if (!Schema::hasColumn('transactions', 'payee_uuid')) {
                $table->string('payee_uuid', 191)->nullable()->index()->after('payer_type');
            }
            if (!Schema::hasColumn('transactions', 'payee_type')) {
                $table->string('payee_type', 191)->nullable()->after('payee_uuid');
            }

            // ── Polymorphic initiator (who triggered the transaction) ─────────
            if (!Schema::hasColumn('transactions', 'initiator_uuid')) {
                $table->string('initiator_uuid', 191)->nullable()->after('payee_type');
            }
            if (!Schema::hasColumn('transactions', 'initiator_type')) {
                $table->string('initiator_type', 191)->nullable()->after('initiator_uuid');
            }

            // ── Polymorphic context (related resource, e.g. Order, Invoice) ──
            if (!Schema::hasColumn('transactions', 'context_uuid')) {
                $table->string('context_uuid', 191)->nullable()->index()->after('initiator_type');
            }
            if (!Schema::hasColumn('transactions', 'context_type')) {
                $table->string('context_type', 191)->nullable()->after('context_uuid');
            }

            // ── Payment method details ────────────────────────────────────────
            if (!Schema::hasColumn('transactions', 'payment_method')) {
                $table->string('payment_method', 64)->nullable()->after('context_type');
            }
            if (!Schema::hasColumn('transactions', 'payment_method_last4')) {
                $table->string('payment_method_last4', 4)->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('transactions', 'payment_method_brand')) {
                $table->string('payment_method_brand', 32)->nullable()->after('payment_method_last4');
            }

            // ── Idempotency and linkage ───────────────────────────────────────
            if (!Schema::hasColumn('transactions', 'reference')) {
                $table->string('reference', 191)->nullable()->index()->after('payment_method_brand');
            }
            if (!Schema::hasColumn('transactions', 'parent_transaction_uuid')) {
                $table->string('parent_transaction_uuid', 191)->nullable()->after('reference');
            }

            // ── Descriptive ──────────────────────────────────────────────────
            if (!Schema::hasColumn('transactions', 'notes')) {
                $table->text('notes')->nullable()->after('description');
            }

            // ── Failure info ─────────────────────────────────────────────────
            if (!Schema::hasColumn('transactions', 'failure_reason')) {
                $table->string('failure_reason', 500)->nullable()->after('notes');
            }
            if (!Schema::hasColumn('transactions', 'failure_code')) {
                $table->string('failure_code', 64)->nullable()->after('failure_reason');
            }

            // ── Reporting ────────────────────────────────────────────────────
            if (!Schema::hasColumn('transactions', 'period')) {
                $table->string('period', 32)->nullable()->after('failure_code');
            }
            if (!Schema::hasColumn('transactions', 'tags')) {
                $table->json('tags')->nullable()->after('period');
            }

            // ── Traceability ─────────────────────────────────────────────────
            if (!Schema::hasColumn('transactions', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('tags');
            }

            // ── Lifecycle timestamps ──────────────────────────────────────────
            if (!Schema::hasColumn('transactions', 'settled_at')) {
                $table->timestamp('settled_at')->nullable()->after('ip_address');
            }
            if (!Schema::hasColumn('transactions', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('settled_at');
            }
            if (!Schema::hasColumn('transactions', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('voided_at');
            }
            if (!Schema::hasColumn('transactions', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('reversed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $columns = [
                'direction',
                'fee_amount', 'tax_amount', 'net_amount', 'balance_after',
                'exchange_rate', 'settled_currency', 'settled_amount',
                'subject_uuid', 'subject_type',
                'payer_uuid', 'payer_type',
                'payee_uuid', 'payee_type',
                'initiator_uuid', 'initiator_type',
                'context_uuid', 'context_type',
                'payment_method', 'payment_method_last4', 'payment_method_brand',
                'reference', 'parent_transaction_uuid',
                'notes', 'failure_reason', 'failure_code',
                'period', 'tags', 'ip_address',
                'settled_at', 'voided_at', 'reversed_at', 'expires_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
