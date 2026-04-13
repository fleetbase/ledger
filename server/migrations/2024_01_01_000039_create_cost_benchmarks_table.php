<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('cost_benchmarks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->char('service_agreement_uuid', 36)->nullable()->index();
            $table->string('benchmark_type', 20)->default('contracted'); // market, historical, contracted
            $table->string('lane_origin', 50)->nullable(); // state or zip
            $table->string('lane_destination', 50)->nullable();
            $table->string('mode', 20)->nullable(); // ftl, ltl, parcel, etc.
            $table->string('equipment_type', 50)->nullable();
            $table->decimal('benchmark_rate', 12, 2);
            $table->string('rate_unit', 20)->default('flat'); // flat, per_mile, per_cwt
            $table->date('effective_date');
            $table->date('expiration_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
            $table->foreign('service_agreement_uuid')->references('uuid')->on('service_agreements')->nullOnDelete();
        });
    }
    public function down() { Schema::dropIfExists('cost_benchmarks'); }
};
