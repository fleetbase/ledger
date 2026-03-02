import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class BillingInvoicesIndexController extends Controller {
    @service invoiceActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'status', 'customer_uuid'];
    @tracked page = 1;
    @tracked limit = null;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked status = null;
    @tracked customer_uuid = null;
    @tracked table = null;
get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'table/cell/anchor',
                action: this.invoiceActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'public_id',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'status',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.amount'),
                valuePath: 'amount',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.due-date'),
                valuePath: 'due_date',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                resizable: true,
                sortable: true,
            },
        ];
    }

get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.invoiceActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.invoiceActions.transition.create,
            },
        ];
    }

get bulkActions() {
        const selected = this.tableContext.getSelectedRows();
        return [
            {
                label: this.intl.t('common.delete-selected-count', { count: selected.length }),
                class: 'text-red-500',
                fn: this.invoiceActions.bulkDelete,
            },
        ];
    }
}
