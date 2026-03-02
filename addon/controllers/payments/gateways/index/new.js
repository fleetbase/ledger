import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = {
    status: 'active',
    environment: 'sandbox',
    is_default: false,
};

export default class PaymentsGatewaysIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;
    @service intl;
    @service events;

    @tracked overlay;
    @tracked gateway = this.store.createRecord('ledger-gateway', DEFAULT_PROPERTIES);

    get actionButtons() {
        return [];
    }

    @task *save(gateway) {
        try {
            yield gateway.save();
            this.events.trackResourceCreated(gateway);
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.ledger.payments.gateways.index.details', gateway);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: 'Gateway',
                    resourceName: gateway.name,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.gateway = this.store.createRecord('ledger-gateway', DEFAULT_PROPERTIES);
    }
}
