import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';

export default class GatewayModel extends Model {
    @attr('string') name;
    @attr('string') code;
    @attr('string') type;
    @attr('string') description;
    @attr('string') status;
    @attr('boolean') sandbox;
    @attr('raw') config_schema;
    @attr('date') created_at;
    @attr('date') updated_at;

    @computed('status')
    get status_badge_color() {
        return this.status === 'active' ? 'green' : 'gray';
    }

    @computed('sandbox')
    get mode_label() {
        return this.sandbox ? 'Sandbox' : 'Live';
    }

    @computed('sandbox')
    get mode_badge_color() {
        return this.sandbox ? 'yellow' : 'green';
    }

    @computed('code')
    get icon_name() {
        const icons = {
            stripe: 'credit-card',
            qpay: 'money-bill',
            cash: 'money-bill-wave',
        };
        return icons[this.code] || 'credit-card';
    }
}
