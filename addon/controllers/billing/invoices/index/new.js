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

    /**
     * Reference to the Invoice::Form component instance.
     * Set via @registerRef={{fn (mut this.formRef)}} in the route template.
     * Used to call formRef.syncItemsToInvoice(invoice) before saving.
     */
    @tracked formRef = null;

    @tracked invoice = this.store.createRecord('ledger-invoice', DEFAULT_PROPERTIES);

    get actionButtons() {
        return [];
    }

    @task *save(invoice) {
        try {
            // Sync line items from the form component into the invoice record
            // before persisting.  This must happen here (not in the form) because
            // Layout::Resource::Panel calls saveTask.perform(@resource) directly.
            if (this.formRef) {
                this.formRef.syncItemsToInvoice(invoice);
            }

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
        this.formRef = null;
    }
}
