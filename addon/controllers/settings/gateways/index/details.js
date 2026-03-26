import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class SettingsGatewaysIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service hostRouter;
    @service fetch;

    get tabs() {
        return [
            { label: 'Configuration', route: 'console.ledger.settings.gateways.index.details.index' },
            { label: 'Webhook Events', route: 'console.ledger.settings.gateways.index.details.webhooks' },
        ];
    }

    get actionButtons() {
        return [
            { label: 'Edit', icon: 'pencil', type: 'default', onClick: this.editGateway },
            { label: 'Remove', icon: 'trash', type: 'danger', onClick: this.deleteGateway },
        ];
    }

    @action editGateway() {
        this.hostRouter.transitionTo('console.ledger.payments.gateways.index.edit', this.model);
    }

    @action async deleteGateway() {
        this.modalsManager.confirm({
            title: 'Remove Payment Gateway?',
            body: `This will remove "${this.model.name}". Any active integrations using this gateway will stop working.`,
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.model.destroyRecord();
                    this.notifications.success('Gateway removed.');
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
