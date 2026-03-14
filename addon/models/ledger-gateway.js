import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';
import { format as formatDate, formatDistanceToNow, isValid as isValidDate } from 'date-fns';

export default class LedgerGatewayModel extends Model {
    @attr('string') public_id;
    @attr('string') name;
    @attr('string') code;
    @attr('string') type;
    @attr('string') driver;
    @attr('string') description;
    @attr('string') status;
    @attr('string') environment;
    @attr('boolean') is_default;
    @attr('boolean') is_sandbox;
    @attr('raw') capabilities;
    @attr('string') return_url;
    @attr('string') webhook_url;
    @attr('string') system_webhook_url;
    @attr('raw') config;
    @attr('raw') config_schema;
    @attr('date') created_at;
    @attr('date') updated_at;

    @computed('is_default') get is_default_label() {
        return this.is_default ? 'Yes' : 'No';
    }

    @computed('status') get status_label() {
        const labels = { active: 'Active', inactive: 'Inactive' };
        return labels[this.status] ?? this.status ?? 'Unknown';
    }

    @computed('driver') get driver_label() {
        if (!this.driver) return 'N/A';
        return this.driver.charAt(0).toUpperCase() + this.driver.slice(1);
    }

    @computed('created_at') get createdAtAgo() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDistanceToNow(this.created_at);
    }

    @computed('created_at') get createdAt() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDate(this.created_at, 'PP HH:mm');
    }

    @computed('created_at') get createdAtShort() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDate(this.created_at, 'dd, MMM');
    }
    @computed('updated_at') get updatedAtAgo() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDistanceToNow(this.updated_at);
    }

    @computed('updated_at') get updatedAt() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDate(this.updated_at, 'PP HH:mm');
    }

    @computed('updated_at') get updatedAtShort() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDate(this.updated_at, 'dd, MMM');
    }
}
