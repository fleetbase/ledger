<?php

namespace Fleetbase\Ledger\Services;

use Carbon\Carbon;
use Fleetbase\Ledger\Models\GlAssignment;
use Fleetbase\Ledger\Models\GlExportBatch;
use Fleetbase\Models\File;
use Illuminate\Support\Facades\Storage;

class GlExportService
{
    public function generateExport(string $companyUuid, string $format, Carbon $start, Carbon $end): GlExportBatch
    {
        $assignments = GlAssignment::where('company_uuid', $companyUuid)
            ->whereBetween('created_at', [$start, $end])
            ->with('glAccount', 'assignable')
            ->get();

        $batch = GlExportBatch::create([
            'company_uuid' => $companyUuid,
            'format'       => $format,
            'status'       => 'pending',
            'period_start' => $start->toDateString(),
            'period_end'   => $end->toDateString(),
            'record_count' => $assignments->count(),
            'total_amount' => $assignments->sum('amount'),
        ]);

        $content = match ($format) {
            'csv'            => $this->formatCsv($assignments),
            'quickbooks_iif' => $this->formatQuickBooksIIF($assignments),
            'json'           => $this->formatJson($assignments),
            default          => $this->formatCsv($assignments),
        };

        $extension = match ($format) {
            'quickbooks_iif' => 'iif',
            'json'           => 'json',
            default          => 'csv',
        };
        $filename = "gl-export-{$start->format('Y-m-d')}-{$end->format('Y-m-d')}.{$extension}";
        $path     = "gl-exports/{$companyUuid}/{$filename}";

        Storage::put($path, $content);

        $file = File::create([
            'company_uuid'  => $companyUuid,
            'path'          => $path,
            'original_name' => $filename,
            'content_type'  => $extension === 'csv' ? 'text/csv' : 'application/' . $extension,
        ]);

        $batch->update([
            'file_uuid'   => $file->uuid,
            'status'      => 'generated',
            'exported_at' => now(),
        ]);

        return $batch;
    }

    protected function formatCsv($assignments): string
    {
        $lines = ['GL Code,GL Name,Amount,Record Type,Record ID,Date,Assignment Type,Rule'];

        foreach ($assignments as $a) {
            $lines[] = implode(',', [
                $a->glAccount->code ?? '',
                '"' . str_replace('"', '""', $a->glAccount->name ?? '') . '"',
                $a->amount,
                class_basename($a->assignable_type),
                $a->assignable_uuid,
                $a->created_at->format('Y-m-d'),
                $a->assignment_type,
                $a->rule?->name ?? 'Default',
            ]);
        }

        return implode("\n", $lines);
    }

    protected function formatQuickBooksIIF($assignments): string
    {
        $lines   = [];
        $lines[] = "!TRNS\tTRNSTYPE\tDATE\tACCNT\tAMOUNT\tMEMO";
        $lines[] = "!SPL\tTRNSTYPE\tDATE\tACCNT\tAMOUNT\tMEMO";
        $lines[] = "!ENDTRNS";

        foreach ($assignments as $a) {
            $date    = $a->created_at->format('m/d/Y');
            $lines[] = "TRNS\tGENERAL JOURNAL\t{$date}\t{$a->glAccount->code}\t{$a->amount}\t" . class_basename($a->assignable_type);
            $lines[] = "SPL\tGENERAL JOURNAL\t{$date}\tAccounts Payable\t-{$a->amount}\t";
            $lines[] = "ENDTRNS";
        }

        return implode("\n", $lines);
    }

    protected function formatJson($assignments): string
    {
        return $assignments->map(fn ($a) => [
            'gl_code'         => $a->glAccount->code,
            'gl_name'         => $a->glAccount->name,
            'amount'          => $a->amount,
            'record_type'     => class_basename($a->assignable_type),
            'record_id'       => $a->assignable_uuid,
            'date'            => $a->created_at->toIso8601String(),
            'assignment_type' => $a->assignment_type,
        ])->toJson(JSON_PRETTY_PRINT);
    }
}
