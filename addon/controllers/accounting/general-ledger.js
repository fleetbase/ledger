import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

const ACCOUNT_TYPES = [
    { label: 'All Types', value: null },
    { label: 'Asset', value: 'asset' },
    { label: 'Liability', value: 'liability' },
    { label: 'Equity', value: 'equity' },
    { label: 'Revenue', value: 'revenue' },
    { label: 'Expense', value: 'expense' },
];

export default class AccountingGeneralLedgerController extends Controller {
    queryParams = ['date_from', 'date_to', 'type'];

    @tracked date_from = null;
    @tracked date_to = null;
    @tracked type = null;

    /**
     * Plain object map of accountId → boolean for expanded state.
     * Tracked so HBS re-renders when it changes.
     */
    @tracked expandedMap = {};

    get accountTypes() {
        return ACCOUNT_TYPES;
    }

    get selectedType() {
        return ACCOUNT_TYPES.find((t) => t.value === this.type) ?? ACCOUNT_TYPES[0];
    }

    @action setType(option) {
        this.type = option?.value ?? null;
    }

    @action setDateFrom(event) {
        this.date_from = event?.target?.value || null;
    }

    @action setDateTo(event) {
        this.date_to = event?.target?.value || null;
    }

    @action toggleAccount(accountId) {
        this.expandedMap = {
            ...this.expandedMap,
            [accountId]: !this.expandedMap[accountId],
        };
    }

    @action expandAll() {
        const map = {};
        (this.model?.accounts ?? []).forEach((a) => {
            map[a.account.id] = true;
        });
        this.expandedMap = map;
    }

    @action collapseAll() {
        this.expandedMap = {};
    }
}
