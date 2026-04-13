<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('carrier_invoice_audit_rules', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->string('name');
            $table->string('rule_type', 30);
            $table->decimal('tolerance_percent', 5, 2)->nullable();
            $table->decimal('tolerance_amount', 10, 2)->nullable();
            $table->string('charge_type', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
        });
    }

    public function down()
    {
        Schema::dropIfExists('carrier_invoice_audit_rules');
    }
};
