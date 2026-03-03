import Route from '@ember/routing/route';
export default class ReportsBalanceSheetRoute extends Route {
    setupController(controller, model) {
        super.setupController(controller, model);
        controller.loadReport.perform();
    }
}
