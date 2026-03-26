import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = {
    status: 'active',
    currency: 'USD',
    type: 'customer',
    is_frozen: false,
};

export default class PaymentsWalletsIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;
    @service intl;
    @service events;

    @tracked overlay;
    @tracked wallet = this.store.createRecord('ledger-wallet', DEFAULT_PROPERTIES);

    get actionButtons() {
        return [];
    }

    @task *save(wallet) {
        try {
            yield wallet.save();
            this.events.trackResourceCreated(wallet);
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.ledger.payments.wallets.index.details', wallet);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: 'Wallet',
                    resourceName: wallet.name,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.wallet = this.store.createRecord('ledger-wallet', DEFAULT_PROPERTIES);
    }
}
