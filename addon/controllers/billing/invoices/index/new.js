import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class BillingInvoicesIndexNewController extends Controller {
    @service store;
    @service fetch;
    @service hostRouter;
    @service notifications;
    @service intl;
    @service events;
    @service currentUser;

    @tracked overlay;
    @tracked invoice = null;

    formRef = null;

    get actionButtons() {
        return [];
    }

    /**
     * Load invoice settings from the backend and create a new invoice record
     * pre-populated with the company's configured defaults (currency, notes,
     * terms, due date offset). Falls back to sensible hard-coded values when
     * settings have not yet been saved.
     */
    @task *loadDefaults() {
        let defaults = {
            status: 'draft',
            currency: this._companyCurrency(),
        };

        try {
            const { invoiceSettings } = yield this.fetch.get('settings/invoice-settings', {}, { namespace: 'ledger/int/v1' });
            if (invoiceSettings) {
                // Currency — use the explicitly saved setting, otherwise fall
                // back to the organisation's currency.
                if (invoiceSettings.default_currency) {
                    defaults.currency = invoiceSettings.default_currency;
                }

                // Notes and terms — pre-fill the form fields.
                if (invoiceSettings.default_notes) {
                    defaults.notes = invoiceSettings.default_notes;
                }
                if (invoiceSettings.default_terms) {
                    defaults.terms = invoiceSettings.default_terms;
                }

                // Due date — calculate from today + offset only when the user
                // has explicitly saved a non-zero value in Invoice Settings.
                // A null/undefined/0 setting means "no default due date" so we
                // leave the field empty rather than silently pre-filling 30 days.
                const offset = invoiceSettings.due_date_offset_days;
                if (offset != null && Number(offset) > 0) {
                    const due = new Date();
                    due.setDate(due.getDate() + Number(offset));
                    defaults.due_date = due;
                }
            }
        } catch {
            // Settings endpoint not yet reachable — silently use defaults.
        }

        this.invoice = this.store.createRecord('ledger-invoice', defaults);
    }

    @action registerFormRef(ref) {
        this.formRef = ref;
    }

    @task *save(invoice) {
        try {
            if (this.formRef) {
                this.formRef.syncItemsToInvoice(invoice);
            }
            yield invoice.save();
            if (this.formRef) {
                this.formRef.resetItems(invoice);
            }
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
        this.loadDefaults.perform();
        this.formRef = null;
    }

    /**
     * Safely read the organisation's currency from the already-loaded
     * organisations list. Does not call getCompany() to avoid the
     * store.peekRecord crash that occurs before the user session is fully loaded.
     */
    _companyCurrency() {
        const companyId = this.currentUser.companyId;
        if (!companyId) return 'USD';
        const org = (this.currentUser.organizations || []).find(
            (o) => o.uuid === companyId || o.id === companyId
        );
        return org?.currency ?? 'USD';
    }
}
