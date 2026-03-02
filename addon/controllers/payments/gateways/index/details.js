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
            { label: 'Edit', icon: 'pencil', onClick: this.editGateway },
            { label: 'Delete', icon: 'trash', type: 'danger', onClick: this.deleteGateway },
        ];
    }

    @action editGateway() {
        const gateway = this.model;
        this.modalsManager.show('modals/gateway-form', { gateway });
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
                    this.hostRouter.transitionTo('payments.gateways.index');
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
