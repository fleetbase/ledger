import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PaymentsGatewaysIndexNewRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('ledger create gateway')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.ledger.payments.gateways.index');
        }
    }
}
