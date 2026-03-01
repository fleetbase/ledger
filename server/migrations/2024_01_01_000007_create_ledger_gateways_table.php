<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the ledger_gateways table.
 *
 * This table stores configured payment gateway instances.
 * Credentials are stored encrypted (handled at the application layer via
 * the Gateway model's 'encrypted:array' cast on the config column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_gateways', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->char('company_uuid', 36)->nullable()->index();
            $table->char('created_by_uuid', 36)->nullable()->index();

            // Gateway identity
            $table->string('name');                         // User-defined name, e.g. "Main Stripe Account"
            $table->string('driver');                       // Driver code: 'stripe', 'qpay', 'cash'
            $table->text('description')->nullable();

            // Credentials — stored encrypted at the application layer
            $table->text('config')->nullable();

            // Cached capability list from the driver's getCapabilities()
            $table->json('capabilities')->nullable();

            // Operational flags
            $table->boolean('is_sandbox')->default(false)->index();
            $table->string('status', 50)->default('active')->index();  // 'active', 'inactive'

            // URLs
            $table->string('return_url')->nullable();       // Redirect URL after off-site payment
            $table->string('webhook_url')->nullable();      // Registered webhook URL (informational)

            $table->softDeletes();
            $table->timestamps();

            // Compound index for common lookups
            $table->index(['company_uuid', 'driver', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_gateways');
    }
};
