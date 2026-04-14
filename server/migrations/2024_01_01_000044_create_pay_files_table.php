<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('pay_files', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->index();
            $table->string('name');
            $table->string('format', 20)->default('csv'); // csv, edi_820, ach_nacha
            $table->string('status', 20)->default('draft'); // draft, generated, sent, confirmed, cancelled
            $table->date('period_start');
            $table->date('period_end');
            $table->char('file_uuid', 36)->nullable();
            $table->integer('record_count')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('company_uuid')->references('uuid')->on('companies');
        });
    }
    public function down() { Schema::dropIfExists('pay_files'); }
};
