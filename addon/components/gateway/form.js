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
    }

    @action selectDriver(driver) {
        this.args.resource.driver = driver.code;
        this.loadSchema.perform(driver.code);
    }

    @action updateConfigField(key, value) {
        this.configValues = { ...this.configValues, [key]: value };
        // Persist config back to the resource
        this.args.resource.config = { ...this.configValues };
    }
}
