import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class BillingInvoicesIndexNewRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('ledger create invoice')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.ledger.billing.invoices.index');
        }
    }
}
