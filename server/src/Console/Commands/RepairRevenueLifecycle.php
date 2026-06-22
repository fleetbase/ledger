<?php

namespace Fleetbase\Ledger\Console\Commands;

use Fleetbase\Ledger\Models\Invoice;
use Fleetbase\Ledger\Services\RevenueLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairRevenueLifecycle extends Command
{
    protected $signature = 'fleetops:repair-revenue-lifecycle
                            {--apply : Apply repairs instead of reporting only}
                            {--limit=100 : Maximum records per category to repair in one run}';

    protected $description = 'Audit and repair Ledger revenue lifecycle state for deleted or canceled FleetOps orders and invoices';

    public function __construct(protected RevenueLifecycleService $revenueLifecycleService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        $this->info($apply ? '[Ledger] Applying revenue lifecycle repairs...' : '[Ledger] Dry run: revenue lifecycle repair audit.');

        $orderClass = 'Fleetbase\\FleetOps\\Models\\Order';
        if (!class_exists($orderClass)) {
            $this->warn('[Ledger] FleetOps Order model is unavailable; skipping order-linked repairs.');
        } else {
            $this->reportOrders($orderClass, $apply, $limit);
        }

        $this->reportInvoices($apply, $limit);
        $this->reportMissingReversals();

        $this->info($apply ? '[Ledger] Repair run complete.' : '[Ledger] Dry run complete. Re-run with --apply to repair eligible records.');

        return self::SUCCESS;
    }

    private function reportOrders(string $orderClass, bool $apply, int $limit): void
    {
        $query = $orderClass::withTrashed()
            ->where(function ($query) {
                $query->whereNotNull('deleted_at')
                    ->orWhereIn('status', ['canceled', 'cancelled', 'order_canceled']);
            });

        $total  = (clone $query)->count();
        $orders = $query
            ->limit($limit)
            ->get();

        $this->line("[Ledger] Inactive/deleted FleetOps orders found: {$total}");
        $this->sample($orders);

        if (!$apply) {
            return;
        }

        foreach ($orders as $order) {
            $this->revenueLifecycleService->repairOrder($order, 'repair_command');
        }
    }

    private function reportInvoices(bool $apply, int $limit): void
    {
        $query = Invoice::withTrashed()
            ->where(function ($query) {
                $query->whereNotNull('deleted_at')
                    ->orWhereIn('status', ['void', 'voided', 'cancelled', 'canceled']);
            });

        $total    = (clone $query)->count();
        $invoices = $query
            ->limit($limit)
            ->get();

        $this->line("[Ledger] Deleted/void/cancelled invoices found: {$total}");
        $this->sample($invoices);

        if (!$apply) {
            return;
        }

        foreach ($invoices as $invoice) {
            $this->revenueLifecycleService->repairInvoice($invoice, 'repair_command');
        }
    }

    private function reportMissingReversals(): void
    {
        $missing = DB::table('ledger_journals as journals')
            ->where('journals.type', 'revenue_recognition')
            ->where('journals.status', 'posted')
            ->whereNull('journals.deleted_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('ledger_journals as reversals')
                    ->where('reversals.type', 'revenue_recognition_reversal')
                    ->whereNull('reversals.deleted_at')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(reversals.meta, '$.reverses_journal_uuid')) = journals.uuid");
            })
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('ledger_invoices')
                    ->whereRaw("ledger_invoices.uuid = JSON_UNQUOTE(JSON_EXTRACT(journals.meta, '$.invoice_uuid'))")
                    ->where(function ($invoiceQuery) {
                        $invoiceQuery->whereNotNull('ledger_invoices.deleted_at')
                            ->orWhereIn('ledger_invoices.status', ['void', 'voided', 'cancelled', 'canceled']);
                    });
            })
            ->count();

        $this->line("[Ledger] Revenue-recognition journals missing reversals for inactive invoices: {$missing}");
    }

    private function sample($records): void
    {
        $ids = $records
            ->take(5)
            ->map(fn ($record) => $record->public_id ?? $record->uuid)
            ->filter()
            ->values()
            ->all();

        if ($ids) {
            $this->line('[Ledger] Sample: ' . implode(', ', $ids));
        }
    }
}
