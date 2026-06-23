<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen Ledger-owned currency columns so demo and regional Taler currencies
     * such as KUDOS can be persisted without exposing them as normal UI options.
     */
    public function up(): void
    {
        $this->changeCurrencyColumn('ledger_accounts', false, 'USD', 10);
        $this->changeCurrencyColumn('ledger_journals', false, 'USD', 10);
        $this->changeCurrencyColumn('ledger_invoices', false, 'USD', 10);
        $this->changeCurrencyColumn('ledger_wallets', false, 'USD', 10);
        $this->changeCurrencyColumn('ledger_gateway_transactions', true, null, 10);
    }

    public function down(): void
    {
        $this->changeCurrencyColumn('ledger_accounts', false, 'USD', 3);
        $this->changeCurrencyColumn('ledger_journals', false, 'USD', 3);
        $this->changeCurrencyColumn('ledger_invoices', false, 'USD', 3);
        $this->changeCurrencyColumn('ledger_wallets', false, 'USD', 3);
        $this->changeCurrencyColumn('ledger_gateway_transactions', true, null, 3);
    }

    private function changeCurrencyColumn(string $tableName, bool $nullable, ?string $default, int $length): void
    {
        if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'currency')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($length, $nullable, $default) {
            $column = $table->string('currency', $length);

            if ($nullable) {
                $column->nullable();
            }

            if ($default !== null) {
                $column->default($default);
            }

            $column->change();
        });
    }
};
