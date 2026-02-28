<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the ledger_wallet_transactions table.
 *
 * This table provides a complete, immutable audit trail of every
 * balance movement against any wallet in the system. Every deposit,
 * withdrawal, transfer, payout, earning, and fee is recorded here.
 *
 * Monetary values are stored as integers in the smallest currency unit
 * (e.g., cents for USD/AED) per Fleetbase monetary storage standards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_wallet_transactions', function (Blueprint $table) {
            // Primary identifiers
            $table->bigIncrements('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();

            // Ownership
            $table->string('company_uuid', 191)->nullable()->index();

            // Parent wallet
            $table->string('wallet_uuid', 191)->nullable()->index();

            // Optional link to a gateway transaction that triggered this wallet movement
            $table->string('gateway_transaction_uuid', 191)->nullable()->index();

            // Transaction classification
            $table->string('type', 64)->default('deposit')
                ->comment('deposit, withdrawal, transfer_in, transfer_out, payout, fee, refund, adjustment, earning');
            $table->string('direction', 16)->default('credit')
                ->comment('credit (money in) or debit (money out)');
            $table->string('status', 32)->default('completed')
                ->comment('pending, completed, failed, reversed');

            // Monetary values — stored as integers (smallest currency unit, e.g., cents)
            $table->unsignedBigInteger('amount')->default(0)
                ->comment('Transaction amount in smallest currency unit (e.g., cents)');
            $table->bigInteger('balance_after')->default(0)
                ->comment('Wallet balance immediately after this transaction (can be negative for overdraft)');
            $table->string('currency', 3)->default('USD');

            // Description and external reference
            $table->string('description', 500)->nullable();
            $table->string('reference', 191)->nullable()->index()
                ->comment('External reference: gateway transaction ID, order public_id, invoice public_id, etc.');

            // Polymorphic subject (driver, customer, order, invoice, etc.)
            // Uses 'subject' naming convention per Fleetbase standards
            $table->string('subject_uuid', 191)->nullable()->index();
            $table->string('subject_type', 191)->nullable();

            // Flexible metadata (JSON)
            $table->json('meta')->nullable();

            // Soft deletes and timestamps
            $table->softDeletes();
            $table->timestamps();

            // Composite indexes for common query patterns
            $table->index(['wallet_uuid', 'type']);
            $table->index(['wallet_uuid', 'direction']);
            $table->index(['wallet_uuid', 'status']);
            $table->index(['wallet_uuid', 'created_at']);
            $table->index(['company_uuid', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_wallet_transactions');
    }
};
