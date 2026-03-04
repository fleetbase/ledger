import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class BillingInvoiceTemplatesIndexController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;
    @service intl;
    @service fetch;

    @tracked queryParams = ['page', 'limit', 'sort', 'query'];
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked table = null;

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: () => this.hostRouter.refresh(),
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.createTemplate,
            },
        ];
    }

    get bulkActions() {
        return [
            {
                label: 'Delete Selected',
                class: 'text-red-500',
                fn: this.bulkDelete,
            },
        ];
    }

    get columns() {
        return [
            {
                sticky: true,
                label: 'Name',
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                action: this.editTemplate,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            {
                label: 'Description',
                valuePath: 'description',
                resizable: true,
            },
            {
                label: 'Orientation',
                valuePath: 'orientation',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Default',
                valuePath: 'is_default',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Created',
                valuePath: 'createdAt',
                resizable: true,
                sortable: true,
            },
        ];
    }

    @action createTemplate() {
        this.hostRouter.transitionTo('console.ledger.billing.invoice-templates.index.new');
    }

    @action editTemplate(template) {
        this.hostRouter.transitionTo('console.ledger.billing.invoice-templates.index.edit', template.id);
    }

    @task *deleteTemplate(template) {
        try {
            yield template.destroyRecord();
            this.notifications.success('Invoice template deleted successfully.');
            yield this.hostRouter.refresh();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @task *bulkDelete() {
        const selected = this.table?.selectedRows ?? [];
        try {
            yield Promise.all(selected.map((row) => row.content.destroyRecord()));
            this.notifications.success(`${selected.length} invoice template(s) deleted.`);
            yield this.hostRouter.refresh();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }
}
