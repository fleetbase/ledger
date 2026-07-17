import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';

export default class GatewayFormComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked availableDrivers = [];
    @tracked activeStep = 0;
    @tracked configSchema = [];
    @tracked configValues = {};
    @tracked connectionTestResult;

    constructor() {
        super(...arguments);
        this.loadDrivers.perform();
    }

    get setupSteps() {
        const steps = [
            { icon: 'key', label: 'Credentials', complete: this.hasRequiredConfig },
            { icon: 'route', label: 'Routing', complete: Boolean(this.args.resource?.environment && this.args.resource?.status) },
            { icon: 'circle-check', label: 'Review', complete: Boolean(this.args.resource?.name && this.args.resource?.driver) },
        ];

        if (this.canSelectDriver) {
            steps.unshift({ icon: 'plug', label: 'Gateway', complete: Boolean(this.selectedDriver) });
        }

        return steps.map((step, index) => ({
            ...step,
            key: step.label.toLowerCase(),
            active: this.activeStep === index,
            complete: index < this.activeStep && step.complete,
        }));
    }

    get currentStep() {
        return this.setupSteps[this.activeStep]?.key ?? this.setupSteps[0]?.key;
    }

    get canSelectDriver() {
        return !this.args.resource?.id;
    }

    get driverCards() {
        return this.availableDrivers.map((driver) => ({
            ...driver,
            selected: driver.code === this.args.resource?.driver,
            categoryLabel: driver.category ?? this.driverCategory(driver.code),
            icon: this.driverIcon(driver.code),
        }));
    }

    get selectedDriver() {
        return this.availableDrivers.find((driver) => driver.code === this.args.resource?.driver);
    }

    get selectedDriverIcon() {
        return this.driverIcon(this.selectedDriver?.code);
    }

    get hasRequiredConfig() {
        const requiredFields = this.configSchema.filter((field) => field.required);

        return requiredFields.every((field) => {
            const value = this.configValues[field.key];
            return value !== null && value !== undefined && String(value).trim() !== '';
        });
    }

    get connectionState() {
        if (this.testCredentials.isRunning) {
            return 'testing';
        }

        if (!this.connectionTestResult) {
            return 'idle';
        }

        return this.connectionTestResult.success ? 'success' : 'failed';
    }

    get connectionStateTitle() {
        switch (this.connectionState) {
            case 'testing':
                return 'Testing gateway credentials...';
            case 'success':
                return 'Gateway verified';
            case 'failed':
                return 'Gateway test failed';
            default:
                return this.args.resource?.id ? 'Ready to verify' : 'Save to verify';
        }
    }

    get connectionStateMessage() {
        if (!this.args.resource?.id) {
            return 'Create the gateway first, then run live credential checks and webhook registration from the gateway diagnostics screen.';
        }

        if (this.connectionState === 'idle') {
            return 'Run a credential test before using this gateway for invoice payments.';
        }

        if (this.connectionState === 'testing') {
            return 'Ledger is checking the configured credentials with the payment provider.';
        }

        return this.connectionTestResult?.message ?? 'The gateway credential check completed.';
    }

    get connectionMetadataEntries() {
        return Object.entries(this.connectionTestResult?.metadata ?? {}).map(([key, value]) => ({
            label: key.replaceAll('_', ' '),
            value,
        }));
    }

    get reviewConfigEntries() {
        return this.configSchema.map((field) => ({
            label: field.label,
            value: field.type === 'password' && this.configValues[field.key] ? '••••••••' : this.configValues[field.key],
        }));
    }

    @task *loadDrivers() {
        try {
            const response = yield this.fetch.get('gateways/drivers', {}, { namespace: 'ledger/int/v1' });
            // The endpoint returns { status: 'ok', drivers: [...] }
            this.availableDrivers = response?.drivers ?? response ?? [];

            if (this.args.resource?.driver) {
                yield this.loadSchema.perform(this.args.resource.driver);
            } else {
                const initialDriver = this.args.initialDriverCode ? this.availableDrivers.find((driver) => driver.code === this.args.initialDriverCode) : this.availableDrivers[0];
                if (initialDriver) {
                    this.selectDriver(initialDriver);
                }
            }
        } catch (err) {
            this.notifications.warning('Could not load available payment drivers.');
        }
    }

    @task *loadSchema(driverCode) {
        yield Promise.resolve();
        const driver = this.availableDrivers.find((d) => d.code === driverCode);
        this.configSchema = driver?.config_schema ?? [];

        // Pre-fill with existing config values or field defaults
        const existingConfig = this.args.resource?.config ?? {};
        const values = {};
        this.configSchema.forEach((field) => {
            values[field.key] = existingConfig[field.key] ?? field.default ?? null;
        });
        this.configValues = values;

        // Default webhook_url to the system-computed handler URL when not already set.
        // driver.webhook_url is the full URL returned by the backend (e.g. https://api.example.com/ledger/webhooks/stripe).
        // We never fall back to a relative path here — if the manifest does not include a full URL yet,
        // the user can copy it from the "System webhook URL" hint shown below the field.
        const resource = this.args.resource;
        if (resource && !resource.webhook_url && driver?.webhook_url) {
            resource.webhook_url = driver.webhook_url;
        }
    }

    @action selectDriver(driver) {
        if (!this.canSelectDriver) {
            return;
        }

        this.args.resource.set?.('driver', driver.code);
        this.args.resource.set?.('name', this.args.resource?.name || driver.name);
        this.args.resource.set?.('code', this.args.resource?.code || driver.code);
        // Sync the driver's capabilities to the resource so they are persisted
        if (driver.capabilities) {
            this.args.resource.set?.('capabilities', driver.capabilities);
        }
        this.loadSchema.perform(driver.code);
        this.connectionTestResult = null;
    }

    @action updateConfigField(key, value) {
        // When bound via {{on "input" (fn this.updateConfigField field.key)}} the second
        // argument is a DOM InputEvent, not the raw string value.  Extract the actual
        // value from event.target.value in that case so credentials are stored correctly.
        const resolvedValue = this.resolveFieldValue(value);
        this.configValues = { ...this.configValues, [key]: resolvedValue };
        // Persist config back to the resource
        this.args.resource.set?.('config', { ...this.configValues });
        this.connectionTestResult = null;
    }

    @task *testCredentials() {
        if (!this.args.resource?.id) {
            this.notifications.info('Save this gateway before testing credentials.');
            return;
        }

        try {
            this.connectionTestResult = yield this.fetch.post(`gateways/${this.args.resource.id}/test-credentials`, {}, { namespace: 'ledger/int/v1' });
        } catch (error) {
            this.connectionTestResult = {
                success: false,
                message: error.message ?? 'Gateway credential test failed.',
                metadata: {},
            };
            this.notifications.serverError(error);
        }
    }

    @action setResourceField(field, value) {
        const resolvedValue = this.resolveFieldValue(value);
        this.args.resource?.set?.(field, resolvedValue);
    }

    resolveFieldValue(value) {
        if (value instanceof Event) {
            return value.target?.value ?? value;
        }

        return value?.value ?? value;
    }

    @action goToStep(index) {
        this.activeStep = index;
    }

    @action nextStep() {
        this.activeStep = Math.min(this.activeStep + 1, this.setupSteps.length - 1);
    }

    @action previousStep() {
        this.activeStep = Math.max(this.activeStep - 1, 0);
    }

    @action copyWebhookUrl() {
        const url = this.args.resource?.system_webhook_url ?? this.args.resource?.webhook_url;
        if (!url) {
            return this.notifications.warning('No webhook URL is available yet.');
        }

        copyToClipboard(url)
            .then(() => this.notifications.success('Webhook URL copied.'))
            .catch(() => this.notifications.error('Unable to copy webhook URL.'));
    }

    driverCategory(code) {
        return (
            {
                taler: 'Wallet payment',
                stripe: 'Cards and wallets',
                cash: 'Manual payment',
                qpay: 'Regional payment',
            }[code] ?? 'Payment gateway'
        );
    }

    driverIcon(code) {
        return (
            {
                taler: 'wallet',
                stripe: 'credit-card',
                cash: 'money-bill-wave',
                qpay: 'qrcode',
            }[code] ?? 'plug'
        );
    }
}
