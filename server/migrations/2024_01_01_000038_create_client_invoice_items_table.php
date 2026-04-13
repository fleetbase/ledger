<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('client_invoice_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->char('client_invoice_uuid', 36)->index();
            $table->string('charge_type', 50);
            $table->string('description')->nullable();
            $table->string('calculation_method', 30)->nullable();
            $table->decimal('rate', 12, 4)->nullable();
            $table->decimal('quantity', 12, 2)->nullable(); // miles, cwt, units, etc.
            $table->decimal('amount', 12, 2)->default(0); // calculated charge
            $table->char('shipment_uuid', 36)->nullable(); // for batch invoices with multiple shipments
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('client_invoice_uuid')->references('uuid')->on('client_invoices')->cascadeOnDelete();
        });
    }
    public function down() { Schema::dropIfExists('client_invoice_items'); }
};
