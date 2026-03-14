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

    setupController(controller) {
        super.setupController(...arguments);
        // Load invoice settings and create a pre-populated new invoice record.
        // This ensures the form shows the correct currency, notes, terms and
        // due date from the company's Invoice Settings on every visit.
        controller.loadDefaults.perform();
    }
}
