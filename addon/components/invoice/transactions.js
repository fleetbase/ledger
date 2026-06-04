import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

/**
 * Invoice::Transactions component.
 *
 * Displays all ledger-transaction records related to the invoice. The backend
 * owns the linkage rules so invoice payments, direct invoice transactions, and
 * legacy context-linked records are all returned consistently.
 *
 * The invoice is passed in as `@invoice` (a `LedgerInvoiceModel` instance).
 * We filter by `context: invoice.uuid` because `InvoiceService::recordPayment`
 * sets `context_uuid = invoice->uuid` on every transaction it creates, and
 * `TransactionFilter::context()` maps the `context` query param to that column.
 *
 * Usage:
 *   <Invoice::Transactions @invoice={{@model}} />
 */
export default class InvoiceTransactionsComponent extends Component {
    @service store;
    @service fetch;
    @service intl;
    @service transactionActions;

    @tracked transactions = [];
    @tracked meta = null;
    @tracked page = 1;
    @tracked isLoading = false;

    constructor(owner, args) {
        super(owner, args);
        this.loadTransactions.perform();
    }

    // ── Columns ──────────────────────────────────────────────────────────────

    get columns() {
        return [
            // Reference ID — anchors to the transaction detail view
            {
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'table/cell/anchor',
                action: this.transactionActions.transition.view,
                width: 160,
                resizable: true,
                sortable: false,
            },
            // Date
            {
                label: this.intl.t('column.date'),
                valuePath: 'createdAt',
                resizable: true,
                sortable: false,
            },
            // Description
            {
                label: this.intl.t('column.description'),
                valuePath: 'description',
                resizable: true,
                sortable: false,
            },
            // Transaction type (invoice_payment, refund, etc.) — humanized
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
                humanize: true,
                resizable: true,
                sortable: false,
            },
            // Direction (credit / debit)
            {
                label: this.intl.t('column.direction'),
                valuePath: 'direction',
                humanize: true,
                resizable: true,
                sortable: false,
            },
            // Amount
            {
                label: this.intl.t('column.amount'),
                valuePath: 'amount',
                cellComponent: 'table/cell/currency',
                resizable: true,
                sortable: false,
            },
            // Status badge
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: false,
            },
            // Gateway
            {
                label: this.intl.t('column.gateway'),
                valuePath: 'gateway',
                humanize: true,
                resizable: true,
                sortable: false,
            },
            // Payment method
            {
                label: this.intl.t('column.payment-method'),
                valuePath: 'payment_method',
                humanize: true,
                resizable: true,
                sortable: false,
            },
            // Payer display name (resolved from the polymorphic payer relation)
            {
                label: this.intl.t('column.payer'),
                valuePath: 'payer_name',
                resizable: true,
                sortable: false,
            },
            // Row-actions dropdown — view transaction detail
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.transaction') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.transaction') }),
                        icon: 'eye',
                        fn: this.transactionActions.transition.view,
                        permission: 'ledger view transaction',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    // ── Data loading ─────────────────────────────────────────────────────────

    /**
     * Query ledger-transaction records scoped to this invoice.
     */
    @task *loadTransactions() {
        const invoice = this.args.invoice;

        // Guard: invoice must be loaded and have a UUID
        if (!invoice?.uuid) {
            this.transactions = [];
            return;
        }

        try {
            const result = yield this.fetch.get(`invoices/${invoice.id}/transactions`, { sort: '-created_at', limit: 50, page: this.page }, { namespace: 'ledger/int/v1' });
            const records = (result?.transactions ?? []).map((transaction) => this.store.push(this.store.normalize('ledger-transaction', transaction)));

            this.transactions = records;
            this.meta = result?.meta ?? null;
        } catch {
            this.transactions = [];
            this.meta = null;
        }
    }
}
