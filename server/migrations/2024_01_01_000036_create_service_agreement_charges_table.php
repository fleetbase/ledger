<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('service_agreement_charges', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->char('service_agreement_uuid', 36)->index();
            $table->char('charge_template_uuid', 36)->index();
            $table->json('overrides')->nullable(); // per-client overrides: {"linehaul": {"rate": 2.50}, "fuel_surcharge": {"rate": 15}}
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('service_agreement_uuid')->references('uuid')->on('service_agreements')->cascadeOnDelete();
            $table->foreign('charge_template_uuid')->references('uuid')->on('charge_templates');
        });
    }
    public function down() { Schema::dropIfExists('service_agreement_charges'); }
};
