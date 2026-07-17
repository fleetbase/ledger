<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_gateway_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('ledger_gateway_transactions', 'reconciliation_status')) {
                $table->string('reconciliation_status', 64)->nullable()->after('processed_at')->index('ledger_gateway_transactions_reconciliation_status_index');
            }

            if (!Schema::hasColumn('ledger_gateway_transactions', 'reconciliation_checked_at')) {
                $table->timestamp('reconciliation_checked_at')->nullable()->after('reconciliation_status');
            }

            if (!Schema::hasColumn('ledger_gateway_transactions', 'reconciliation_data')) {
                $table->json('reconciliation_data')->nullable()->after('reconciliation_checked_at');
            }

            if (!Schema::hasColumn('ledger_gateway_transactions', 'refund_status')) {
                $table->string('refund_status', 64)->nullable()->after('reconciliation_data')->index('ledger_gateway_transactions_refund_status_index');
            }

            if (!Schema::hasColumn('ledger_gateway_transactions', 'refund_accepted_at')) {
                $table->timestamp('refund_accepted_at')->nullable()->after('refund_status');
            }

            if (!Schema::hasColumn('ledger_gateway_transactions', 'refund_expires_at')) {
                $table->timestamp('refund_expires_at')->nullable()->after('refund_accepted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ledger_gateway_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('ledger_gateway_transactions', 'refund_expires_at')) {
                $table->dropColumn('refund_expires_at');
            }

            if (Schema::hasColumn('ledger_gateway_transactions', 'refund_accepted_at')) {
                $table->dropColumn('refund_accepted_at');
            }

            if (Schema::hasColumn('ledger_gateway_transactions', 'refund_status')) {
                $table->dropIndex('ledger_gateway_transactions_refund_status_index');
                $table->dropColumn('refund_status');
            }

            if (Schema::hasColumn('ledger_gateway_transactions', 'reconciliation_data')) {
                $table->dropColumn('reconciliation_data');
            }

            if (Schema::hasColumn('ledger_gateway_transactions', 'reconciliation_checked_at')) {
                $table->dropColumn('reconciliation_checked_at');
            }

            if (Schema::hasColumn('ledger_gateway_transactions', 'reconciliation_status')) {
                $table->dropIndex('ledger_gateway_transactions_reconciliation_status_index');
                $table->dropColumn('reconciliation_status');
            }
        });
    }
};
