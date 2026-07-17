import Route from '@ember/routing/route';

export default class PaymentsGatewaysDetailsSetupRoute extends Route {
    model() {
        return this.modelFor('payments.gateways.details');
    }
}
