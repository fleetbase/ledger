import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class BillingInvoicesIndexController extends Controller {
    @service invoiceActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'status', 'customer_uuid'];
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked status = null;
    @tracked customer_uuid = null;
    @tracked table = null;

    get actionButtons() {
        return [
            {
                icon:     'refresh',
                onClick:  this.invoiceActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text:    this.intl.t('common.new'),
                type:    'primary',
                icon:    'plus',
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
                fn:    this.invoiceActions.bulkDelete,
            },
        ];
    }

    get columns() {
        return [
            {
                // Invoice number — clickable anchor that opens the details panel.
                sticky:          true,
                label:           this.intl.t('column.number'),
                valuePath:       'number',
                cellComponent:   'table/cell/anchor',
                action:          this.invoiceActions.transition.view,
                resizable:       true,
                sortable:        true,
                filterable:      true,
                filterParam:     'number',
                filterComponent: 'filter/string',
            },
            {
                // Customer name — resolved from the belongsTo relationship.
                label:     this.intl.t('column.customer'),
                valuePath: 'customerName',
                resizable: true,
                sortable:  false,
            },
            {
                // Status badge.
                label:           this.intl.t('column.status'),
                valuePath:       'status',
                cellComponent:   'table/cell/status',
                resizable:       true,
                sortable:        true,
                filterable:      true,
                filterParam:     'status',
                filterComponent: 'filter/select',
                filterOptions:   [
                    { label: 'Draft',     value: 'draft' },
                    { label: 'Sent',      value: 'sent' },
                    { label: 'Viewed',    value: 'viewed' },
                    { label: 'Partial',   value: 'partial' },
                    { label: 'Paid',      value: 'paid' },
                    { label: 'Overdue',   value: 'overdue' },
                    { label: 'Void',      value: 'void' },
                    { label: 'Cancelled', value: 'cancelled' },
                ],
            },
            {
                // Total — uses the `total` computed alias (maps to total_amount).
                label:         this.intl.t('column.total'),
                valuePath:     'total',
                cellComponent: 'table/cell/currency',
                currencyPath:  'currency',
                resizable:     true,
                sortable:      true,
            },
            {
                // Balance due.
                label:         this.intl.t('column.balance'),
                valuePath:     'balance',
                cellComponent: 'table/cell/currency',
                currencyPath:  'currency',
                resizable:     true,
                sortable:      true,
            },
            {
                // Due date.
                label:     this.intl.t('column.due-date'),
                valuePath: 'dueDate',
                resizable: true,
                sortable:  true,
            },
            {
                // Invoice date (issued date).
                label:     this.intl.t('column.invoice-date'),
                valuePath: 'invoiceDate',
                resizable: true,
                sortable:  true,
            },
            {
                // Record creation timestamp.
                label:     this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                resizable: true,
                sortable:  true,
            },
        ];
    }
}
