import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';

export default class GatewayDetailsComponent extends Component {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service hostRouter;

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

    get diagnosticSummary() {
        return this.diagnostics?.diagnostics ?? {};
    }

    get gatewaySummary() {
        return this.diagnostics?.gateway ?? {};
    }

    get webhookStatus() {
        return this.diagnosticSummary.webhook_registration ?? (this.args.resource?.webhook_url ? 'configured' : 'not_configured');
    }

    get lastWebhookAt() {
        return this.diagnosticSummary.last_webhook_received_at;
    }

    get lastPaymentAt() {
        return this.diagnosticSummary.last_payment_event_at;
    }

    get lastRefundAt() {
        return this.diagnosticSummary.last_refund_event_at;
    }

    get lastSettlementAt() {
        return this.diagnosticSummary.last_settlement_seen_at;
    }

    get reconciliationStatus() {
        return this.diagnosticSummary.last_reconciliation_status;
    }

    get credentialStatus() {
        return this.diagnosticSummary.credential_status ?? 'not_checked';
    }

    get lastCredentialTestedAt() {
        return this.diagnosticSummary.last_credential_tested_at;
    }

    get lastCredentialTestMessage() {
        return this.diagnosticSummary.last_credential_test_message;
    }

    get lastWebhookRegistrationAt() {
        return this.diagnosticSummary.last_webhook_registration_at;
    }

    get lastTestOrderAt() {
        return this.diagnosticSummary.last_test_order_at;
    }

    get lastTestOrderId() {
        return this.diagnosticSummary.last_test_order_id;
    }

    get readinessItems() {
        return [
            {
                label: 'Connection',
                value: this.args.resource?.status === 'active' ? 'Ready' : 'Inactive',
                status: this.args.resource?.status === 'active' ? 'ready' : 'inactive',
                icon: 'plug',
                caption: this.args.resource?.status === 'active' ? 'Available on invoices' : 'Not used at checkout',
                accentClass: this.args.resource?.status === 'active' ? 'ledger-gateway-kpi-accent-green' : 'ledger-gateway-kpi-accent-slate',
            },
            {
                label: 'Environment',
                value: this.args.resource?.environment === 'live' ? 'Live' : 'Sandbox',
                status: this.args.resource?.environment ?? 'unknown',
                icon: 'server',
                caption: this.args.resource?.environment === 'live' ? 'Production payments' : 'Testing and sandbox use',
                accentClass: this.args.resource?.environment === 'live' ? 'ledger-gateway-kpi-accent-blue' : 'ledger-gateway-kpi-accent-amber',
            },
            {
                label: 'Webhook',
                value: this.webhookStatus === 'configured' ? 'Configured' : 'Needs Setup',
                status: this.webhookStatus === 'configured' ? 'configured' : 'needs setup',
                icon: 'link',
                caption: this.webhookStatus === 'configured' ? 'Provider callback ready' : 'Registration needed',
                accentClass: this.webhookStatus === 'configured' ? 'ledger-gateway-kpi-accent-green' : 'ledger-gateway-kpi-accent-amber',
            },
            {
                label: 'Credentials',
                value: this.credentialStatus === 'success' || this.credentialStatus === 'ok' ? 'Verified' : this.credentialStatus === 'failed' ? 'Failed' : 'Not Checked',
                status: this.credentialStatus,
                icon: 'key',
                caption: this.lastCredentialTestedAt ? 'Last provider auth test' : 'Run a provider check',
                accentClass:
                    this.credentialStatus === 'success' || this.credentialStatus === 'ok'
                        ? 'ledger-gateway-kpi-accent-green'
                        : this.credentialStatus === 'failed'
                          ? 'ledger-gateway-kpi-accent-rose'
                          : 'ledger-gateway-kpi-accent-slate',
            },
        ];
    }

