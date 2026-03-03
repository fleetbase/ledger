<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Renames the `date` column to `entry_date` in the `ledger_journals` table
     * to match the frontend model attribute name and avoid conflicts with
     * reserved words in some SQL contexts.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ledger_journals', function (Blueprint $table) {
            $table->renameColumn('date', 'entry_date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ledger_journals', function (Blueprint $table) {
            $table->renameColumn('entry_date', 'date');
        });
    }
};
