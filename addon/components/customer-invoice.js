import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * CustomerInvoiceComponent
 *
 * Public-facing invoice view rendered at:
 *   {console_url}/invoice?id=<invoice-public_id>
 *
 * Registered to the 'auth:login' menu registry with slug='invoice'.
 * Rendered by the host console's top-level `virtual` route at `/:slug`.
 *
 * Fetches from the public API (no auth required):
 *   GET  {API.host}/ledger/public/invoices/<public_id>
 *   GET  {API.host}/ledger/public/invoices/<public_id>/gateways
 *   POST {API.host}/ledger/public/invoices/<public_id>/pay
 */
export default class CustomerInvoiceComponent extends Component {
    @service urlSearchParams;
    @service notifications;
    @service fetch;
    @service router;

    @tracked invoice = null;
    @tracked gateways = [];
    @tracked showPaymentForm = false;
    @tracked selectedGatewayId = null;
    @tracked paymentReference = '';
    @tracked error = null;
    @tracked successMessage = null;
    @tracked isRedirectingToCheckout = false;

    constructor() {
        super(...arguments);
        this.loadInvoice.perform();
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    get invoiceId() {
        return this.urlSearchParams.get('id');
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

    get isStripeGateway() {
        return this.selectedGateway?.driver === 'stripe';
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    /**
     * Loads the invoice and available payment gateways.
     * Task state (isRunning, isIdle) is used directly in the template.
     * If ?payment=success is present (Stripe redirect-back), shows a success
     * message and reloads the invoice to reflect the paid status.
     */
    @task({ restartable: true })
    *loadInvoice() {
        this.error = null;
        const id = this.invoiceId;

        // Detect Stripe redirect-back
        const paymentParam = this.urlSearchParams.get('payment');
        if (paymentParam === 'success') {
            this.successMessage = 'Your payment was completed successfully. Thank you!';
            this.showPaymentForm = false;
        } else if (paymentParam === 'cancelled') {
            this.error = 'Payment was cancelled. You can try again below.';
        }

        if (!id) {
            this.error = 'No invoice identifier provided. Please check the link and try again.';
            return;
        }

        try {
            const invoiceData = yield this.fetch.get(`invoices/${id}`, {}, { namespace: 'ledger/public' });
            this.invoice = invoiceData?.invoice ?? invoiceData;

            try {
                const gatewaysData = yield this.fetch.get(`invoices/${id}/gateways`, {}, { namespace: 'ledger/public' });
                this.gateways = gatewaysData?.gateways ?? [];
                if (this.gateways.length > 0) {
                    this.selectedGatewayId = this.gateways[0].id;
                }
            } catch {
                // Gateways are optional — do not block the invoice view if unavailable
                this.gateways = [];
            }
        } catch (err) {
            const status = err?.status ?? err?.response?.status;
            if (status === 404) {
                this.error = 'Invoice not found. Please check the link and try again.';
            } else if (status === 403) {
                // The invoice exists but is in draft status and not yet available
                // to the customer. Show the server-provided message if present.
                this.error = err?.payload?.error ?? err?.message ?? 'This invoice is not yet available. Please contact the sender.';
            } else {
                this.error = err?.message ?? 'Failed to load invoice. Please try again later.';
            }
        }
    }

    /**
     * Submits a payment request.
     *
     * For Stripe: backend returns { checkout_url } and the browser is redirected
     * to Stripe's hosted checkout page. isRedirectingToCheckout is set to true
     * to show a loading state while the redirect happens.
     *
     * For other gateways: backend records the payment immediately and returns
     * the updated invoice.
     */
    @task({ drop: true })
    *submitPayment() {
        this.error = null;

        try {
            const data = yield this.fetch.post(
                `invoices/${this.invoiceId}/pay`,
                {
                    gateway_id: this.selectedGatewayId,
                    reference: this.paymentReference || null,
                },
                { namespace: 'ledger/public' }
            );

            // Stripe Checkout Session — redirect the browser to Stripe's hosted page
            if (data?.checkout_url) {
                this.isRedirectingToCheckout = true;
                window.location.href = data.checkout_url;
                return;
            }

            // Immediate payment recorded (cash, bank transfer, etc.)
            this.invoice = data?.invoice ?? data;
            this.successMessage = 'Your payment has been recorded successfully. Thank you!';
            this.showPaymentForm = false;
        } catch (err) {
            this.error = err?.message ?? 'Payment failed. Please try again.';
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

    @action transitionToConsole() {
        return this.router.transitionTo('console');
    }
}
