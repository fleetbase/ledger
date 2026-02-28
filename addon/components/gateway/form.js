import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class GatewayFormComponent extends Component {
    @service fetch;

    @tracked selectedDriver = null;
    @tracked configSchema = [];
    @tracked configValues = {};
    @tracked name = '';
    @tracked environment = 'sandbox';
    @tracked isDefault = false;

    get availableDrivers() {
        return this.args.availableDrivers ?? [];
    }

    get environmentOptions() {
        return [
            { value: 'sandbox', label: 'Sandbox / Test' },
            { value: 'live', label: 'Live / Production' },
        ];
    }

    @task *loadSchema(driverCode) {
        const driver = this.availableDrivers.find((d) => d.code === driverCode);
        this.configSchema = driver?.config_schema ?? [];
        this.configValues = {};
        // Pre-fill defaults
        this.configSchema.forEach((field) => {
            if (field.default !== undefined) {
                this.configValues[field.key] = field.default;
            }
        });
    }

    @action selectDriver(driverCode) {
        this.selectedDriver = driverCode;
        this.loadSchema.perform(driverCode);
        this.notifyChange();
    }

    @action updateConfigField(key, value) {
        this.configValues = { ...this.configValues, [key]: value };
        this.notifyChange();
    }

    @action updateName(value) {
        this.name = value;
        this.notifyChange();
    }

    @action updateEnvironment(value) {
        this.environment = value;
        this.notifyChange();
    }

    @action toggleDefault(value) {
        this.isDefault = value;
        this.notifyChange();
    }

    notifyChange() {
        if (this.args.onChange) {
            this.args.onChange({
                name: this.name,
                driver: this.selectedDriver,
                environment: this.environment,
                is_default: this.isDefault,
                config: this.configValues,
            });
        }
    }
}
