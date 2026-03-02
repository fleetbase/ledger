<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds name, description, and is_frozen columns to ledger_wallets.
 *
 * Also drops the overly-restrictive unique(subject_uuid, subject_type) constraint
 * which prevented a subject (e.g. a company) from having more than one wallet.
 * The new unique key is (company_uuid, subject_uuid, subject_type, name) so that
 * each named wallet per subject per company is unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_wallets', function (Blueprint $table) {
            // Drop the old unique constraint that only allowed one wallet per subject
            $table->dropUnique(['subject_uuid', 'subject_type']);

            // Add new columns
            $table->string('name', 191)->nullable()->after('subject_type');
            $table->text('description')->nullable()->after('name');
            $table->boolean('is_frozen')->default(false)->after('status');

            // New unique constraint: one named wallet per subject per company
            $table->unique(['company_uuid', 'subject_uuid', 'subject_type', 'name'], 'ledger_wallets_company_subject_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_wallets', function (Blueprint $table) {
            $table->dropUnique('ledger_wallets_company_subject_name_unique');
            $table->dropColumn(['name', 'description', 'is_frozen']);
            $table->unique(['subject_uuid', 'subject_type']);
        });
    }
};
