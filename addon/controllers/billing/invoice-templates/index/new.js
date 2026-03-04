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

    @tracked isSaving = false;
    @tracked isPreviewing = false;

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
        this.isSaving = true;
        try {
            const template = this.template;
            // Apply the builder's current state onto the Ember Data record
            Object.assign(template, templateData);
            yield template.save();
            this.notifications.success(`Invoice template "${template.name}" created successfully.`);
            yield this.hostRouter.transitionTo('console.ledger.billing.invoice-templates.index.edit', template.id);
        } catch (err) {
            this.notifications.serverError(err);
        } finally {
            this.isSaving = false;
        }
    }

    @task *preview(templateData) {
        this.isPreviewing = true;
        try {
            // POST to core-api preview endpoint with the current unsaved template data
            const html = yield this.fetch.post(
                'templates/preview',
                { template: templateData },
                { namespace: '~api/v1' }
            );
            // Open the preview HTML in a new tab
            const win = window.open('', '_blank');
            if (win) {
                win.document.write(html);
                win.document.close();
            }
        } catch (err) {
            this.notifications.serverError(err);
        } finally {
            this.isPreviewing = false;
        }
    }
}
