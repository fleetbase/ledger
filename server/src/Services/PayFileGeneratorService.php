<?php

namespace Fleetbase\Ledger\Services;

use Carbon\Carbon;
use Fleetbase\Ledger\Models\CarrierInvoice;
use Fleetbase\Ledger\Models\PayFile;
use Fleetbase\Ledger\Models\PayFileItem;
use Fleetbase\Models\File;
use Fleetbase\Support\CompanySettingsResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Generates pay files from approved carrier invoices.
 *
 * SAFETY GUARANTEES:
 * - Invoices are NEVER marked paid by this service.
 *   Invoices flip to 'paid' only when PayFile::markAsConfirmed() is called.
 * - Invoices already in a non-cancelled pay file are excluded — preventing
 *   duplicate payment.
 * - CSV format is fully implemented. EDI 820 and NACHA are documented STUBS.
 * - Empty result returns a PayFile with 0 records (does not fail).
 */
class PayFileGeneratorService
{
    /**
     * Generate a pay file for a company over a date range.
     */
    public function generate(string $companyUuid, string $format, Carbon $start, Carbon $end): PayFile
    {
        $invoices       = $this->selectEligibleInvoices($companyUuid, $start, $end);
        $paymentMethod  = $this->resolvePaymentMethod($companyUuid);

        // Wrap creation + items in a transaction to keep state consistent
        $payFile = DB::transaction(function () use ($companyUuid, $format, $start, $end, $invoices, $paymentMethod) {
            $payFile = PayFile::create([
                'company_uuid' => $companyUuid,
                'name'         => "Pay File {$start->format('Y-m-d')} to {$end->format('Y-m-d')}",
                'format'       => $format,
                'status'       => PayFile::STATUS_DRAFT,
                'period_start' => $start->toDateString(),
                'period_end'   => $end->toDateString(),
                'record_count' => $invoices->count(),
                'total_amount' => $invoices->sum('approved_amount'),
            ]);

            foreach ($invoices as $invoice) {
                PayFileItem::create([
                    'pay_file_uuid'        => $payFile->uuid,
                    'carrier_invoice_uuid' => $invoice->uuid,
                    'vendor_uuid'          => $invoice->vendor_uuid,
                    'amount'               => $invoice->approved_amount,
                    'payment_method'       => $paymentMethod,
                    'reference_number'     => $invoice->invoice_number ?? $invoice->pro_number,
                ]);
            }

            return $payFile;
        });

        // Generate export content (outside transaction — file IO)
        $content = $this->renderContent($payFile, $format);

        $extension = match ($format) {
            PayFile::FORMAT_EDI_820   => 'edi',
            PayFile::FORMAT_ACH_NACHA => 'ach',
            default                   => 'csv',
        };

        $filename = "payfile-{$payFile->public_id}.{$extension}";
        $path     = "pay-files/{$companyUuid}/{$filename}";

        try {
            Storage::put($path, $content);

            $file = File::create([
                'company_uuid'      => $companyUuid,
                'path'              => $path,
                'original_filename' => $filename,
                'content_type'      => $this->contentTypeForFormat($format),
                'file_size'         => strlen($content),
            ]);

            $payFile->update([
                'file_uuid'    => $file->uuid,
                'status'       => PayFile::STATUS_GENERATED,
                'generated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // File storage failed — leave PayFile in draft so it can be retried
            $payFile->update([
                'meta' => array_merge($payFile->meta ?? [], [
                    'generation_error' => $e->getMessage(),
                ]),
            ]);
            throw $e;
        }

        return $payFile->fresh()->load('items', 'file');
    }

    /**
     * Select carrier invoices eligible for inclusion in a new pay file.
     *
     * Eligibility:
     * - status = 'approved'
     * - resolved_at within [start, end]
     * - NOT already in any pay_file_item where the parent pay_file is NOT cancelled
     *
     * The exclusion query prevents an invoice from appearing in two active pay files.
     */
    protected function selectEligibleInvoices(string $companyUuid, Carbon $start, Carbon $end): Collection
    {
        return CarrierInvoice::where('company_uuid', $companyUuid)
            ->where('status', 'approved')
            ->whereBetween('resolved_at', [$start, $end])
            ->whereNotIn('uuid', function ($q) {
                // Exclude invoices already locked in non-cancelled pay files
                $q->select('pay_file_items.carrier_invoice_uuid')
                  ->from('pay_file_items')
                  ->join('pay_files', 'pay_files.uuid', '=', 'pay_file_items.pay_file_uuid')
                  ->where('pay_files.status', '!=', PayFile::STATUS_CANCELLED)
                  ->whereNull('pay_files.deleted_at')
                  ->whereNull('pay_file_items.deleted_at');
            })
            ->with('vendor')
            ->get();
    }

    /**
     * Dispatch to the right formatter.
     */
    protected function renderContent(PayFile $payFile, string $format): string
    {
        return match ($format) {
            PayFile::FORMAT_CSV       => $this->formatCsv($payFile),
            PayFile::FORMAT_EDI_820   => $this->formatEdi820($payFile),
            PayFile::FORMAT_ACH_NACHA => $this->formatNacha($payFile),
            default                   => $this->formatCsv($payFile),
        };
    }

    /**
     * CSV format — fully implemented and the recommended default.
     */
    protected function formatCsv(PayFile $payFile): string
    {
        $lines = ['Vendor Name,Vendor UUID,Invoice #,PRO #,Amount,Payment Method,Reference'];

        $items = $payFile->items()
            ->with(['vendor', 'carrierInvoice'])
            ->get();

        foreach ($items as $item) {
            $invoice = $item->carrierInvoice;
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $item->vendor?->name ?? '') . '"',
                $item->vendor_uuid,
                $invoice?->invoice_number ?? '',
                $invoice?->pro_number ?? '',
                number_format((float) $item->amount, 2, '.', ''),
                $item->payment_method ?? 'ach',
                $item->reference_number ?? '',
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * EDI 820 — STUB ONLY.
     *
     * TODO: Real EDI 820 (Payment Order/Remittance Advice) requires accurate
     * field positioning, control numbers, segment terminators, hash totals,
     * envelope structure (ISA/GS/ST...SE/GE/IEA), and trading partner agreements.
     * This MUST be implemented by a finance/EDI specialist before production use.
     */
    protected function formatEdi820(PayFile $payFile): string
    {
        return "TODO: EDI 820 format not implemented. PayFile UUID: {$payFile->uuid}\n"
            . "Records: {$payFile->record_count}, Total: \${$payFile->total_amount}\n"
            . "Real EDI 820 implementation requires industry-compliant segment building,\n"
            . "control numbers, hash totals, and trading partner setup.\n";
    }

    /**
     * NACHA/ACH — STUB ONLY.
     *
     * TODO: Real NACHA file format requires 94-character fixed-width records:
     * File Header (1), Batch Header (5), Entry Detail (6), Addenda (7),
     * Batch Control (8), File Control (9). Includes immediate origin/destination
     * routing numbers, batch hash, entry hash, and block count padding.
     * This MUST be implemented by someone with bank ACH origination credentials
     * and NACHA Operating Rules expertise before production use.
     */
    protected function formatNacha(PayFile $payFile): string
    {
        return "TODO: NACHA/ACH format not implemented. PayFile UUID: {$payFile->uuid}\n"
            . "Records: {$payFile->record_count}, Total: \${$payFile->total_amount}\n"
            . "Real NACHA implementation requires 94-char fixed-width records,\n"
            . "originating bank routing setup, hash totals, and NACHA rules compliance.\n";
    }

    protected function contentTypeForFormat(string $format): string
    {
        return match ($format) {
            PayFile::FORMAT_CSV       => 'text/csv',
            PayFile::FORMAT_EDI_820   => 'application/edi-x12',
            PayFile::FORMAT_ACH_NACHA => 'application/octet-stream',
            default                   => 'text/plain',
        };
    }

    /**
     * Resolve the default payment_method from CompanySettingsResolver.
     *
     * The resolver's built-in default is 'ach' — do not add a redundant
     * hardcoded fallback here. Callers who want to override should pass
     * payment_method explicitly upstream; this method only supplies the
     * default when none was provided.
     */
    private function resolvePaymentMethod(string $companyUuid): string
    {
        return CompanySettingsResolver::forCompany($companyUuid)
            ->get('pay_files.default_payment_method');
    }
}
