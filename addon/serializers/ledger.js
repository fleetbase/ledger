import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { EmbeddedRecordsMixin } from '@ember-data/serializer/rest';
import { underscore } from '@ember/string';

// EmbeddedRecordsMixin must be applied via classic extend() syntax
const LedgerSerializerBase = ApplicationSerializer.extend(EmbeddedRecordsMixin);

/**
 * Base serializer for all Ledger models.
 *
 * Handles the bidirectional mapping between the `ledger-` prefixed Ember model
 * names and the unprefixed snake_case payload keys the backend returns.
 *
 * Payload key -> Ember model name:  'account'          -> 'ledger-account'
 * Ember model name -> payload key:  'ledger-account'   -> 'account'
 *                                   'ledger-wallet-transaction' -> 'wallet_transaction'
 */
export default class LedgerSerializer extends LedgerSerializerBase {
    /**
     * Called when Ember Data receives a payload and needs to determine which
     * model class to use. Prepend 'ledger-' so it resolves to our prefixed models.
     */
    modelNameFromPayloadKey(key) {
        const modelName = super.modelNameFromPayloadKey(key);
        return `ledger-${modelName}`;
    }

    /**
     * Called when Ember Data builds a request payload and needs to know what
     * key to use for the model. Strip the 'ledger-' prefix and convert to
     * snake_case to match what the backend expects.
     */
    payloadKeyFromModelName(modelName) {
        return underscore(modelName)
            .replace(/^ledger_/, '')
            .toLowerCase();
    }
}
