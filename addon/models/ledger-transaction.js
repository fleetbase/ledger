import Model, { attr, belongsTo } from '@ember-data/model';
import { computed } from '@ember/object';
import { format as formatDate, formatDistanceToNow, isValid as isValidDate } from 'date-fns';

export default class LedgerTransactionModel extends Model {
    // Identity
    @attr('string') public_id;
    // Classification
    @attr('string') type;
    @attr('string') direction;
    @attr('string') status;

    @computed('direction') get direction_sign() {
        return this.direction === 'debit' ? '-' : '+';
    }

    @computed('direction') get direction_color() {
        return this.direction === 'debit' ? 'red' : 'green';
    }
    // Monetary
    @attr('string') amount;
    @attr('string') fee_amount;
    @attr('string') tax_amount;
    @attr('string') net_amount;
    @attr('string') balance_after;
    @attr('string') currency;
    @attr('string') exchange_rate;
    @attr('string') settled_currency;
    @attr('string') settled_amount;
    // Polymorphic roles
    @attr('string') subject_uuid;
    @attr('string') subject_type;
    @attr('string') payer_uuid;
    @attr('string') payer_type;
    @attr('string') payee_uuid;
    @attr('string') payee_type;
    @attr('string') initiator_uuid;
    @attr('string') initiator_type;
    @attr('string') context_uuid;
    @attr('string') context_type;
    // Gateway
    @attr('string') gateway;
    @attr('string') gateway_uuid;
    @attr('string') gateway_transaction_id;
    @attr('string') payment_method;
    @attr('string') payment_method_last4;
    @attr('string') payment_method_brand;
    // Idempotency and linkage
    @attr('string') reference;
    @attr('string') parent_transaction_uuid;
    // Descriptive
    @attr('string') description;
    @attr('string') notes;
    // Failure info
    @attr('string') failure_reason;
    @attr('string') failure_code;
    // Reporting
    @attr('string') period;
    @attr('raw') tags;
    // Traceability
    @attr('string') ip_address;
    // Misc
    @attr('raw') meta;
    // Timestamps
    @attr('date') settled_at;
    @attr('date') voided_at;
    @attr('date') reversed_at;
    @attr('date') expires_at;
    @attr('date') created_at;
    @attr('date') updated_at;

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
    @computed('settled_at') get settledAtAgo() {
        if (!isValidDate(this.settled_at)) {
            return null;
        }
        return formatDistanceToNow(this.settled_at);
    }

    @computed('settled_at') get settledAt() {
        if (!isValidDate(this.settled_at)) {
            return null;
        }
        return formatDate(this.settled_at, 'PP HH:mm');
    }

    @computed('settled_at') get settledAtShort() {
        if (!isValidDate(this.settled_at)) {
            return null;
        }
        return formatDate(this.settled_at, 'dd, MMM');
    }
    @computed('voided_at') get voidedAtAgo() {
        if (!isValidDate(this.voided_at)) {
            return null;
        }
        return formatDistanceToNow(this.voided_at);
    }

    @computed('voided_at') get voidedAt() {
        if (!isValidDate(this.voided_at)) {
            return null;
        }
        return formatDate(this.voided_at, 'PP HH:mm');
    }

    @computed('voided_at') get voidedAtShort() {
        if (!isValidDate(this.voided_at)) {
            return null;
        }
        return formatDate(this.voided_at, 'dd, MMM');
    }
    @computed('reversed_at') get reversedAtAgo() {
        if (!isValidDate(this.reversed_at)) {
            return null;
        }
        return formatDistanceToNow(this.reversed_at);
    }

    @computed('reversed_at') get reversedAt() {
        if (!isValidDate(this.reversed_at)) {
            return null;
        }
        return formatDate(this.reversed_at, 'PP HH:mm');
    }

    @computed('reversed_at') get reversedAtShort() {
        if (!isValidDate(this.reversed_at)) {
            return null;
        }
        return formatDate(this.reversed_at, 'dd, MMM');
    }
    @computed('expires_at') get expiresAtAgo() {
        if (!isValidDate(this.expires_at)) {
            return null;
        }
        return formatDistanceToNow(this.expires_at);
    }

    @computed('expires_at') get expiresAt() {
        if (!isValidDate(this.expires_at)) {
            return null;
        }
        return formatDate(this.expires_at, 'PP HH:mm');
    }

    @computed('expires_at') get expiresAtShort() {
        if (!isValidDate(this.expires_at)) {
            return null;
        }
        return formatDate(this.expires_at, 'dd, MMM');
    }
}
