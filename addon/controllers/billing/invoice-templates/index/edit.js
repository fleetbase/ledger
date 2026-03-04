import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class BillingInvoiceTemplatesIndexEditController extends Controller {
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
        this.isSaving = true;
        try {
            const template = this.template;
            Object.assign(template, templateData);
            yield template.save();
            this.notifications.success(`Invoice template "${template.name}" saved.`);
        } catch (err) {
            this.notifications.serverError(err);
        } finally {
            this.isSaving = false;
        }
    }

    @task *preview(templateData) {
        this.isPreviewing = true;
        try {
            const html = yield this.fetch.post(
                `templates/${this.template.id}/preview`,
                { template: templateData },
                { namespace: '~api/v1' }
            );
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
