<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * The `date` column was originally defined as NOT NULL. Since the invoice
     * creation form allows the date to be omitted (defaulting to today on the
     * backend), the column must be nullable so that the database does not
     * reject rows where the application-level default has not yet been applied.
     */
    public function up(): void
    {
        Schema::table('ledger_invoices', function (Blueprint $table) {
            $table->date('date')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ledger_invoices', function (Blueprint $table) {
            $table->date('date')->nullable(false)->change();
        });
    }
};