    get providerStatusRows() {
        return [
            {
                label: 'Driver',
                value: this.driverLabel,
                icon: this.isTaler ? 'wallet' : 'plug',
            },
            {
                label: 'Mode',
                value: this.args.resource?.environment === 'live' ? 'Live' : 'Sandbox',
                icon: this.args.resource?.environment === 'live' ? 'bolt' : 'flask',
            },
            {
                label: 'Credentials',
                value: this.credentialStatus === 'not_checked' ? 'Not checked' : this.credentialStatus,
                caption: this.lastCredentialTestMessage,
                date: this.lastCredentialTestedAt,
                icon: 'key',
                status: this.credentialStatus === 'success' || this.credentialStatus === 'ok' ? 'success' : this.credentialStatus === 'failed' ? 'danger' : 'warning',
            },
            {
                label: 'Webhook',
                value: this.webhookStatus === 'configured' ? 'Configured' : 'Needs setup',
                date: this.lastWebhookRegistrationAt ?? this.lastWebhookAt,
                icon: 'link',
                status: this.webhookStatus === 'configured' ? 'success' : 'warning',
            },
            {
                label: 'Payment',
                value: this.lastPaymentAt ? 'Seen' : 'No payment yet',
                date: this.lastPaymentAt,
                icon: 'money-bill-transfer',
                status: this.lastPaymentAt ? 'success' : 'default',
            },
            {
                label: 'Refund',
                value: this.lastRefundAt ? 'Seen' : 'No refund yet',
                date: this.lastRefundAt,
                icon: 'rotate-left',
                status: this.lastRefundAt ? 'success' : 'default',
            },
            {
                label: 'Settlement',
                value: this.reconciliationStatus ?? 'Not checked',
                date: this.lastSettlementAt,
                icon: 'scale-balanced',
                status: this.reconciliationStatus ? 'success' : 'warning',
            },
        ];
    }

    get setupIdentityRows() {
        return [
            {
                label: 'Name',
                value: this.args.resource?.name,
            },
            {
                label: 'Driver',
                value: this.driverLabel,
            },
            {
                label: 'Public ID',
                value: this.args.resource?.public_id,
                mono: true,
                copyable: true,
            },
            {
                label: 'Status',
                value: this.args.resource?.status,
            },
            {
                label: 'Environment',
                value: this.args.resource?.environment ?? (this.args.resource?.is_sandbox ? 'sandbox' : 'live'),
            },
            {
                label: 'Created',
                value: this.args.resource?.createdAt ?? this.args.resource?.created_at,
                date: true,
            },
        ];
    }

    get setupRoutingRows() {
        return [
            {
                label: 'Return URL',
                value: this.args.resource?.return_url,
                mono: true,
                copyable: true,
            },
            {
                label: 'Ledger webhook URL',
                value: this.args.resource?.system_webhook_url,
                mono: true,
                copyable: true,
            },
            {
                label: 'Provider registered callback',
                value: this.args.resource?.webhook_url,
                displayValue:
                    this.args.resource?.webhook_url && this.args.resource?.webhook_url === this.args.resource?.system_webhook_url
                        ? 'Same as Ledger webhook URL'
                        : this.args.resource?.webhook_url,
                caption:
                    this.args.resource?.webhook_url && this.args.resource?.webhook_url === this.args.resource?.system_webhook_url
                        ? 'The provider posts events to the Ledger webhook URL above.'
                        : null,
                mono: true,
                copyable: true,
            },
        ];
    }

    get setupConfigRows() {
        const configSummary = this.gatewaySummary.config_summary ?? [];

        if (configSummary.length) {
            return configSummary;
        }

        return [
            {
                label: 'Provider credentials',
                value: 'Stored encrypted and hidden',
            },
        ];
    }

    get recentActivity() {
        return [
            this.activityItem('Webhook', this.diagnostics?.last_webhook, 'link'),
            this.activityItem('Payment', this.diagnostics?.last_payment, 'money-bill-transfer'),
            this.activityItem('Refund', this.diagnostics?.last_refund, 'rotate-left'),
            this.activityItem('Settlement', this.diagnostics?.last_settlement, 'scale-balanced', this.lastSettlementAt),
        ].filter(Boolean);
    }

    get diagnosticsResults() {
        return [
            {
                label: 'Credential test',
                value: this.lastCredentialTestMessage ?? 'No credential test has been run.',
                date: this.lastCredentialTestedAt,
                status: this.credentialStatus === 'success' || this.credentialStatus === 'ok' ? 'success' : this.credentialStatus === 'failed' ? 'danger' : 'warning',
            },
            {
                label: 'Webhook registration',
                value: this.lastWebhookRegistrationAt
                    ? 'Provider webhook registration completed.'
                    : this.webhookStatus === 'configured'
                      ? 'Webhook URL is configured, but no registration action has been recorded.'
                      : 'No provider webhook registration stored.',
                date: this.lastWebhookRegistrationAt,
                status: this.lastWebhookRegistrationAt ? 'success' : 'warning',
            },
            {
                label: 'Test order',
                value: this.lastTestOrderId ? `Last order ${this.lastTestOrderId}` : 'No test order has been created.',
                date: this.lastTestOrderAt,
                status: this.lastTestOrderId ? 'success' : 'warning',
            },
        ];
    }

