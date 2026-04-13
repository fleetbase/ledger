<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('gl_assignment_rules', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->string('name');
            $table->integer('priority')->default(0);
            $table->string('match_type', 20)->default('all');
            $table->char('gl_account_uuid', 36);
            $table->string('target', 50);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
            $table->foreign('gl_account_uuid')->references('uuid')->on('ledger_accounts');
        });
    }

    public function down()
    {
        Schema::dropIfExists('gl_assignment_rules');
    }
};
