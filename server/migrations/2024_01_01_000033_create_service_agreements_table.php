<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('service_agreements', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->char('customer_uuid', 36)->index(); // the shipper/client
            $table->string('name');
            $table->string('status', 20)->default('draft'); // draft, active, expired, cancelled
            $table->string('billing_frequency', 20)->default('per_shipment'); // per_shipment, weekly, biweekly, monthly
            $table->integer('payment_terms_days')->default(30); // net 30, net 60, etc.
            $table->date('effective_date');
            $table->date('expiration_date')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
        });
    }
    public function down() { Schema::dropIfExists('service_agreements'); }
};
