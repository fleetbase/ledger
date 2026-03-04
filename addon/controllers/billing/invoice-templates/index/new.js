import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class BillingInvoiceTemplatesIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;
    @service modalsManager;
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
        // For a brand-new record, treat it as dirty if the user has given it a
        // non-default name or added any canvas elements.
        const hasContent = template?.content?.length > 0;
        const hasName = template?.name && template.name !== 'Untitled Template';

        if (hasContent || hasName) {
            return this.#confirmContinueWithUnsavedChanges(template);
        }

        // Nothing meaningful was entered — discard the new record and navigate.
        if (template?.isNew) {
            template.rollbackAttributes();
        }
        return this.hostRouter.transitionTo('console.ledger.billing.invoice-templates.index');
    }

    #confirmContinueWithUnsavedChanges(template, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: 'Invoice Template' }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                if (template?.isNew) {
                    template.rollbackAttributes();
                }
                await this.hostRouter.transitionTo('console.ledger.billing.invoice-templates.index');
            },
            ...options,
        });
    }

    @task *save(templateData) {
        try {
            // POST directly so the full payload — including the `queries` array —
            // reaches the backend in one request. The TemplateController.createRecord()
            // override calls _syncQueries() after creating the template record.
            const { queries = [], ...attributes } = templateData;

            // Normalise temporary client-side UUIDs (prefixed _new_) to null so
            // the backend treats them as new records to create.
            const normalisedQueries = queries.map((q) => ({
                ...q,
                uuid: q.uuid?.startsWith('_new_') ? null : q.uuid ?? null,
            }));

            const response = yield this.fetch.post('templates', {
                template: { ...attributes, queries: normalisedQueries },
            });

            // Push the saved record into the Ember Data store so the rest of
            // the app (e.g. the index list) reflects the new template.
            const saved = this.store.push(this.store.normalize('template', response.template ?? response));

            this.notifications.success(`Invoice template "${saved.name}" created successfully.`);
            yield this.hostRouter.transitionTo('console.ledger.billing.invoice-templates.index');
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @task *preview(templateData) {
        try {
            // POST to core-api preview endpoint with the current unsaved template data
            const { html } = yield this.fetch.post('templates/preview', { template: templateData });
            // Open the preview HTML in a new tab
            const win = window.open('', '_blank');
            if (win) {
                win.document.write(html);
                win.document.close();
            }
        } catch (err) {
            this.notifications.serverError(err);
        }
    }
}
