import LedgerSerializer from './ledger';

/**
 * Serializer for the ledger-invoice-item model.
 *
 * Inherits the ledger-prefix stripping and EmbeddedRecordsMixin from
 * LedgerSerializer. No additional attrs needed — items are leaf records
 * with no further embedded relationships.
 */
export default class LedgerInvoiceItemSerializer extends LedgerSerializer {}
