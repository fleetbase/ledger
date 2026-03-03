import Route from '@ember/routing/route';
export default class ReportsArAgingRoute extends Route {
    setupController(controller, model) {
        super.setupController(controller, model);
        controller.loadReport.perform();
    }
}
