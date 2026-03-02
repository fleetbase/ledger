import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = {
    type: 'standard',
    currency: 'USD',
    is_system_entry: false,
};

export default class AccountingJournalIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;
    @service intl;
    @service events;

    @tracked overlay;
    @tracked journalEntry = this.store.createRecord('ledger-journal', DEFAULT_PROPERTIES);

    get actionButtons() {
        return [];
    }

    @task *save(journalEntry) {
        try {
            yield journalEntry.save();
            this.events.trackResourceCreated(journalEntry);
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.ledger.accounting.journal.index.details', journalEntry);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: 'Journal Entry',
                    resourceName: journalEntry.number,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.journalEntry = this.store.createRecord('ledger-journal', DEFAULT_PROPERTIES);
    }
}
