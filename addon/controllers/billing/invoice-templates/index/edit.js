import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

export default class BillingInvoiceTemplatesIndexEditController extends Controller {
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
