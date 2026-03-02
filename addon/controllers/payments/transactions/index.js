import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class PaymentsTransactionsIndexController extends Controller {
    @service transactionActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'type', 'status'];
    @tracked page = 1;
    @tracked limit = null;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked type = null;
    @tracked status = null;
    @tracked table = null;
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
                label: this.intl.t('column.amount'),
                valuePath: 'amount',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
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
                onClick: this.transactionActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
        ];
    }

    get bulkActions() {
        return [];
    }
}
