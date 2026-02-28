import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class SettingsGatewaysIndexController extends Controller {
    @service hostRouter;
    @service notifications;
    @service modalsManager;
    @service fetch;

    @tracked query = null;
    @tracked table = null;
    @tracked availableDrivers = [];

    columns = [
        { label: 'Name', valuePath: 'name', width: '180px' },
        { label: 'Driver', valuePath: 'driver_label', width: '120px' },
        { label: 'Environment', valuePath: 'environment', width: '100px' },
        { label: 'Default', valuePath: 'is_default', width: '80px', component: 'table/cell/boolean' },
        { label: 'Status', valuePath: 'status_label', width: '90px', component: 'table/cell/status' },
    ];

    get actionButtons() {
        return [
            { label: 'Add Gateway', icon: 'plus', type: 'primary', onClick: this.addGateway },
        ];
    }

    @task({ restartable: true }) *search(query) {
        this.query = query;
    }

    @task *loadDrivers() {
        try {
            const result = yield this.fetch.get('ledger/int/v1/gateways/drivers');
            this.availableDrivers = result?.data ?? [];
        } catch {
            this.availableDrivers = [];
        }
    }

    @action addGateway() {
        this.loadDrivers.perform().then(() => {
            this.modalsManager.show('modals/gateway-form', {
                title: 'Add Payment Gateway',
                availableDrivers: this.availableDrivers,
                confirm: async (modal) => {
                    modal.startLoading();
                    try {
                        const gateway = modal.getOption('gateway');
                        const record = this.store.createRecord('gateway', gateway);
                        await record.save();
                        this.notifications.success('Gateway added.');
                        this.hostRouter.refresh();
                        modal.done();
                    } catch (error) {
                        this.notifications.serverError(error);
                        modal.stopLoading();
                    }
                },
            });
        });
    }

    @action viewGateway(gateway) {
        this.hostRouter.transitionTo('console.ledger.settings.gateways.index.details', gateway.public_id);
    }

    @action async deleteGateway(gateway) {
        this.modalsManager.confirm({
            title: 'Remove Payment Gateway?',
            body: `This will remove "${gateway.name}". Any active integrations using this gateway will stop working.`,
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await gateway.destroyRecord();
                    this.notifications.success('Gateway removed.');
                    this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action reload() {
        return this.hostRouter.refresh();
    }
}
