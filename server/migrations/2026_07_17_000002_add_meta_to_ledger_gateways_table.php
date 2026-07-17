<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ledger_gateways', 'meta')) {
            Schema::table('ledger_gateways', function (Blueprint $table) {
                $table->json('meta')->nullable()->after('webhook_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ledger_gateways', 'meta')) {
            Schema::table('ledger_gateways', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }
    }
};
