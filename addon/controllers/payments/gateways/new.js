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

export default class PaymentsGatewaysNewController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;
    @service intl;
    @service events;

    queryParams = ['driver'];

    @tracked driver = null;
    @tracked gateway = this.store.createRecord('ledger-gateway', DEFAULT_PROPERTIES);

    @task *save(gateway) {
        try {
            yield gateway.save();
            this.events.trackResourceCreated(gateway);

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.ledger.payments.gateways.details', gateway);
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

    @action cancel() {
        this.resetForm();
        return this.hostRouter.transitionTo('console.ledger.payments.gateways.index');
    }

    @action resetForm() {
        this.gateway = this.store.createRecord('ledger-gateway', DEFAULT_PROPERTIES);
    }
}
