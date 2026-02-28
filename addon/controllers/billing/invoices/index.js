import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class BillingInvoicesIndexController extends Controller {
    @service store;
    @service notifications;
    @service modalsManager;
    @service intl;
    @service hostRouter;
    @service crud;

    queryParams = ['page', 'limit', 'sort', 'query', 'status'];

    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked status = null;
    @tracked table = null;

    columns = [
        {
            label: 'Invoice #',
            valuePath: 'number',
            width: '140px',
            sortable: true,
        },
        {
            label: 'Customer',
            valuePath: 'customer_name',
            width: '180px',
        },
        {
            label: 'Due Date',
            valuePath: 'due_at',
            width: '120px',
            sortable: true,
            component: 'table/cell/date',
        },
        {
            label: 'Total',
            valuePath: 'formatted_total',
            width: '120px',
            sortable: true,
        },
        {
            label: 'Balance',
            valuePath: 'formatted_balance',
            width: '120px',
        },
        {
            label: 'Status',
            valuePath: 'status',
            width: '100px',
            component: 'table/cell/status',
        },
    ];

    get actionButtons() {
        return [
            {
                label: 'New Invoice',
                icon: 'plus',
                type: 'primary',
                onClick: this.createInvoice,
            },
        ];
    }

    get bulkActions() {
        return [
            {
                label: 'Delete Selected',
                icon: 'trash',
                fn: this.bulkDeleteInvoices,
            },
        ];
    }

    @task({ restartable: true }) *search(query) {
        this.query = query;
    }

    @action createInvoice() {
        this.hostRouter.transitionTo('console.ledger.billing.invoices.index.new');
    }

    @action viewInvoice(invoice) {
        this.hostRouter.transitionTo('console.ledger.billing.invoices.index.details', invoice.id);
    }

    @action async deleteInvoice(invoice) {
        this.modalsManager.confirm({
            title: `Delete Invoice ${invoice.number}?`,
            body: 'This action cannot be undone.',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await invoice.destroyRecord();
                    this.notifications.success('Invoice deleted.');
                    this.hostRouter.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action async bulkDeleteInvoices(selected) {
        this.modalsManager.confirm({
            title: `Delete ${selected.length} invoice(s)?`,
            body: 'This action cannot be undone.',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await Promise.all(selected.map((i) => i.destroyRecord()));
                    this.notifications.success(`${selected.length} invoice(s) deleted.`);
                    this.hostRouter.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action reload() {
        return this.hostRouter.refresh();
    }
}
