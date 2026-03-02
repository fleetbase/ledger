import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class BillingInvoicesIndexEditController extends Controller {
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

    @task *save(invoice) {
        try {
            yield invoice.save();
            this.events.trackResourceUpdated(invoice);
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.ledger.billing.invoices.index.details', invoice);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: 'Invoice',
                    resourceName: invoice.number,
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
        return this.hostRouter.transitionTo('console.ledger.billing.invoices.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model, {
                confirm: async () => {
                    this.model.rollbackAttributes();
                    await this.hostRouter.transitionTo('console.ledger.billing.invoices.index.details', this.model);
                },
            });
        }
        return this.hostRouter.transitionTo('console.ledger.billing.invoices.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(invoice, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: 'Invoice' }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                invoice.rollbackAttributes();
                await this.hostRouter.transitionTo('console.ledger.billing.invoices.index.details', invoice);
            },
            ...options,
        });
    }
}
