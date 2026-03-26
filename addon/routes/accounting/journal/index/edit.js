import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class AccountingJournalIndexEditRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    @action error(error) {
        this.notifications.serverError(error);
        if (typeof error.message === 'string' && error.message.endsWith('not found')) {
            return this.hostRouter.transitionTo('console.ledger.accounting.journal.index');
        }
    }

    beforeModel() {
        if (this.abilities.cannot('ledger update journal-entry')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.ledger.accounting.journal.index');
        }
    }

    model({ id }) {
        return this.store.findRecord('ledger-journal', id);
    }
}
