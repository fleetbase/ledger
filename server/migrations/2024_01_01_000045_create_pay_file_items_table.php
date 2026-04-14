<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('pay_file_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->char('pay_file_uuid', 36)->index();
            $table->char('carrier_invoice_uuid', 36)->index();
            $table->char('vendor_uuid', 36)->index();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20)->default('ach'); // ach, check, wire
            $table->string('reference_number')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('pay_file_uuid')->references('uuid')->on('pay_files')->cascadeOnDelete();
        });
    }
    public function down() { Schema::dropIfExists('pay_file_items'); }
};
