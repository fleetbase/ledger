<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('gl_assignment_conditions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid', 191)->nullable()->unique();
            $table->char('gl_assignment_rule_uuid', 36);
            $table->string('field', 50);
            $table->string('operator', 20);
            $table->text('value');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('gl_assignment_rule_uuid')
                  ->references('uuid')
                  ->on('gl_assignment_rules')
                  ->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('gl_assignment_conditions');
    }
};
