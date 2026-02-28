import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';

export default class WalletModel extends Model {
    @attr('string') public_id;
    @attr('string') name;
    @attr('string') owner_uuid;
    @attr('string') owner_type;
    @attr('string') type;
    @attr('string') status;
    @attr('string') currency;
    @attr('number') balance;
    @attr('boolean') is_frozen;
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
            driver: 'Driver',
            customer: 'Customer',
            company: 'Company',
            system: 'System',
        };
        return labels[this.type] || this.type;
    }

    @computed('is_frozen', 'status')
    get status_label() {
        if (this.is_frozen) return 'Frozen';
        return this.status === 'active' ? 'Active' : 'Inactive';
    }

    @computed('is_frozen', 'status')
    get status_badge_color() {
        if (this.is_frozen) return 'blue';
        return this.status === 'active' ? 'green' : 'gray';
    }
}
