import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class BillingInvoiceTemplatesIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;
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
        // If the template has never been saved and has unsaved changes, confirm
        // before discarding. For a brand-new record, hasDirtyAttributes is true
        // as soon as any field is set (including the defaults set in the route).
        // We only prompt if the user has actually changed something meaningful
        // (i.e. the name is not the default placeholder).
        const hasContent = template?.content?.length > 0;
        const hasName = template?.name && template.name !== 'Untitled Template';

        if (hasContent || hasName) {
            if (!window.confirm('You have unsaved changes. Leave without saving?')) {
                return;
            }
        }

        // Roll back the unsaved record so it doesn't linger in the store
        if (template?.isNew) {
            template.rollbackAttributes();
        }

        this.hostRouter.transitionTo('console.ledger.billing.invoice-templates.index');
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
