<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add result_type column to classify gainshare outcomes:
 * savings, loss, break_even, below_threshold.
 *
 * Previously, losses and break-even results were silently discarded.
 * Now all outcomes are recorded for complete financial visibility.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('gainshare_executions', function (Blueprint $table) {
            $table->string('result_type', 20)->default('savings')->after('client_share');
            // values: savings, loss, break_even, below_threshold
        });
    }

    public function down()
    {
        Schema::table('gainshare_executions', function (Blueprint $table) {
            $table->dropColumn('result_type');
        });
    }
};
