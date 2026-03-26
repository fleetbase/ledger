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
        Schema::create('ledger_wallets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->char('created_by_uuid', 36)->nullable()->index();
            $table->char('updated_by_uuid', 36)->nullable()->index();
            $table->uuid('subject_uuid')->nullable()->index();
            $table->string('subject_type')->nullable();
            $table->integer('balance')->default(0); // stored in cents
            $table->string('currency', 3)->default('USD');
            $table->string('status', 191)->default('active')->index(); // active, frozen, closed
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['subject_uuid', 'subject_type']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ledger_wallets');
    }
};
