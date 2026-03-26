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

    serialize(snapshot) {
        const json = super.serialize(...arguments);

        // Derive customer_type from the embedded customer record.
        // The customer endpoint returns each customer with a `customer_type`
        // field that is either "contact" or "vendor".  We map that to the
        // PolymorphicType string the backend PolymorphicType cast expects.
        const customerSnapshot = snapshot.belongsTo('customer');
        if (customerSnapshot) {
            // The API returns customer_type on the embedded customer object as the
            // full Ember resource type string, e.g. "fleet-ops:vendor" or
            // "fleet-ops:contact" (after the backend fix that removed the erroneous
            // "customer-" prefix).  We pass it through directly as the invoice's
            // customer_type so the backend PolymorphicType cast can resolve the
            // correct model class.
            const rawType = customerSnapshot.attr('customer_type');
            if (rawType) {
                json['customer_type'] = rawType;
            }
        }

        return json;
    }
}
