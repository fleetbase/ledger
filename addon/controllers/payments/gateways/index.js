import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

export default class PaymentsGatewaysIndexController extends Controller {
    @service gatewayActions;
    @service tableContext;
    @service intl;
    @service fetch;
    @service notifications;

    @tracked queryParams = this.gatewayActions.queryParamsFor(['page', 'limit', 'sort', 'query', 'public_id', 'name', 'code', 'driver', 'environment', 'status', 'created_at', 'updated_at']);
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked public_id = null;
    @tracked name = null;
    @tracked code = null;
    @tracked driver = null;
    @tracked environment = null;
    @tracked status = null;
    @tracked created_at = null;
    @tracked updated_at = null;
    @tracked table = null;
    @tracked drivers = [];
    @tracked summary = null;

    constructor() {
        super(...arguments);
        this.loadGatewayHub.perform();
    }

    get actionButtons() {
        return [
            {
                id: 'refresh',
                icon: 'refresh',
                onClick: this.gatewayActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                id: 'create',
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
                id: 'bulk-delete',
                label: this.intl.t('common.delete-selected-count', { count: selected.length }),
                class: 'text-red-500',
                fn: this.gatewayActions.bulkDelete,
            },
        ];
    }

    get columns() {
        // Gateway::Hub renders its own column picker, so registered columns are merged here once.
        return this.gatewayActions.mergeRegisteredColumns(
            [
                // ── Default visible columns ──────────────────────────────────────
                {
                    id: 'name',
                    sticky: true,
                    label: this.intl.t('column.name'),
                    valuePath: 'name',
                    cellComponent: 'table/cell/gateway-provider',
                    action: this.gatewayActions.transition.view,
                    width: 320,
                    resizable: true,
                    sortable: true,
                    filterable: true,
                    filterParam: 'name',
                    filterComponent: 'filter/string',
                },
                {
                    id: 'driver',
                    label: this.intl.t('column.driver'),
                    valuePath: 'driver',
                    cellComponent: 'table/cell/base',
                    humanize: true,
                    width: 130,
                    resizable: true,
                    sortable: true,
                    filterable: true,
                    filterParam: 'driver',
                    filterComponent: 'filter/multi-option',
                    filterOptions: ['taler', 'stripe', 'cash', 'qpay'],
                },
                {
                    id: 'environment',
                    label: this.intl.t('column.environment'),
                    valuePath: 'environment',
                    cellComponent: 'table/cell/base',
                    humanize: true,
                    width: 110,
                    resizable: true,
                    sortable: true,
                    filterable: true,
                    filterParam: 'environment',
                    filterComponent: 'filter/select',
                    filterOptionLabel: 'label',
                    filterOptionValue: 'value',
                    filterOptions: [
                        { label: 'Live', value: 'live' },
                        { label: 'Sandbox', value: 'sandbox' },
                    ],
                },
                {
                    id: 'status',
                    label: this.intl.t('column.status'),
                    valuePath: 'status',
                    cellComponent: 'table/cell/status',
                    width: 100,
                    resizable: true,
                    sortable: true,
                    filterable: true,
                    filterParam: 'status',
                    filterComponent: 'filter/select',
                    filterOptionLabel: 'label',
                    filterOptionValue: 'value',
                    filterOptions: [
                        { label: 'Active', value: 'active' },
                        { label: 'Inactive', value: 'inactive' },
                    ],
                },
                {
                    id: 'created-at',
                    label: this.intl.t('column.created-at'),
                    valuePath: 'createdAt',
                    sortParam: 'created_at',
                    filterParam: 'created_at',
                    width: 150,
                    resizable: true,
                    sortable: true,
                    filterable: true,
                    filterComponent: 'filter/date',
                },
                // ── Hidden / toggleable columns ──────────────────────────────────
                {
                    id: 'public-id',
                    label: this.intl.t('column.id'),
                    valuePath: 'public_id',
                    width: 120,
                    hidden: true,
                    resizable: true,
                    sortable: false,
                    filterable: true,
                    filterParam: 'public_id',
                    filterComponent: 'filter/string',
                },
                {
                    id: 'code',
                    label: this.intl.t('column.code'),
                    valuePath: 'code',
                    hidden: true,
                    resizable: true,
                    sortable: true,
                    filterable: true,
                    filterParam: 'code',
                    filterComponent: 'filter/string',
                },
                {
                    id: 'description',
                    label: this.intl.t('column.description'),
                    valuePath: 'description',
                    hidden: true,
                    resizable: true,
                    sortable: false,
                    filterable: true,
                    filterParam: 'description',
                    filterComponent: 'filter/string',
                },
                {
                    id: 'updated-at',
                    label: this.intl.t('column.updated-at'),
                    valuePath: 'updatedAt',
                    sortParam: 'updated_at',
                    filterParam: 'updated_at',
                    hidden: true,
                    resizable: true,
                    sortable: true,
                    filterable: true,
                    filterComponent: 'filter/date',
                },
                // ── Row actions dropdown ─────────────────────────────────────────
                {
                    id: 'row-actions',
                    label: '',
                    cellComponent: 'table/cell/dropdown',
                    ddButtonText: false,
                    ddButtonIcon: 'ellipsis-h',
                    ddButtonIconPrefix: 'fas',
                    ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.gateway') }),
                    cellClassNames: 'overflow-visible',
                    wrapperClass: 'flex items-center justify-end mx-2',
                    sticky: 'right',
                    width: 60,
                    actions: [
                        {
                            id: 'view',
                            label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.gateway') }),
                            icon: 'eye',
                            fn: this.gatewayActions.transition.view,
                            permission: 'ledger view gateway',
                        },
                        {
                            id: 'edit',
                            label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.gateway') }),
                            icon: 'pencil',
                            fn: this.gatewayActions.edit,
                            permission: 'ledger update gateway',
                        },
                        {
                            id: 'delete',
                            label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.gateway') }),
                            icon: 'trash',
                            fn: this.gatewayActions.delete,
                            className: 'text-red-500 hover:text-red-700',
                            permission: 'ledger delete gateway',
                        },
                    ],
                    sortable: false,
                    filterable: false,
                    resizable: false,
                    searchable: false,
                },
            ],
            { controller: this }
        );
    }

    @task({ restartable: true })
    *loadGatewayHub() {
        try {
            const [drivers, summary] = yield Promise.all([
                this.fetch.get('gateways/drivers', {}, { namespace: 'ledger/int/v1' }),
                this.fetch.get('gateways/summary', {}, { namespace: 'ledger/int/v1' }),
            ]);
            this.drivers = drivers?.drivers ?? [];
            this.summary = summary;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
