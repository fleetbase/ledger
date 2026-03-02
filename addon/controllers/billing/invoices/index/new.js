import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = {
    status: 'draft',
    currency: 'USD',
};

export default class BillingInvoicesIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;
    @service intl;
    @service events;

    @tracked overlay;
    @tracked invoice = this.store.createRecord('ledger-invoice', DEFAULT_PROPERTIES);

    get actionButtons() {
        return [];
    }

    @task *save(invoice) {
        try {
            yield invoice.save();
            this.events.trackResourceCreated(invoice);
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.ledger.billing.invoices.index.details', invoice);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: 'Invoice',
                    resourceName: invoice.number,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.invoice = this.store.createRecord('ledger-invoice', DEFAULT_PROPERTIES);
    }
}
