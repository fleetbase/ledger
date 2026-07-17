import Route from '@ember/routing/route';

export default class PaymentsGatewaysDetailsWebhooksRoute extends Route {
    model() {
        return this.modelFor('payments.gateways.details');
    }
}
