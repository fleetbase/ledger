import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class PaymentsWalletsIndexController extends Controller {
    @service walletActions;
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
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                action: this.walletActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.currency'),
                valuePath: 'currency',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.balance'),
                valuePath: 'balance',
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
        ];
    }

get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.walletActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
        ];
    }

get bulkActions() {
        const selected = this.tableContext.getSelectedRows();
        return [
            {
                label: this.intl.t('common.delete-selected-count', { count: selected.length }),
                class: 'text-red-500',
                fn: this.walletActions.bulkDelete,
            },
        ];
    }
}
