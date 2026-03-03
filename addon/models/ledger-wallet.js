import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';
import { format as formatDate, formatDistanceToNow, isValid as isValidDate } from 'date-fns';

export default class LedgerWalletModel extends Model {
    @attr('string') public_id;
    @attr('string') name;
    @attr('string') description;
    @attr('string') subject_uuid;
    @attr('string') subject_type;
    @attr('raw') subject;
    @attr('string') type;
    @attr('string') status;
    @attr('string') currency;
    @attr('number') balance;
    @attr('string') formatted_balance;
    @attr('boolean') is_frozen;
    @attr('raw') meta;
    @attr('date') created_at;
    @attr('date') updated_at;

    // ── Owner helpers ──────────────────────────────────────────────────────

    /**
     * Display name of the wallet owner.
     * Tries name → display_name → full_name → email → phone in order.
     */
    @computed('subject')
    get owner_name() {
        const s = this.subject;
        if (!s) {
            return null;
        }
        return s.name || s.display_name || s.full_name || s.email || s.phone || null;
    }

    /**
     * Contact detail (email or phone) of the owner when the subject is a User.
     */
    @computed('subject')
    get owner_contact() {
        const s = this.subject;
        if (!s) {
            return null;
        }
        return s.email || s.phone || null;
    }

    /**
     * Human-readable owner type label derived from the computed `type` attr.
     */
    @computed('type')
    get owner_type_label() {
        const t = this.type;
        if (!t) {
            return null;
        }
        return t.charAt(0).toUpperCase() + t.slice(1);
    }

    // ── Type helpers ───────────────────────────────────────────────────────

    /**
     * Human-readable wallet type label.
     * The `type` attr is already computed server-side from subject_type.
     */
    @computed('type')
    get type_label() {
        const t = this.type;
        if (!t) {
            return null;
        }
        return t.charAt(0).toUpperCase() + t.slice(1);
    }

    // ── Status helpers ─────────────────────────────────────────────────────

    @computed('status')
    get status_label() {
        const s = this.status;
        if (!s) {
            return null;
        }
        return s.charAt(0).toUpperCase() + s.slice(1);
    }

    @computed('status')
    get status_badge_color() {
        switch (this.status) {
            case 'active':
                return 'green';
            case 'inactive':
                return 'gray';
            case 'frozen':
                return 'blue';
            case 'suspended':
                return 'red';
            default:
                return 'gray';
        }
    }

    // ── Timestamp helpers ──────────────────────────────────────────────────

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
