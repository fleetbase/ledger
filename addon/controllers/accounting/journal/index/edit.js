import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class AccountingJournalIndexEditController extends Controller {
    @service hostRouter;
    @service notifications;
    @service modalsManager;
    @service intl;
    @service events;

    @tracked overlay;
    @tracked actionButtons = [
        {
            icon: 'eye',
            fn: this.view,
        },
    ];

    @task *save(journalEntry) {
        try {
            yield journalEntry.save();
            this.events.trackResourceUpdated(journalEntry);
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.ledger.accounting.journal.index.details', journalEntry);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: 'Journal Entry',
                    resourceName: journalEntry.number,
                })
            );
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action cancel() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }
        return this.hostRouter.transitionTo('console.ledger.accounting.journal.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model, {
                confirm: async () => {
                    this.model.rollbackAttributes();
                    await this.hostRouter.transitionTo('console.ledger.accounting.journal.index.details', this.model);
                },
            });
        }
        return this.hostRouter.transitionTo('console.ledger.accounting.journal.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(journalEntry, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: 'Journal Entry' }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                journalEntry.rollbackAttributes();
                await this.hostRouter.transitionTo('console.ledger.accounting.journal.index.details', journalEntry);
            },
            ...options,
        });
    }
}
