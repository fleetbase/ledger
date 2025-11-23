<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ledger_invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->char('created_by_uuid', 36)->nullable()->index();
            $table->char('updated_by_uuid', 36)->nullable()->index();
            $table->uuid('customer_uuid')->nullable()->index();
            $table->string('customer_type')->nullable();
            $table->uuid('order_uuid')->nullable()->index();
            $table->uuid('transaction_uuid')->nullable()->index();
            $table->string('number', 191)->unique();
            $table->date('date')->index();
            $table->date('due_date')->nullable()->index();
            $table->integer('subtotal')->default(0); // stored in cents
            $table->integer('tax')->default(0); // stored in cents
            $table->integer('total_amount')->default(0); // stored in cents
            $table->integer('amount_paid')->default(0); // stored in cents
            $table->integer('balance')->default(0); // stored in cents
            $table->string('currency', 3)->default('USD');
            $table->string('status', 191)->default('draft')->index(); // draft, sent, viewed, paid, partial, overdue, void, cancelled
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->unique(['uuid']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ledger_invoices');
    }
};
