<?php

namespace Fleetbase\Ledger\Console\Commands;

use Carbon\Carbon;
use Fleetbase\Ledger\Models\Invoice;
use Illuminate\Console\Command;

class UpdateOverdueInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ledger:update-overdue-invoices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finds all invoices that are past their due date and updates their status to overdue';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Checking for overdue invoices...');

        $overdueInvoices = Invoice::where('due_date', '<', Carbon::now())
            ->whereIn('status', ['sent', 'viewed'])
            ->get();

        if ($overdueInvoices->isEmpty()) {
            $this->info('No overdue invoices found.');

            return 0;
        }

        $this->info(sprintf('Found %s overdue invoices to update.', $overdueInvoices->count()));

        foreach ($overdueInvoices as $invoice) {
            $invoice->status = 'overdue';
            $invoice->save();
            $this->line(sprintf('Updated invoice #%s to overdue.', $invoice->number));
        }

        $this->info('All overdue invoices have been updated.');

        return 0;
    }
}
