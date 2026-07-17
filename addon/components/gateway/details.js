import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class GatewayDetailsComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked diagnostics = null;
    @tracked lastActionResult = null;

    constructor() {
        super(...arguments);
        this.loadDiagnostics.perform();
    }

    get isTaler() {
        return this.args.resource?.driver === 'taler';
    }

    get gatewayId() {
        return this.args.resource?.id;
    }

    get section() {
        return this.args.section ?? 'overview';
    }

    get showOverview() {
        return this.section === 'overview';
    }

    get showSetup() {
        return this.section === 'setup';
    }

    get showDiagnostics() {
        return this.section === 'diagnostics';
    }

    get driverLabel() {
        const driver = this.args.resource?.driver;

        if (!driver) {
            return 'Gateway';
        }

        if (driver === 'taler') {
            return 'GNU Taler';
        }

        if (driver === 'qpay') {
            return 'QPay';
        }

        return driver.charAt(0).toUpperCase() + driver.slice(1);
    }

    get webhookStatus() {
        return this.diagnostics?.diagnostics?.webhook_registration ?? (this.args.resource?.webhook_url ? 'configured' : 'not_configured');
    }

    get lastWebhookAt() {
        return this.diagnostics?.diagnostics?.last_webhook_received_at;
    }

    get lastPaymentAt() {
        return this.diagnostics?.diagnostics?.last_payment_event_at;
    }

    get lastRefundAt() {
        return this.diagnostics?.diagnostics?.last_refund_event_at;
    }

    get lastSettlementAt() {
        return this.diagnostics?.diagnostics?.last_settlement_seen_at;
    }

    get reconciliationStatus() {
        return this.diagnostics?.diagnostics?.last_reconciliation_status;
    }

    get readinessItems() {
        return [
            {
                label: 'Connection',
                status: this.args.resource?.status === 'active' ? 'ready' : 'inactive',
                icon: 'plug',
            },
            {
                label: 'Environment',
                status: this.args.resource?.environment ?? 'unknown',
                icon: 'server',
            },
            {
                label: 'Webhook',
                status: this.webhookStatus === 'configured' ? 'configured' : 'needs setup',
                icon: 'link',
            },
            {
                label: 'Settlement',
                status: this.reconciliationStatus ?? 'not checked',
                icon: 'scale-balanced',
            },
        ];
    }

    get checkoutPreviewLabel() {
        if (this.args.resource?.driver === 'cash') {
            return 'Manual payment instructions';
        }

        if (this.args.resource?.driver === 'taler') {
            return 'Wallet redirect and QR payment';
        }

        return 'Online invoice payment option';
    }

    @task({ restartable: true })
    *loadDiagnostics() {
        if (!this.gatewayId) return;

        try {
            this.diagnostics = yield this.fetch.get(`gateways/${this.gatewayId}/diagnostics`, {}, { namespace: 'ledger/int/v1' });
        } catch {
            this.diagnostics = null;
        }
    }

    @task({ drop: true })
    *testCredentials() {
        yield* this.runGatewayAction('test-credentials', 'Taler credentials accepted.');
    }

    @task({ drop: true })
    *createTestOrder() {
        yield* this.runGatewayAction('create-test-order', 'Taler test order created.');
    }

    @task({ drop: true })
    *registerWebhook() {
        yield* this.runGatewayAction('register-webhook', 'Taler webhook registered.');
    }

    *runGatewayAction(action, successMessage) {
        if (!this.gatewayId) return;

        try {
            const result = yield this.fetch.post(`gateways/${this.gatewayId}/${action}`, {}, { namespace: 'ledger/int/v1' });
            this.lastActionResult = result;
            this.notifications.success(result?.message ?? successMessage);
            yield this.loadDiagnostics.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
