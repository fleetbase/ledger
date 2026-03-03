import Route from '@ember/routing/route';

export default class AccountingGeneralLedgerRoute extends Route {
    setupController(controller, model) {
        super.setupController(controller, model);
        controller.loadGeneralLedger.perform();
    }
}
