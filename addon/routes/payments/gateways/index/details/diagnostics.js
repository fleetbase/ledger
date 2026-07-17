import Route from '@ember/routing/route';

export default class PaymentsGatewaysIndexDetailsDiagnosticsRoute extends Route {
    model() {
        return this.modelFor('payments.gateways.index.details');
    }
}
