import Route from '@ember/routing/route';

export default class PaymentsGatewaysDetailsDiagnosticsRoute extends Route {
    model() {
        return this.modelFor('payments.gateways.details');
    }
}
