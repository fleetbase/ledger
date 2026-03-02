import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class BillingInvoicesIndexEditRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    @action error(error) {
        this.notifications.serverError(error);
        if (typeof error.message === 'string' && error.message.endsWith('not found')) {
            return this.hostRouter.transitionTo('console.ledger.billing.invoices.index');
        }
    }

    beforeModel() {
        if (this.abilities.cannot('ledger update invoice')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.ledger.billing.invoices.index');
        }
    }

    model({ id }) {
        return this.store.findRecord('ledger-invoice', id);
    }
}
