<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('client_invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->char('customer_uuid', 36)->index();
            $table->char('service_agreement_uuid', 36)->nullable();
            $table->char('shipment_uuid', 36)->nullable()->index();
            $table->string('invoice_number', 50)->nullable()->index();
            $table->string('status', 20)->default('draft'); // draft, sent, paid, overdue, cancelled
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('period_start')->nullable(); // for batch invoices
            $table->date('period_end')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
            $table->foreign('service_agreement_uuid')->references('uuid')->on('service_agreements')->nullOnDelete();
        });
    }
    public function down() { Schema::dropIfExists('client_invoices'); }
};
