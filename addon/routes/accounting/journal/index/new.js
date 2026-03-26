import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class AccountingJournalIndexNewRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('ledger create journal-entry')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.ledger.accounting.journal.index');
        }
    }
}
