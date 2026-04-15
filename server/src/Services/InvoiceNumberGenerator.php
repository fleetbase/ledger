<?php

namespace Fleetbase\Ledger\Services;

use Fleetbase\Ledger\Models\ClientInvoice;

/**
 * Generates sequential invoice numbers per company.
 * Format: INV-{YEAR}-{SEQUENCE} e.g., INV-2026-00001
 */
class InvoiceNumberGenerator
{
    public function generate(string $companyUuid): string
    {
        $year = now()->format('Y');
        $prefix = "INV-{$year}-";

        $lastInvoice = ClientInvoice::where('company_uuid', $companyUuid)
            ->where('invoice_number', 'like', "{$prefix}%")
            ->orderBy('invoice_number', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) str_replace($prefix, '', $lastInvoice->invoice_number);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }
}
