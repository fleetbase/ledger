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
        { label: 'Status', valuePath: 'status_label', width: '90px', cellComponent: 'table/cell/status' },
    ];

    get actionButtons() {
        return [{ label: 'Add Gateway', icon: 'plus', type: 'primary', onClick: this.addGateway }];
    }

    @task({ restartable: true }) *search(query) {
        this.query = query;
    }

    @action addGateway() {
        this.hostRouter.transitionTo('console.ledger.payments.gateways.index.new');
    }

    @action viewGateway(gateway) {
        this.hostRouter.transitionTo('console.ledger.payments.gateways.index.details', gateway.id);
    }

    @action editGateway(gateway) {
        this.hostRouter.transitionTo('console.ledger.payments.gateways.index.edit', gateway);
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
