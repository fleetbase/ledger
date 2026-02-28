import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';

export default class AccountModel extends Model {
    @attr('string') public_id;
    @attr('string') name;
    @attr('string') code;
    @attr('string') type;
    @attr('string') description;
    @attr('string') currency;
    @attr('number') balance;
    @attr('boolean') is_active;
    @attr('date') created_at;
    @attr('date') updated_at;

    @computed('balance', 'currency')
    get formatted_balance() {
        const amount = (this.balance || 0) / 100;
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency || 'USD' }).format(amount);
    }

    @computed('type')
    get type_label() {
        const labels = {
            asset: 'Asset',
            liability: 'Liability',
            equity: 'Equity',
            revenue: 'Revenue',
            expense: 'Expense',
        };
        return labels[this.type] || this.type;
    }

    @computed('is_active')
    get status_label() {
        return this.is_active ? 'Active' : 'Inactive';
    }
}
