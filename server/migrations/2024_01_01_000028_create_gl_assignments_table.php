<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('gl_assignments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->char('gl_account_uuid', 36);
            $table->char('gl_assignment_rule_uuid', 36)->nullable();
            $table->string('assignable_type');
            $table->char('assignable_uuid', 36);
            $table->decimal('amount', 12, 2);
            $table->string('assignment_type', 20)->default('auto');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
            $table->foreign('gl_account_uuid')->references('uuid')->on('ledger_accounts');
            $table->foreign('gl_assignment_rule_uuid')->references('uuid')->on('gl_assignment_rules')->nullOnDelete();
            $table->index(['assignable_type', 'assignable_uuid']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('gl_assignments');
    }
};
