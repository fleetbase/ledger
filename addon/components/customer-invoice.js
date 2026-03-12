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
 *   GET /ledger/public/invoices/<public_id>
 *
 * And lists available payment gateways from:
 *   GET /ledger/public/invoices/<public_id>/gateways
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

    @task({ restartable: true })
    *loadInvoice() {
        this.error = null;
        const id = this.invoiceId;

        if (!id) {
            this.error = 'No invoice identifier provided. Please check the link and try again.';
            return;
        }

        try {
            const baseUrl = this._publicBaseUrl();

            // Fetch invoice — marks it as 'viewed' on the backend
            const invoiceResponse = yield fetch(`${baseUrl}/invoices/${id}`);
            if (!invoiceResponse.ok) {
                if (invoiceResponse.status === 404) {
                    this.error = 'Invoice not found. Please check the link and try again.';
                } else {
                    this.error = 'Failed to load invoice. Please try again later.';
                }
                return;
            }
            const { invoice } = yield invoiceResponse.json();
            this.invoice = invoice;

            // Fetch available payment gateways for this company
            const gatewaysResponse = yield fetch(`${baseUrl}/invoices/${id}/gateways`);
            if (gatewaysResponse.ok) {
                const { gateways } = yield gatewaysResponse.json();
                this.gateways = gateways ?? [];
                if (this.gateways.length > 0) {
                    this.selectedGatewayId = this.gateways[0].id;
                }
            }
        } catch (err) {
            this.error = 'An unexpected error occurred. Please try again later.';
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
     * For gateway-based payments (Stripe, PayPal, etc.) the gateway widget
     * handles tokenisation and posts directly to the gateway charge endpoint.
     */
    @action async submitManualPayment() {
        if (!this.invoice || this.isSubmitting) return;

        this.isSubmitting = true;
        this.error = null;

        try {
            const baseUrl = this._publicBaseUrl();
            const response = await fetch(`${baseUrl}/invoices/${this.invoiceId}/pay`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    payment_method: 'bank_transfer',
                    reference: this.paymentReference || null,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                this.error = data?.error ?? data?.message ?? 'Payment failed. Please try again.';
                return;
            }

            this.invoice = data.invoice;
            this.successMessage = 'Your payment has been recorded successfully. Thank you!';
            this.showPaymentForm = false;
        } catch (err) {
            this.error = 'An unexpected error occurred. Please try again later.';
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
     * Build the base URL for the public Ledger API.
     * Resolves relative to the current origin so it works in all environments.
     */
    _publicBaseUrl() {
        const origin = window.location.origin;
        return `${origin}/ledger/public`;
    }

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
