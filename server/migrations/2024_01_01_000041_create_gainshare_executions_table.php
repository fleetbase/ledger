<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('gainshare_executions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->char('gainshare_rule_uuid', 36)->index();
            $table->char('shipment_uuid', 36)->nullable()->index();
            $table->char('carrier_invoice_uuid', 36)->nullable();
            $table->char('client_invoice_uuid', 36)->nullable();
            $table->char('cost_benchmark_uuid', 36)->nullable();
            $table->decimal('benchmark_total', 12, 2)->nullable();
            $table->decimal('actual_total', 12, 2)->nullable();
            $table->decimal('savings', 12, 2)->nullable();
            $table->decimal('company_share', 12, 2)->nullable();
            $table->decimal('client_share', 12, 2)->nullable();
            $table->string('status', 20)->default('calculated'); // calculated, approved, invoiced
            $table->date('period_start')->nullable(); // for per_period calculations
            $table->date('period_end')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
            $table->foreign('gainshare_rule_uuid')->references('uuid')->on('gainshare_rules');
        });
    }
    public function down() { Schema::dropIfExists('gainshare_executions'); }
};
