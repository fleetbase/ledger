import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class AccountingJournalIndexDetailsRoute extends Route {
    @service store;

    model({ id }) {
        return this.store.findRecord('journal', id);
    }
}
