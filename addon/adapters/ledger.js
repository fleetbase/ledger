import ApplicationAdapter from '@fleetbase/ember-core/adapters/application';
import { pluralize } from 'ember-inflector';
import { dasherize } from '@ember/string';

export default class LedgerAdapter extends ApplicationAdapter {
    namespace = 'ledger/int/v1';

    /**
     * Strip the 'ledger-' prefix from the model name before building the URL path.
     * e.g. 'ledger-account' -> 'accounts', 'ledger-transaction' -> 'transactions'
     */
    pathForType(modelName) {
        return pluralize(dasherize(modelName)).replace('ledger-', '');
    }
}
