import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class PaymentsGatewaysIndexController extends Controller {
    @service gatewayActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'type'];
    @tracked page = 1;
    @tracked limit = null;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked type = null;
    @tracked table = null;
    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                action: this.gatewayActions.transition.view,
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
                label: this.intl.t('column.sandbox'),
                valuePath: 'sandbox',
                cellComponent: 'table/cell/boolean',
                resizable: true,
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
                onClick: this.gatewayActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.gatewayActions.transition.create,
            },
        ];
    }

    get bulkActions() {
        const selected = this.tableContext.getSelectedRows();
        return [
            {
                label: this.intl.t('common.delete-selected-count', { count: selected.length }),
                class: 'text-red-500',
                fn: this.gatewayActions.bulkDelete,
            },
        ];
    }
}
