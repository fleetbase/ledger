import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class AccountingJournalIndexController extends Controller {
    @service journalActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'entry_source'];
    @tracked page = 1;
    @tracked limit = null;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked entry_source = null;
    @tracked table = null;
get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
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
                label: this.intl.t('column.debit-account'),
                valuePath: 'debit_account.name',
                resizable: true,
            },
            {
                label: this.intl.t('column.credit-account'),
                valuePath: 'credit_account.name',
                resizable: true,
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
                filterComponent: 'filter/string',
            },
        ];
    }

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
}
