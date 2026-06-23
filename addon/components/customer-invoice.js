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

    @tracked invoice = null;
    @tracked gateways = [];
    @tracked gatewaysLoaded = false;
    @tracked showPaymentForm = false;
    @tracked selectedGatewayId = null;
    @tracked paymentReference = '';
    @tracked error = null;
    @tracked successMessage = null;
    @tracked pendingMessage = null;
    @tracked isRedirectingToCheckout = false;
    @tracked talerPaymentUri = null;
    @tracked paymentQrImage = null;
    @tracked paymentQrText = null;

    constructor() {
        super(...arguments);
        this.installTalerSupportMeta();
        this.loadInvoice.perform();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.removeTalerSupportMeta();
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
        return this.invoice && !this.isPaid && !this.isVoid && this.gatewaysLoaded && this.hasGateways;
    }

    get cannotAcceptOnlinePayment() {
        return this.invoice && !this.isPaid && !this.isVoid && this.gatewaysLoaded && !this.hasGateways;
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

    get hasTalerPaymentUri() {
        return typeof this.talerPaymentUri === 'string' && this.talerPaymentUri.startsWith('taler');
    }

    get paymentQrImageSrc() {
        if (typeof this.paymentQrImage !== 'string' || this.paymentQrImage.length === 0) {
            return null;
        }

        if (this.paymentQrImage.startsWith('data:') || this.paymentQrImage.startsWith('http://') || this.paymentQrImage.startsWith('https://')) {
            return this.paymentQrImage;
        }

        return `data:image/png;base64,${this.paymentQrImage}`;
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
        this.gatewaysLoaded = false;
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
            } finally {
                this.gatewaysLoaded = true;
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
     * For redirect-capable gateways: backend returns { payment_url } or
     * { checkout_url } and the browser is redirected to the hosted/wallet flow.
     *
     * For other gateways: backend records the payment immediately and returns
     * the updated invoice.
     */
    @task({ drop: true })
    *submitPayment() {
        this.error = null;

        if (!this.selectedGatewayId) {
            this.error = 'Online payment is not available for this invoice.';
            return;
        }

        try {
            const data = yield this.fetch.post(
                `invoices/${this.invoiceId}/pay`,
                {
                    gateway_id: this.selectedGatewayId,
                    reference: this.paymentReference || null,
                },
                { namespace: 'ledger/public' }
            );

            const paymentUrl = data?.payment_url ?? data?.payment_uri ?? data?.checkout_url ?? data?.data?.taler_pay_uri;
            this.paymentQrImage = data?.qr_image ?? data?.data?.qr_image ?? null;
            this.paymentQrText = data?.qr_text ?? data?.data?.qr_text ?? paymentUrl ?? null;

            if (this.isTalerUri(paymentUrl)) {
                this.talerPaymentUri = paymentUrl;
                this.isRedirectingToCheckout = false;
                this.pendingMessage = data?.message ?? 'Payment started. Open your GNU Taler wallet to complete it.';
                return;
            }

            // Redirect payment sessions — Stripe Checkout, QPay app link, etc.
            if (paymentUrl) {
                this.isRedirectingToCheckout = true;
                this.pendingMessage = data?.message ?? 'Redirecting to payment provider...';
                window.location.href = paymentUrl;
                return;
            }

            if (data?.payment_status === 'pending') {
                this.pendingMessage = data?.message ?? 'Payment started. Complete it in your payment app, then refresh this invoice.';
                this.showPaymentForm = false;
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
        this.pendingMessage = null;
        this.talerPaymentUri = null;
        this.paymentQrImage = null;
        this.paymentQrText = null;
        this.error = null;
    }

    @action selectGateway(gatewayId) {
        this.selectedGatewayId = gatewayId;
    }

    @action updateReference(event) {
        this.paymentReference = event.target.value;
    }

    isTalerUri(value) {
        return typeof value === 'string' && (value.startsWith('taler://') || value.startsWith('taler+http://') || value.startsWith('taler+https://'));
    }

    installTalerSupportMeta() {
        if (typeof document === 'undefined') {
            return;
        }

        let meta = document.querySelector('meta[name="taler-support"]');

        if (!meta) {
            meta = document.createElement('meta');
            meta.name = 'taler-support';
            meta.dataset.ledgerTalerSupport = 'true';
            document.head.appendChild(meta);
        }

        meta.content = 'uri';
    }

    removeTalerSupportMeta() {
        if (typeof document === 'undefined') {
            return;
        }

        const meta = document.querySelector('meta[name="taler-support"][data-ledger-taler-support="true"]');
        meta?.remove();
    }
}
