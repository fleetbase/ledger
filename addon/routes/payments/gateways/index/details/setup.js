import Route from '@ember/routing/route';

export default class PaymentsGatewaysIndexDetailsSetupRoute extends Route {
    model() {
        return this.modelFor('payments.gateways.index.details');
    }
}
