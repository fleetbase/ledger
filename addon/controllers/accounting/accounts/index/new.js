import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = {
    status: 'active',
    currency: 'USD',
    type: 'asset',
};

export default class AccountingAccountsIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;
    @service intl;
    @service events;

    @tracked overlay;
    @tracked account = this.store.createRecord('ledger-account', DEFAULT_PROPERTIES);

    get actionButtons() {
        return [];
    }

    @task *save(account) {
        try {
            yield account.save();
            this.events.trackResourceCreated(account);
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.ledger.accounting.accounts.index.details', account);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: 'Account',
                    resourceName: account.name,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.account = this.store.createRecord('ledger-account', DEFAULT_PROPERTIES);
    }
}
