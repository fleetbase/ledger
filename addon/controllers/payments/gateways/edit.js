import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class PaymentsGatewaysEditController extends Controller {
    @service hostRouter;
    @service notifications;
    @service modalsManager;
    @service intl;
    @service events;

    @task *save(gateway) {
        try {
            yield gateway.save();
            this.events.trackResourceUpdated(gateway);

            yield this.hostRouter.transitionTo('console.ledger.payments.gateways.details', gateway);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: 'Gateway',
                    resourceName: gateway.name,
                })
            );
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action cancel() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }
        return this.transitionToGatewayDetails(this.model);
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model, {
                confirm: async () => {
                    this.model.rollbackAttributes();
                    await this.hostRouter.transitionTo('console.ledger.payments.gateways.details', this.model);
                },
            });
        }
        return this.hostRouter.transitionTo('console.ledger.payments.gateways.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(gateway, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: 'Gateway' }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                gateway.rollbackAttributes();
                await this.transitionToGatewayDetails(gateway);
            },
            ...options,
        });
    }

    transitionToGatewayDetails(gateway) {
        return this.hostRouter.transitionTo('console.ledger.payments.gateways.details', gateway);
    }
}
