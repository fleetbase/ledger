import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class BillingInvoicesIndexController extends Controller {
    @service invoiceActions;
    @service tableContext;
    @service hostRouter;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'status', 'currency', 'order', 'customer', 'created_at', 'due_date', 'amount'];
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked status = null;
    @tracked currency = null;
    @tracked order = null;
    @tracked customer = null;
    @tracked created_at = null;
    @tracked due_date = null;
    @tracked amount = null;
    @tracked table = null;

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

    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.number'),
                valuePath: 'number',
                cellComponent: 'table/cell/anchor',
                action: this.invoiceActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'number',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.customer'),
                valuePath: 'customerName',
                resizable: true,
                sortable: false,
                filterable: true,
                filterParam: 'customer',
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select customer',
                model: 'customer',
            },
            {
                label: 'Order',
                valuePath: 'orderTrackingLabel',
                cellComponent: 'table/cell/anchor',
                action: this.viewOrder,
                resizable: true,
                sortable: false,
                filterable: true,
                filterParam: 'order',
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select order',
                model: 'order',
                modelNamePath: 'tracking',
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
                    { label: 'Draft', value: 'draft' },
                    { label: 'Sent', value: 'sent' },
                    { label: 'Viewed', value: 'viewed' },
                    { label: 'Partial', value: 'partial' },
                    { label: 'Paid', value: 'paid' },
                    { label: 'Overdue', value: 'overdue' },
                    { label: 'Void', value: 'void' },
                    { label: 'Cancelled', value: 'cancelled' },
                ],
            },
            {
                label: this.intl.t('column.total'),
                valuePath: 'total',
                cellComponent: 'table/cell/currency',
                currencyPath: 'currency',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'amount',
                filterComponent: 'filter/range',
                min: 0,
                max: 10000000,
                step: 100,
                minLabel: 'Min',
                maxLabel: 'Max',
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
                label: this.intl.t('column.balance'),
                valuePath: 'balance',
                cellComponent: 'table/cell/currency',
                currencyPath: 'currency',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.due-date'),
                valuePath: 'dueDate',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'due_date',
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('column.invoice-date'),
                valuePath: 'invoiceDate',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'created_at',
                filterComponent: 'filter/date',
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.invoice') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.invoice') }),
                        icon: 'eye',
                        fn: this.invoiceActions.transition.view,
                        permission: 'ledger view invoice',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.invoice') }),
                        icon: 'pencil',
                        fn: this.invoiceActions.transition.edit,
                        permission: 'ledger update invoice',
                    },
                    {
                        label: 'Record Payment',
                        icon: 'check-circle',
                        fn: this.invoiceActions.recordPayment,
                        permission: 'ledger update invoice',
                    },
                    {
                        label: 'Preview Invoice',
                        icon: 'file-invoice',
                        fn: this.invoiceActions.previewInvoice,
                        permission: 'ledger view invoice',
                    },
                    {
                        label: this.intl.t('invoice.actions.copy-invoice-url'),
                        icon: 'link',
                        fn: this.invoiceActions.copyInvoiceUrl,
                        permission: 'ledger view invoice',
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.invoice') }),
                        icon: 'trash',
                        fn: this.invoiceActions.delete,
                        className: 'text-red-500 hover:text-red-700',
                        permission: 'ledger delete invoice',
                    },
                ],
            },
        ];
    }

    @action viewOrder(invoice) {
        const orderId = invoice?.orderRouteIdentifier;
        if (!orderId) {
            return;
        }

        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', orderId);
    }
}
