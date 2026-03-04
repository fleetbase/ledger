import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class BillingInvoiceTemplatesIndexEditController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;
    @service modalsManager;
    @service invoiceTemplateActions;
    @service intl;
    @service fetch;

    get template() {
        return this.model?.template;
    }

    get contextSchemas() {
        return this.model?.contextSchemas ?? [];
    }

    @action
    close() {
        const template = this.template;
        if (template?.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(template);
        }
        return this.hostRouter.transitionTo('console.ledger.billing.invoice-templates.index');
    }

    #confirmContinueWithUnsavedChanges(template, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: 'Invoice Template' }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                template.rollbackAttributes();
                await this.hostRouter.transitionTo('console.ledger.billing.invoice-templates.index');
            },
            ...options,
        });
    }

    @task *save(templateData) {
        try {
            const template = this.template;

            // PUT directly so the full payload — including the `queries` array —
            // reaches the backend in one request. The TemplateController.updateRecord()
            // override calls _syncQueries() after updating the template record.
            const { queries = [], ...attributes } = templateData;

            // Normalise temporary client-side UUIDs (prefixed _new_) to null so
            // the backend treats them as new records to create.
            const normalisedQueries = queries.map((q) => ({
                ...q,
                uuid: q.uuid?.startsWith('_new_') ? null : q.uuid ?? null,
            }));

            const response = yield this.fetch.put(`templates/${template.id}`, {
                template: { ...attributes, queries: normalisedQueries },
            });

            // Push the updated record back into the store so all observers
            // (including the builder's @template arg) reflect the latest state.
            this.store.push(this.store.normalize('template', response.template ?? response));

            this.notifications.success(`Invoice template "${template.name}" saved.`);
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action preview(templateData) {
        return this.invoiceTemplateActions.preview(templateData);
    }
}
