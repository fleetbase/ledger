import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';
import { format as formatDate, formatDistanceToNow, isValid as isValidDate } from 'date-fns';

export default class LedgerJournalModel extends Model {
    @attr('string') public_id;
    @attr('string') number;
    @attr('string') type;
    @attr('string') status;
    @attr('string') reference;
    @attr('string') memo;
    @attr('string') currency;
    @attr('string') amount;
    @attr('string') description;
    @attr('string') debit_account_uuid;
    @attr('string') credit_account_uuid;
    @attr('raw') debit_account;
    @attr('raw') credit_account;
    @attr('string') transaction_uuid;
    @attr('boolean') is_system_entry;
    @attr('raw') meta;
    @attr('date') entry_date;
    @attr('date') created_at;
    @attr('date') updated_at;

    get entry_source() {
        return this.is_system_entry ? 'System' : 'Manual';
    }

    get type_label() {
        const labels = {
            general: 'General',
            standard: 'Standard',
            adjusting: 'Adjusting',
            closing: 'Closing',
            reversing: 'Reversing',
            opening: 'Opening',
            wallet_transfer: 'Wallet Transfer',
            wallet_deposit: 'Wallet Deposit',
            wallet_withdrawal: 'Wallet Withdrawal',
        };
        return labels[this.type] ?? this.type ?? null;
    }

    @computed('debit_account.name') get debit_account_name() {
        return this.debit_account?.name ?? null;
    }

    @computed('debit_account.code') get debit_account_code() {
        return this.debit_account?.code ?? null;
    }

    @computed('credit_account.name') get credit_account_name() {
        return this.credit_account?.name ?? null;
    }

    @computed('credit_account.code') get credit_account_code() {
        return this.credit_account?.code ?? null;
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

    @computed('entry_date') get entryDateAgo() {
        if (!isValidDate(this.entry_date)) {
            return null;
        }
        return formatDistanceToNow(this.entry_date);
    }

    @computed('entry_date') get entryDate() {
        if (!isValidDate(this.entry_date)) {
            return null;
        }
        return formatDate(this.entry_date, 'PP HH:mm');
    }

    @computed('entry_date') get entryDateShort() {
        if (!isValidDate(this.entry_date)) {
            return null;
        }
        return formatDate(this.entry_date, 'dd, MMM');
    }
}
