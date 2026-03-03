import Route from '@ember/routing/route';
export default class ReportsCashFlowRoute extends Route {
    setupController(controller, model) {
        super.setupController(controller, model);
        controller.loadReport.perform();
    }
}
