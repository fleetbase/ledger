<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('gainshare_rules', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->char('service_agreement_uuid', 36)->index();
            $table->string('calculation_basis', 20)->default('per_shipment'); // per_shipment, per_period
            $table->decimal('split_percentage_company', 5, 2)->default(50.00);
            $table->decimal('split_percentage_client', 5, 2)->default(50.00);
            $table->decimal('minimum_savings_threshold', 10, 2)->nullable(); // minimum savings to trigger gainshare
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
            $table->foreign('service_agreement_uuid')->references('uuid')->on('service_agreements')->cascadeOnDelete();
        });
    }
    public function down() { Schema::dropIfExists('gainshare_rules'); }
};
