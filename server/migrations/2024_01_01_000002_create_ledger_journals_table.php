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
        Schema::create('ledger_journals', function (Blueprint $table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid', 191)->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->char('transaction_uuid', 36)->nullable()->index();
            $table->char('debit_account_uuid', 36)->nullable()->index();
            $table->char('credit_account_uuid', 36)->nullable()->index();
            $table->integer('amount'); // stored in cents
            $table->string('currency', 3)->default('USD');
            $table->text('description')->nullable();
            $table->date('date')->index();
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
        Schema::dropIfExists('ledger_journals');
    }
};
