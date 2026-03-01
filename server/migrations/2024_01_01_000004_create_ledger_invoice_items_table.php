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
        Schema::create('ledger_invoice_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid', 191)->nullable()->unique();
            $table->uuid('invoice_uuid')->nullable()->index();
            $table->string('description', 191);
            $table->integer('quantity')->default(1);
            $table->integer('unit_price')->default(0); // stored in cents
            $table->integer('amount')->default(0); // stored in cents
            $table->decimal('tax_rate', 5, 2)->default(0.00); // percentage
            $table->integer('tax_amount')->default(0); // stored in cents
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ledger_invoice_items');
    }
};
