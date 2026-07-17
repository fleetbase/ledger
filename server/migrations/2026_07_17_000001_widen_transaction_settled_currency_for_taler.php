<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compatibility migration for installs where Ledger created the transaction
     * settlement columns before core-api widened settled_currency.
     */
    public function up(): void
    {
        $this->changeSettledCurrencyLength(10);
    }

    public function down(): void
    {
        $this->changeSettledCurrencyLength(3);
    }

    private function changeSettledCurrencyLength(int $length): void
    {
        if (!Schema::hasTable('transactions') || !Schema::hasColumn('transactions', 'settled_currency')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) use ($length) {
            $table->string('settled_currency', $length)->nullable()->change();
        });
    }
};
