import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class SettingsPaymentController extends Controller {
    @service fetch;
    @service notifications;
    @service store;

    // ── Tracked settings fields ───────────────────────────────────────────────
    @tracked default_gateway_uuid = null;
    @tracked default_gateway = null;
    @tracked allow_partial_payments = false;
    @tracked auto_apply_wallet_credit = false;
    @tracked send_payment_receipt = true;

    // ── Gateway list for the selector ─────────────────────────────────────────
    @tracked availableGateways = [];

    constructor() {
        super(...arguments);
        this.loadGateways.perform();
        this.getSettings.perform();
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    @task *loadGateways() {
        try {
            const gateways = yield this.store.query('ledger-gateway', {
                status: 'active',
                limit: 100,
                sort: 'name',
            });
            this.availableGateways = gateways.toArray();
        } catch (error) {
            // Non-fatal: gateway list just won't populate
            this.availableGateways = [];
        }
    }

    @task *getSettings() {
        try {
            const { paymentSettings } = yield this.fetch.get('ledger/settings/payment-settings');
            if (paymentSettings) {
                this.default_gateway_uuid = paymentSettings.default_gateway_uuid ?? null;
                this.default_gateway = paymentSettings.default_gateway ?? null;
                this.allow_partial_payments = paymentSettings.allow_partial_payments ?? false;
                this.auto_apply_wallet_credit = paymentSettings.auto_apply_wallet_credit ?? false;
                this.send_payment_receipt = paymentSettings.send_payment_receipt ?? true;
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *saveSettings() {
        try {
            yield this.fetch.post('ledger/settings/payment-settings', {
                paymentSettings: {
                    default_gateway_uuid: this.default_gateway_uuid,
                    allow_partial_payments: this.allow_partial_payments,
                    auto_apply_wallet_credit: this.auto_apply_wallet_credit,
                    send_payment_receipt: this.send_payment_receipt,
                },
            });
            this.notifications.success('Payment settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    @action onSelectDefaultGateway(gateway) {
        if (gateway) {
            // Prefer uuid (internal), fall back to public_id
            this.default_gateway_uuid = gateway.uuid || gateway.public_id || gateway.id;
            this.default_gateway = gateway;
        } else {
            this.default_gateway_uuid = null;
            this.default_gateway = null;
        }
    }

    // ── Computed helpers ──────────────────────────────────────────────────────

    get selectedGateway() {
        if (!this.default_gateway_uuid) return null;
        return this.availableGateways.find(
            (g) => g.uuid === this.default_gateway_uuid || g.public_id === this.default_gateway_uuid
        ) ?? null;
    }
}
