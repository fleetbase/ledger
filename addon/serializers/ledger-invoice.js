import LedgerSerializer from './ledger';

/**
 * Serializer for the ledger-invoice model.
 *
 * Extends the base LedgerSerializer (which already mixes in EmbeddedRecordsMixin)
 * and declares `items` as always-embedded so that when `record.save()` is called
 * the full line-items array is included in the request payload — no custom
 * fetch override needed.
 */
export default class LedgerInvoiceSerializer extends LedgerSerializer {
    attrs = {
        items: { embedded: 'always' },
        template: { embedded: 'always' },
        customer: { embedded: 'always' },
    };
}
