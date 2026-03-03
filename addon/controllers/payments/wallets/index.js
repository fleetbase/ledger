import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class PaymentsWalletsIndexController extends Controller {
    @service walletActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'public_id', 'name', 'type', 'status', 'currency', 'is_frozen', 'subject', 'subject_type', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked public_id = null;
    @tracked name = null;
    @tracked type = null;
    @tracked status = null;
    @tracked currency = null;
    @tracked is_frozen = null;
    @tracked subject = null;
    @tracked subject_type = null;
    @tracked created_at = null;
    @tracked updated_at = null;
    @tracked table = null;

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

    get columns() {
        return [
            // ── Owner (subject) column ───────────────────────────────────────
            {
                sticky: true,
                label: this.intl.t('column.owner'),
                valuePath: 'owner_name',
                cellComponent: 'table/cell/anchor',
                action: this.walletActions.transition.view,
                width: 200,
                resizable: true,
                sortable: false,
                filterable: true,
                filterParam: 'subject',
                filterComponent: 'filter/string',
            },
            // ── Default visible columns ──────────────────────────────────────
            {
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                action: this.walletActions.transition.view,
                width: 160,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
                cellComponent: 'table/cell/base',
                humanize: true,
                width: 110,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'type',
                filterComponent: 'filter/select',
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                filterOptions: [
                    { label: 'Company', value: 'company' },
                    { label: 'Driver', value: 'driver' },
                    { label: 'Customer', value: 'customer' },
                    { label: 'User', value: 'user' },
                ],
            },
            {
                label: this.intl.t('column.currency'),
                valuePath: 'currency',
                width: 90,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'currency',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.balance'),
                valuePath: 'balance',
                cellComponent: 'table/cell/currency',
                sortParam: 'balance',
                width: 130,
                resizable: true,
                sortable: true,
                filterable: false,
            },
            {
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
                    { label: 'Frozen', value: 'frozen' },
                    { label: 'Suspended', value: 'suspended' },
                ],
            },
            {
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
                label: this.intl.t('column.description'),
                valuePath: 'description',
                width: 200,
                hidden: true,
                resizable: true,
                sortable: false,
                filterable: false,
            },
            {
                label: this.intl.t('column.frozen'),
                valuePath: 'is_frozen',
                cellComponent: 'table/cell/boolean',
                width: 80,
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'is_frozen',
                filterComponent: 'filter/select',
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                filterOptions: [
                    { label: 'Yes', value: 'true' },
                    { label: 'No', value: 'false' },
                ],
            },
            {
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
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.wallet') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.wallet') }),
                        icon: 'eye',
                        fn: this.walletActions.transition.view,
                        permission: 'ledger view wallet',
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.wallet') }),
                        icon: 'trash',
                        fn: this.walletActions.delete,
                        className: 'text-red-500 hover:text-red-700',
                        permission: 'ledger delete wallet',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }
}
