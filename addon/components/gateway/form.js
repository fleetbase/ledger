import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class GatewayFormComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked availableDrivers = [];
    @tracked configSchema = [];
    @tracked configValues = {};

    constructor() {
        super(...arguments);
        this.loadDrivers.perform();
    }

    @task *loadDrivers() {
        try {
            const response = yield this.fetch.get('gateways/drivers', {}, { namespace: 'ledger/int/v1' });
            // The endpoint returns { status: 'ok', drivers: [...] }
            this.availableDrivers = response?.drivers ?? response ?? [];

            // If the resource already has a driver selected, load its schema
            if (this.args.resource?.driver) {
                yield this.loadSchema.perform(this.args.resource.driver);
            }
        } catch (err) {
            this.notifications.warning('Could not load available payment drivers.');
        }
    }

    @task *loadSchema(driverCode) {
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
        this.args.resource.driver = driver.code;
        // Sync the driver's capabilities to the resource so they are persisted
        if (driver.capabilities) {
            this.args.resource.capabilities = driver.capabilities;
        }
        this.loadSchema.perform(driver.code);
    }

    @action updateConfigField(key, value) {
        // When bound via {{on "input" (fn this.updateConfigField field.key)}} the second
        // argument is a DOM InputEvent, not the raw string value.  Extract the actual
        // value from event.target.value in that case so credentials are stored correctly.
        const resolvedValue = value instanceof Event ? value.target?.value ?? value : value;
        this.configValues = { ...this.configValues, [key]: resolvedValue };
        // Persist config back to the resource
        this.args.resource.config = { ...this.configValues };
    }
}
