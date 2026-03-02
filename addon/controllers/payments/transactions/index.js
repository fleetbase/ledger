import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class PaymentsTransactionsIndexController extends Controller {
    @service transactionActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'type', 'status', 'direction', 'currency', 'gateway', 'period'];
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked type = null;
    @tracked status = null;
    @tracked direction = null;
    @tracked currency = null;
    @tracked gateway = null;
    @tracked period = null;
    @tracked table = null;

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.transactionActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
        ];
    }

    get bulkActions() {
        return [];
    }

    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'table/cell/anchor',
                action: this.transactionActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'public_id',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'type',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.direction'),
                valuePath: 'direction',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'direction',
                filterComponent: 'filter/select',
                filterOptions: [
                    { label: 'Credit', value: 'credit' },
                    { label: 'Debit', value: 'debit' },
                ],
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'status',
                filterComponent: 'filter/select',
                filterOptions: [
                    { label: 'Pending', value: 'pending' },
                    { label: 'Succeeded', value: 'succeeded' },
                    { label: 'Failed', value: 'failed' },
                    { label: 'Refunded', value: 'refunded' },
                    { label: 'Voided', value: 'voided' },
                    { label: 'Reversed', value: 'reversed' },
                ],
            },
            {
                label: this.intl.t('column.amount'),
                valuePath: 'amount',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.currency'),
                valuePath: 'currency',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'currency',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.gateway'),
                valuePath: 'gateway',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'gateway',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.reference'),
                valuePath: 'reference',
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
}
