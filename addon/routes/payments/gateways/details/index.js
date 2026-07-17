import Route from '@ember/routing/route';

export default class PaymentsGatewaysDetailsIndexRoute extends Route {
    model() {
        return this.modelFor('payments.gateways.details');
    }
}
