<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('charge_template_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->char('charge_template_uuid', 36)->index();
            $table->string('charge_type', 50); // linehaul, fuel_surcharge, accessorial, management_fee, gainshare, etc.
            $table->string('description')->nullable();
            $table->string('calculation_method', 30); // flat, per_mile, per_cwt, per_unit, percentage_of_linehaul, percentage_of_total
            $table->decimal('rate', 12, 4)->nullable(); // the rate value (amount, per-mile rate, percentage, etc.)
            $table->decimal('minimum', 10, 2)->nullable(); // minimum charge
            $table->decimal('maximum', 10, 2)->nullable(); // maximum charge cap
            $table->integer('sequence')->default(0); // display/calculation order
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('charge_template_uuid')->references('uuid')->on('charge_templates')->cascadeOnDelete();
        });
    }
    public function down() { Schema::dropIfExists('charge_template_items'); }
};
