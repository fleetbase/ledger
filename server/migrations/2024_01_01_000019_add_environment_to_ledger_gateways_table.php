<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add environment column to the ledger_gateways table.
 *
 * - environment: 'sandbox' | 'live' — replaces the boolean is_sandbox flag
 *   with a more expressive string that matches the frontend form options.
 *   The existing is_sandbox column is retained for backwards compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_gateways', function (Blueprint $table) {
            $table->string('environment', 20)->default('live')->after('is_sandbox')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_gateways', function (Blueprint $table) {
            $table->dropColumn('environment');
        });
    }
};
