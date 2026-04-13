<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add benchmark_source to gainshare_rules to support multiple benchmark
 * resolution strategies. Defaults to 'cost_benchmark' (current behavior).
 * 'rate_contract' is reserved for future rating engine integration.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('gainshare_rules', function (Blueprint $table) {
            $table->string('benchmark_source', 20)->default('cost_benchmark')->after('calculation_basis');
            // values: cost_benchmark, rate_contract
        });
    }

    public function down()
    {
        Schema::table('gainshare_rules', function (Blueprint $table) {
            $table->dropColumn('benchmark_source');
        });
    }
};
