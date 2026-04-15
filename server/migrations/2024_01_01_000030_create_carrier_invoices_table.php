<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('carrier_invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->char('vendor_uuid', 36)->index();
            $table->char('order_uuid', 36)->nullable()->index();
            $table->char('shipment_uuid', 36)->nullable()->index();
            $table->string('invoice_number')->nullable();
            $table->string('pro_number')->nullable()->index();
            $table->string('bol_number')->nullable()->index();

            $table->string('source', 20)->default('manual');
            $table->string('status', 20)->default('pending');

            $table->decimal('invoiced_amount', 12, 2);
            $table->decimal('planned_amount', 12, 2)->nullable();
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->decimal('discrepancy_amount', 12, 2)->nullable();
            $table->decimal('discrepancy_percent', 5, 2)->nullable();

            $table->string('discrepancy_type', 20)->nullable();
            $table->string('resolution', 20)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->char('resolved_by', 36)->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->date('pickup_date')->nullable();
            $table->date('delivery_date')->nullable();

            $table->string('currency', 3)->default('USD');
            $table->char('file_uuid', 36)->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
            $table->foreign('vendor_uuid')->references('uuid')->on('vendors');
            $table->foreign('resolved_by')->references('uuid')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('carrier_invoices');
    }
};
