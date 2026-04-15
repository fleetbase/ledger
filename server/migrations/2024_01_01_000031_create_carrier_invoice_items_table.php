<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('carrier_invoice_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->char('carrier_invoice_uuid', 36)->index();
            $table->string('charge_type', 30);
            $table->string('description')->nullable();
            $table->string('accessorial_code', 20)->nullable();

            $table->decimal('invoiced_amount', 10, 2);
            $table->decimal('planned_amount', 10, 2)->nullable();
            $table->decimal('approved_amount', 10, 2)->nullable();
            $table->decimal('discrepancy_amount', 10, 2)->nullable();

            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('rate', 10, 4)->nullable();
            $table->string('rate_type', 20)->nullable();

            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('carrier_invoice_uuid')
                  ->references('uuid')
                  ->on('carrier_invoices')
                  ->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('carrier_invoice_items');
    }
};
