<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->char('parent_account_uuid', 36)->nullable()->after('status');
            $table->boolean('is_active')->default(true)->after('parent_account_uuid');

            $table->foreign('parent_account_uuid')
                  ->references('uuid')
                  ->on('ledger_accounts')
                  ->nullOnDelete();

            $table->index('parent_account_uuid');
        });
    }

    public function down()
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->dropForeign(['parent_account_uuid']);
            $table->dropIndex(['parent_account_uuid']);
            $table->dropColumn(['parent_account_uuid', 'is_active']);
        });
    }
};
