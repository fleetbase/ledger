import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { getOwner } from '@ember/application';

/**
 * CustomerInvoiceComponent
 *
 * Public-facing invoice view rendered at:
 *   {console_url}/invoice?id=<invoice-public_id>
 *
 * This component is registered via the 'auth:login' menu registry and
 * rendered by the host console's top-level `virtual` route at `/:slug`.
 * The slug is 'invoice', and the invoice public_id is passed as a query param.
 *
 * URL:  {console_url}/invoice?id=INV-0001
 *
 * It fetches the invoice from the public API endpoint (no auth required):
 *   GET  {API.host}/ledger/public/invoices/<public_id>
 *   GET  {API.host}/ledger/public/invoices/<public_id>/gateways
 *   POST {API.host}/ledger/public/invoices/<public_id>/pay
 *
 * All requests use `this.fetch` (the @fleetbase/ember-core FetchService) with
 * `namespace: 'ledger/public'` so the URL is built correctly from `config.API.host`
 * without any manual string concatenation.
 */
export default class CustomerInvoiceComponent extends Component {
    @service urlSearchParams;
    @service notifications;
    @service fetch;

    /** The resolved invoice plain object (not an Ember Data model — public endpoint). */
    @tracked invoice = null;

    /** Available active payment gateways for this invoice's company. */
    @tracked gateways = [];

    /** Whether the payment form is visible. */
    @tracked showPaymentForm = false;

    /** Selected gateway id for the payment form. */
    @tracked selectedGatewayId = null;

    /** Customer-supplied payment reference / note. */
    @tracked paymentReference = '';

    /** Whether a payment submission is in progress. */
    @tracked isSubmitting = false;

    /** Error message to display inline. */
    @tracked error = null;

    /** Success message shown after a successful payment. */
    @tracked successMessage = null;

    constructor() {
        super(...arguments);
        this.loadInvoice.perform();
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    /**
     * The invoice public_id is read from the `?id=` query param in the URL.
     * e.g. {console_url}/invoice?id=INV-0001
     */
    get invoiceId() {
        return this.urlSearchParams.get('id');
    }

    get isLoading() {
        return this.loadInvoice.isRunning;
    }

    get isPaid() {
        return this.invoice?.status === 'paid';
    }

    get isVoid() {
        return ['void', 'cancelled'].includes(this.invoice?.status);
    }

    get canAcceptPayment() {
        return this.invoice && !this.isPaid && !this.isVoid;
    }

    get hasGateways() {
        return this.gateways.length > 0;
    }

    get selectedGateway() {
        return this.gateways.find((g) => g.id === this.selectedGatewayId) ?? null;
    }

    get formattedBalance() {
        if (!this.invoice) return '—';
        return this._formatCurrency(this.invoice.balance, this.invoice.currency);
    }

    get formattedTotal() {
        if (!this.invoice) return '—';
        return this._formatCurrency(this.invoice.total_amount, this.invoice.currency);
    }

    get formattedAmountPaid() {
        if (!this.invoice) return '—';
        return this._formatCurrency(this.invoice.amount_paid, this.invoice.currency);
    }

    get statusBadgeClass() {
        const map = {
            draft: 'badge-secondary',
            sent: 'badge-info',
            viewed: 'badge-info',
            partial: 'badge-warning',
            paid: 'badge-success',
            overdue: 'badge-danger',
            void: 'badge-muted',
            cancelled: 'badge-muted',
        };
        return map[this.invoice?.status] ?? 'badge-secondary';
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    /**
     * Load the invoice and its available payment gateways from the public API.
     *
     * Uses `this.fetch.get(path, query, { namespace })` which builds the URL as:
     *   {config.API.host}/{namespace}/{path}
     *
     * For the public ledger routes the namespace is 'ledger/public', which
     * matches the backend route group:
     *   Route::prefix('ledger')->group(fn() => Route::prefix('public')->group(...))
     *
     * So `this.fetch.get('invoices/INV-0001', {}, { namespace: 'ledger/public' })`
     * resolves to `{API.host}/ledger/public/invoices/INV-0001`.
     */
    @task({ restartable: true })
    *loadInvoice() {
        this.error = null;
        const id = this.invoiceId;

        if (!id) {
            this.error = 'No invoice identifier provided. Please check the link and try again.';
            return;
        }

        try {
            // Fetch invoice — the backend marks it as 'viewed' on GET
            const invoiceData = yield this.fetch.get(`invoices/${id}`, {}, { namespace: 'ledger/public' });
            this.invoice = invoiceData?.invoice ?? invoiceData;

            // Fetch available payment gateways for this company
            try {
                const gatewaysData = yield this.fetch.get(`invoices/${id}/gateways`, {}, { namespace: 'ledger/public' });
                this.gateways = gatewaysData?.gateways ?? [];
                if (this.gateways.length > 0) {
                    this.selectedGatewayId = this.gateways[0].id;
                }
            } catch {
                // Gateways are optional — a missing gateway list should not block the invoice view
                this.gateways = [];
            }
        } catch (err) {
            const status = err?.status ?? err?.response?.status;
            if (status === 404) {
                this.error = 'Invoice not found. Please check the link and try again.';
            } else {
                this.error = err?.message ?? 'Failed to load invoice. Please try again later.';
            }
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    @action togglePaymentForm() {
        this.showPaymentForm = !this.showPaymentForm;
        this.successMessage = null;
        this.error = null;
    }

    @action selectGateway(gatewayId) {
        this.selectedGatewayId = gatewayId;
    }

    @action updateReference(event) {
        this.paymentReference = event.target.value;
    }

    /**
     * Submit a manual / bank-transfer payment confirmation.
     *
     * Uses `this.fetch.post(path, data, { namespace })` which builds the URL as:
     *   {config.API.host}/{namespace}/{path}
     *
     * POST {API.host}/ledger/public/invoices/{id}/pay
     */
    @action async submitManualPayment() {
        if (!this.invoice || this.isSubmitting) return;

        this.isSubmitting = true;
        this.error = null;

        try {
            const data = yield this.fetch.post(
                `invoices/${this.invoiceId}/pay`,
                {
                    payment_method: 'bank_transfer',
                    reference: this.paymentReference || null,
                },
                { namespace: 'ledger/public' }
            );

            this.invoice = data?.invoice ?? data;
            this.successMessage = 'Your payment has been recorded successfully. Thank you!';
            this.showPaymentForm = false;
        } catch (err) {
            this.error = err?.message ?? 'Payment failed. Please try again.';
        } finally {
            this.isSubmitting = false;
        }
    }

    /**
     * Navigate back to the console home.
     */
    @action transitionToConsole() {
        const owner = getOwner(this);
        const router = owner.lookup('router:main');
        return router.transitionTo('console');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Format a cents integer as a human-readable currency string.
     */
    _formatCurrency(cents, currency = 'USD') {
        if (cents === null || cents === undefined) return '—';
        const amount = cents / 100;
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: currency.toUpperCase(),
            }).format(amount);
        } catch {
            return `${currency.toUpperCase()} ${amount.toFixed(2)}`;
        }
    }
}
