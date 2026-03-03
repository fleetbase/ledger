import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class PaymentsGatewaysIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service hostRouter;

    @tracked overlay = null;

    get tabs() {
        return [
            { label: 'Overview', route: 'payments.gateways.index.details.index' },
            { label: 'Webhooks', route: 'payments.gateways.index.details.webhooks' },
        ];
    }

    get actionButtons() {
        return [
            { label: 'Edit', icon: 'pencil', helpText: 'Edit this payment gateway configuration.', onClick: this.editGateway },
            { label: 'Delete', icon: 'trash', type: 'danger', helpText: 'Permanently remove this payment gateway. This cannot be undone.', onClick: this.deleteGateway },
        ];
    }

    @action editGateway() {
        const gateway = this.model;
        this.hostRouter.transitionTo('console.ledger.payments.gateways.index.edit', gateway);
    }

    @action async deleteGateway() {
        const gateway = this.model;
        this.modalsManager.confirm({
            title: `Delete Gateway ${gateway.name}?`,
            body: 'This action cannot be undone.',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await gateway.destroyRecord();
                    this.notifications.success('Gateway deleted.');
                    this.hostRouter.transitionTo('console.ledger.payments.gateways.index');
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
