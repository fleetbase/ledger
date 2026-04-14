<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('pay_file_schedules', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->string('name');
            $table->string('format', 20)->default('csv');
            $table->string('frequency', 20)->default('weekly'); // weekly, biweekly, monthly
            $table->integer('day_of_week')->nullable(); // 0-6 for weekly/biweekly
            $table->integer('day_of_month')->nullable(); // 1-31 for monthly
            $table->boolean('auto_send')->default(false);
            $table->json('recipients')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
        });
    }
    public function down() { Schema::dropIfExists('pay_file_schedules'); }
};
