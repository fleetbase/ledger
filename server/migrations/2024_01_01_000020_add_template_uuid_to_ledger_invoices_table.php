<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ledger_invoices', function (Blueprint $table) {
            $table->uuid('template_uuid')->nullable()->after('transaction_uuid')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ledger_invoices', function (Blueprint $table) {
            $table->dropColumn('template_uuid');
        });
    }
};
