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
        items:    { embedded: 'always' },
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
            // The API returns customer_type as either:
            //   "contact" | "vendor"           (bare, from a freshly selected customer)
            //   "customer-contact" | "customer-vendor"  (prefixed, as stored on the invoice)
            // Normalise to the bare type before building the PolymorphicType string.
            let rawType = customerSnapshot.attr('customer_type'); // e.g. "contact", "vendor", or "customer-vendor"
            if (rawType) {
                const bareType = rawType.startsWith('customer-') ? rawType.slice('customer-'.length) : rawType;
                json['customer_type'] = `fleet-ops:${bareType}`;
            }
        }

        return json;
    }
}
