import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';

export default class WalletTransactionModel extends Model {
    @attr('string') wallet_uuid;
    @attr('string') type;
    @attr('string') direction;
    @attr('string') status;
    @attr('string') currency;
    @attr('number') amount;
    @attr('number') balance_after;
    @attr('string') description;
    @attr('string') reference;
    @attr('raw') meta;
    @attr('date') created_at;
    @attr('date') updated_at;

    @computed('amount', 'currency')
    get formatted_amount() {
        const value = (this.amount || 0) / 100;
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency || 'USD' }).format(value);
    }

    @computed('balance_after', 'currency')
    get formatted_balance_after() {
        const value = (this.balance_after || 0) / 100;
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency || 'USD' }).format(value);
    }

    @computed('direction')
    get direction_sign() {
        return this.direction === 'credit' ? '+' : '-';
    }

    @computed('direction')
    get direction_color() {
        return this.direction === 'credit' ? 'green' : 'red';
    }

    @computed('status')
    get status_badge_color() {
        const colors = {
            pending: 'yellow',
            completed: 'green',
            failed: 'red',
            reversed: 'blue',
        };
        return colors[this.status] || 'gray';
    }
}
