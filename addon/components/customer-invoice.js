import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * CustomerInvoiceComponent
 *
 * Public-facing invoice view rendered at:
 *   /ledger/invoice/<invoice-public_id>
 *
 * This component is registered via the 'engine:ledger' menu registry and
 * rendered by the ledger engine's virtual route. It requires NO authentication.
 *
 * It fetches the invoice from the public API endpoint:
 *   GET /ledger/public/invoices/<slug>
 *
 * And lists available payment gateways from:
 *   GET /ledger/public/invoices/<slug>/gateways
 *
 * @argument {string} slug  - The invoice public_id or uuid (from the virtual route model)
 */
export default class CustomerInvoiceComponent extends Component {
    @service fetch;
    @service notifications;

    /** The resolved invoice plain object (not an Ember Data model — public endpoint). */
    @tracked invoice = null;

    /** Available active payment gateways for this invoice's company. */
    @tracked gateways = [];

    /** Whether the payment form is visible. */
    @tracked showPaymentForm = false;

    /** Selected gateway public_id for the payment form. */
    @tracked selectedGatewayId = null;

    /** Payment amount in cents (pre-filled from invoice.balance). */
    @tracked paymentAmount = 0;

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

    get slug() {
        // The virtual route passes the menu item as @params; the slug is the
        // invoice public_id extracted from the URL segment.
        return this.args.slug ?? this.args.params?.slug;
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
            draft:     'badge-secondary',
            sent:      'badge-info',
            viewed:    'badge-info',
            partial:   'badge-warning',
            paid:      'badge-success',
            overdue:   'badge-danger',
            void:      'badge-muted',
            cancelled: 'badge-muted',
        };
        return map[this.invoice?.status] ?? 'badge-secondary';
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    @task({ restartable: true })
    *loadInvoice() {
        this.error = null;
        const slug = this.slug;
        if (!slug) {
            this.error = 'No invoice identifier provided.';
            return;
        }

        try {
            const baseUrl = this._publicBaseUrl();

            // Fetch invoice
            const invoiceResponse = yield fetch(`${baseUrl}/invoices/${slug}`);
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
            this.paymentAmount = invoice.balance ?? 0;

            // Fetch available gateways
            const gatewaysResponse = yield fetch(`${baseUrl}/invoices/${slug}/gateways`);
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
        this.successMessage  = null;
        this.error           = null;
    }

    @action selectGateway(gatewayId) {
        this.selectedGatewayId = gatewayId;
    }

    @action updateReference(event) {
        this.paymentReference = event.target.value;
    }

    /**
     * Submit a manual payment confirmation to the public pay endpoint.
     *
     * For gateway-based payments (Stripe, PayPal, etc.) the gateway component
     * handles tokenisation and calls the gateway charge endpoint directly.
     * This action is used for manual / bank-transfer confirmations.
     */
    @action async submitManualPayment() {
        if (!this.invoice || this.isSubmitting) return;
        if (!this.paymentAmount || this.paymentAmount <= 0) {
            this.error = 'Please enter a valid payment amount.';
            return;
        }

        this.isSubmitting = true;
        this.error        = null;

        try {
            const baseUrl  = this._publicBaseUrl();
            const response = await fetch(`${baseUrl}/invoices/${this.slug}/pay`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body:    JSON.stringify({
                    amount:         this.paymentAmount,
                    payment_method: 'bank_transfer',
                    reference:      this.paymentReference || null,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                this.error = data?.error ?? data?.message ?? 'Payment failed. Please try again.';
                return;
            }

            this.invoice        = data.invoice;
            this.successMessage = 'Your payment has been recorded successfully. Thank you!';
            this.showPaymentForm = false;
        } catch (err) {
            this.error = 'An unexpected error occurred. Please try again later.';
        } finally {
            this.isSubmitting = false;
        }
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
     * Falls back to a simple division if Intl.NumberFormat is unavailable.
     */
    _formatCurrency(cents, currency = 'USD') {
        if (cents === null || cents === undefined) return '—';
        const amount = cents / 100;
        try {
            return new Intl.NumberFormat(undefined, {
                style:    'currency',
                currency: currency.toUpperCase(),
            }).format(amount);
        } catch {
            return `${currency.toUpperCase()} ${amount.toFixed(2)}`;
        }
    }
}
