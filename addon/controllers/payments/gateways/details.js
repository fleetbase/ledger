import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class PaymentsGatewaysDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service hostRouter;

    @tracked overlay = null;

    get tabs() {
        const currentRouteName = this.hostRouter.currentRouteName;

        return [
            { label: 'Overview', route: 'payments.gateways.details.index' },
            { label: 'Setup', route: 'payments.gateways.details.setup' },
            { label: 'Diagnostics', route: 'payments.gateways.details.diagnostics' },
            { label: 'Transactions', route: 'payments.gateways.details.webhooks' },
        ].map((tab) => ({
            ...tab,
            active: currentRouteName === tab.route || currentRouteName?.endsWith(`.${tab.route}`),
        }));
    }

    get isTransactionsTab() {
        const currentRouteName = this.hostRouter.currentRouteName;

        return currentRouteName === 'payments.gateways.details.webhooks' || currentRouteName?.endsWith('.payments.gateways.details.webhooks');
    }

    get actionButtons() {
        return [
            { label: 'Edit', icon: 'pencil', helpText: 'Edit this payment gateway configuration.', onClick: this.editGateway },
            { label: 'Delete', icon: 'trash', type: 'danger', helpText: 'Permanently remove this payment gateway. This cannot be undone.', onClick: this.deleteGateway },
        ];
    }

    get driverIcon() {
        return (
            {
                taler: 'wallet',
                stripe: 'credit-card',
                cash: 'money-bill-wave',
                qpay: 'qrcode',
            }[this.model?.driver] ?? 'plug'
        );
    }

    get statusLabel() {
        return this.model?.status === 'active' ? 'Active' : 'Inactive';
    }

    @action editGateway() {
        const gateway = this.model;
        this.hostRouter.transitionTo('console.ledger.payments.gateways.edit', gateway);
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
