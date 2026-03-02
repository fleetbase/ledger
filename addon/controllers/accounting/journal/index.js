import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class AccountingJournalIndexController extends Controller {
    @service journalActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'type', 'entry_source', 'currency'];
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-entry_date';
    @tracked query = null;
    @tracked type = null;
    @tracked entry_source = null;
    @tracked currency = null;
    @tracked table = null;

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.journalActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.journalActions.transition.create,
            },
        ];
    }

    get bulkActions() {
        const selected = this.tableContext.getSelectedRows();
        return [
            {
                label: this.intl.t('common.delete-selected-count', { count: selected.length }),
                class: 'text-red-500',
                fn: this.journalActions.bulkDelete,
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
                action: this.journalActions.transition.view,
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.date'),
                valuePath: 'entry_date',
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
                filterComponent: 'filter/select',
                filterOptions: [
                    { label: 'General', value: 'general' },
                    { label: 'Payment', value: 'payment' },
                    { label: 'Refund', value: 'refund' },
                    { label: 'Adjustment', value: 'adjustment' },
                    { label: 'Deposit', value: 'deposit' },
                    { label: 'Withdrawal', value: 'withdrawal' },
                    { label: 'Transfer', value: 'transfer' },
                ],
            },
            {
                label: this.intl.t('column.debit-account'),
                valuePath: 'debit_account_name',
                resizable: true,
                filterable: true,
                filterParam: 'debit_account',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.credit-account'),
                valuePath: 'credit_account_name',
                resizable: true,
                filterable: true,
                filterParam: 'credit_account',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.amount'),
                valuePath: 'amount',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.source'),
                valuePath: 'entry_source',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'entry_source',
                filterComponent: 'filter/select',
                filterOptions: [
                    { label: 'System', value: 'system' },
                    { label: 'Manual', value: 'manual' },
                ],
            },
        ];
    }
}
