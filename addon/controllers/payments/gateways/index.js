import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class PaymentsGatewaysIndexController extends Controller {
    @service gatewayActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'driver', 'environment', 'status'];
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked driver = null;
    @tracked environment = null;
    @tracked status = null;
    @tracked table = null;

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
                label: this.intl.t('column.driver'),
                valuePath: 'driver',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'driver',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.environment'),
                valuePath: 'environment',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'environment',
                filterComponent: 'filter/select',
                filterOptions: [
                    { label: 'Live', value: 'live' },
                    { label: 'Sandbox', value: 'sandbox' },
                ],
            },
            {
                label: this.intl.t('column.default'),
                valuePath: 'is_default',
                cellComponent: 'table/cell/boolean',
                resizable: true,
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
                    { label: 'Active', value: 'active' },
                    { label: 'Inactive', value: 'inactive' },
                ],
            },
        ];
    }
}
