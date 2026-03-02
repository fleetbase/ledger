import Model, { attr, belongsTo, hasMany } from '@ember-data/model';

export default class LedgerTransactionModel extends Model {
    // Classification
    @attr('string') type;
    @attr('string') direction;
    @attr('string') status;

    // Monetary
    @attr('number') amount;
    @attr('number') fee_amount;
    @attr('number') tax_amount;
    @attr('number') net_amount;
    @attr('number') balance_after;
    @attr('string') currency;
    @attr('number') exchange_rate;
    @attr('string') settled_currency;
    @attr('number') settled_amount;

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
}