    get diagnosticActions() {
        if (!this.isTaler) {
            return [];
        }

        return [
            {
                key: 'test-credentials',
                title: 'Test Credentials',
                description: 'Checks whether Ledger can authenticate against the configured Taler Merchant Backend.',
                expectedResult: 'Updates credential status and last checked time.',
                icon: this.testCredentials.isRunning ? 'spinner' : 'check-circle',
                isLoading: this.testCredentials.isRunning,
                task: this.testCredentials,
                primary: true,
            },
            {
                key: 'register-webhook',
                title: 'Register Webhook',
                description: 'Creates or updates the Taler webhook so payment events route back to this exact gateway.',
                expectedResult: 'Stores the registered webhook URL and routing payload.',
                icon: this.registerWebhook.isRunning ? 'spinner' : 'link',
                isLoading: this.registerWebhook.isRunning,
                task: this.registerWebhook,
                confirm: true,
                confirmTitle: 'Register Taler webhook?',
                confirmBody: 'Ledger will create or update the webhook in the Taler Merchant Backend for this gateway.',
            },
            {
                key: 'create-test-order',
                title: 'Create Test Order',
                description: 'Creates a small sandbox order to verify order creation and wallet redirect behavior.',
                expectedResult: 'Creates a provider test order and records its order id.',
                icon: this.createTestOrder.isRunning ? 'spinner' : 'flask',
                isLoading: this.createTestOrder.isRunning,
                task: this.createTestOrder,
                confirm: true,
                confirmTitle: 'Create Taler test order?',
                confirmBody: 'Ledger will create a test order in the configured Taler Merchant Backend. No customer invoice is changed.',
            },
            {
                key: 'refresh',
                title: 'Refresh Diagnostics',
                description: 'Reloads the latest credential, webhook, payment, refund, and settlement diagnostic data from Ledger.',
                expectedResult: 'No provider mutation; refreshes this screen only.',
                icon: this.loadDiagnostics.isRunning ? 'spinner' : 'rotate',
                isLoading: this.loadDiagnostics.isRunning,
                task: this.loadDiagnostics,
                secondary: true,
            },
        ];
    }

    get overviewActions() {
        return [
            ...this.diagnosticActions.filter((diagnosticAction) => diagnosticAction.key !== 'refresh'),
            {
                key: 'copy-webhook-url',
                title: 'Copy Webhook URL',
                description: 'Copies the Ledger callback URL for manual provider setup.',
                expectedResult: 'Use this when provider webhook registration is handled outside Ledger.',
                icon: 'copy',
                action: this.copySystemWebhookUrl,
                secondary: true,
            },
            {
                key: 'edit-settings',
                title: 'Edit Settings',
                description: 'Change the gateway name, credentials, routing, status, or environment.',
                expectedResult: 'Opens the gateway setup wizard for this existing gateway.',
                icon: 'pen-to-square',
                action: this.editGateway,
                secondary: true,
            },
        ];
    }

    activityItem(label, record, icon, fallbackDate) {
        if (!record && !fallbackDate) {
            return null;
        }

        return {
            label,
            icon,
            status: record?.status ?? record?.event_type ?? this.reconciliationStatus ?? 'seen',
            message: record?.message ?? record?.description ?? (label === 'Settlement' ? this.reconciliationStatus : null),
            date: record?.created_at ?? record?.createdAt ?? fallbackDate,
            reference: record?.gateway_reference_id ?? record?.id,
        };
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

    @action runDiagnosticAction(diagnosticAction) {
        if (diagnosticAction.action) {
            return diagnosticAction.action();
        }

        if (diagnosticAction.confirm) {
            return this.modalsManager.confirm({
                title: diagnosticAction.confirmTitle,
                body: diagnosticAction.confirmBody,
                acceptButtonText: diagnosticAction.title,
                confirm: () => diagnosticAction.task.perform(),
            });
        }

        return diagnosticAction.task.perform();
    }

    @action editGateway() {
        return this.hostRouter.transitionTo('console.ledger.payments.gateways.edit', this.args.resource);
    }

    @action copySystemWebhookUrl() {
        const url = this.args.resource?.system_webhook_url;

        if (!url) {
            return this.notifications.warning('No system webhook URL is available yet.');
        }

        copyToClipboard(url)
            .then(() => this.notifications.success('System webhook URL copied.'))
            .catch(() => this.notifications.error('Unable to copy webhook URL.'));
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
