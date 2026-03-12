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
    @tracked showPaymentForm = false;
    @tracked selectedGatewayId = null;
    @tracked paymentReference = '';
    @tracked error = null;
    @tracked successMessage = null;

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

    // ── Tasks ─────────────────────────────────────────────────────────────────

    /**
     * Loads the invoice and available payment gateways.
     * Task state (isRunning, isIdle) is used directly in the template.
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
            } else {
                this.error = err?.message ?? 'Failed to load invoice. Please try again later.';
            }
        }
    }

    /**
     * Submits a manual payment confirmation.
     * Task state (isRunning) drives the submit button disabled/spinner state.
     */
    @task({ drop: true })
    *submitPayment() {
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
        const owner = getOwner(this);
        const router = owner.lookup('router:main');
        return router.transitionTo('console');
    }
}
