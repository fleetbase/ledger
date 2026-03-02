import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class PaymentsTransactionsIndexController extends Controller {
    @service transactionActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = [
        'page',
        'limit',
        'sort',
        'query',
        'public_id',
        'description',
        'type',
        'status',
        'direction',
        'currency',
        'gateway',
        'payment_method',
        'reference',
        'period',
        'failure_reason',
        'created_at',
        'updated_at',
        'settled_at',
    ];
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked public_id = null;
    @tracked description = null;
    @tracked type = null;
    @tracked status = null;
    @tracked direction = null;
    @tracked currency = null;
    @tracked gateway = null;
    @tracked payment_method = null;
    @tracked reference = null;
    @tracked period = null;
    @tracked failure_reason = null;
    @tracked created_at = null;
    @tracked updated_at = null;
    @tracked settled_at = null;
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
            // ── Default visible columns ──────────────────────────────────────
            {
                sticky: true,
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'table/cell/anchor',
                action: this.transactionActions.transition.view,
                width: 120,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'public_id',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.date'),
                valuePath: 'createdAt',
                sortParam: 'created_at',
                filterParam: 'created_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('column.description'),
                valuePath: 'description',
                resizable: true,
                sortable: false,
                filterable: true,
                filterParam: 'description',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'type',
                filterComponent: 'filter/multi-option',
                filterOptions: [
                    'wallet_deposit',
                    'wallet_withdrawal',
                    'wallet_transfer',
                    'gateway_charge',
                    'gateway_refund',
                    'gateway_payout',
                    'adjustment',
                    'earning',
                    'fee',
                ],
            },
            {
                label: this.intl.t('column.direction'),
                valuePath: 'direction',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'direction',
                filterComponent: 'filter/select',
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                filterOptions: [
                    { label: 'Credit', value: 'credit' },
                    { label: 'Debit', value: 'debit' },
                ],
            },
            {
                label: this.intl.t('column.amount'),
                valuePath: 'amount',
                cellComponent: 'table/cell/currency',
                resizable: true,
                sortable: true,
                sortParam: 'amount',
                filterable: false,
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'status',
                filterComponent: 'filter/multi-option',
                filterOptions: ['pending', 'succeeded', 'failed', 'refunded', 'voided', 'reversed', 'expired'],
            },
            // ── Hidden / toggleable columns ──────────────────────────────────
            {
                label: this.intl.t('column.net-amount'),
                valuePath: 'net_amount',
                cellComponent: 'table/cell/currency',
                hidden: true,
                resizable: true,
                sortable: true,
                sortParam: 'net_amount',
                filterable: false,
            },
            {
                label: this.intl.t('column.fee'),
                valuePath: 'fee_amount',
                cellComponent: 'table/cell/currency',
                hidden: true,
                resizable: true,
                sortable: false,
                filterable: false,
            },
            {
                label: this.intl.t('column.tax'),
                valuePath: 'tax_amount',
                cellComponent: 'table/cell/currency',
                hidden: true,
                resizable: true,
                sortable: false,
                filterable: false,
            },
            {
                label: this.intl.t('column.currency'),
                valuePath: 'currency',
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'currency',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.gateway'),
                valuePath: 'gateway',
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'gateway',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.payment-method'),
                valuePath: 'payment_method',
                hidden: true,
                resizable: true,
                sortable: false,
                filterable: true,
                filterParam: 'payment_method',
                filterComponent: 'filter/select',
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                filterOptions: [
                    { label: 'Card', value: 'card' },
                    { label: 'Bank Transfer', value: 'bank_transfer' },
                    { label: 'Wallet', value: 'wallet' },
                    { label: 'Cash', value: 'cash' },
                ],
            },
            {
                label: this.intl.t('column.reference'),
                valuePath: 'reference',
                hidden: true,
                resizable: true,
                sortable: false,
                filterable: true,
                filterParam: 'reference',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.period'),
                valuePath: 'period',
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'period',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.settled-at'),
                valuePath: 'settled_at',
                sortParam: 'settled_at',
                filterParam: 'settled_at',
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('column.failure-reason'),
                valuePath: 'failure_reason',
                hidden: true,
                resizable: true,
                sortable: false,
                filterable: true,
                filterParam: 'failure_reason',
                filterComponent: 'filter/string',
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.transaction') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.transaction') }),
                        icon: 'eye',
                        fn: this.transactionActions.transition.view,
                        permission: 'ledger view transaction',
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
