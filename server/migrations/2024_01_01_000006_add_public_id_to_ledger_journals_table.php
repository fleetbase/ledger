<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a `public_id` column to the `ledger_journals` table so that
     * journal entries have a human-readable, externally-safe identifier
     * (e.g. "journal_abc123") via the HasPublicId trait, consistent with
     * every other Fleetbase model.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ledger_journals', function (Blueprint $table) {
            $table->string('public_id', 191)->nullable()->unique()->after('_key');
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
            $table->dropColumn('public_id');
        });
    }
};
