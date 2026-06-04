import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class LedgerDashboardDateRangeControlComponent extends Component {
    @service ledgerDashboard;

    @tracked dateRange;

    constructor() {
        super(...arguments);

        this.dateRange = this.ledgerDashboard.dateRangeValue;
        this.unsubscribeDashboard = this.ledgerDashboard.subscribe(() => {
            this.dateRange = this.ledgerDashboard.dateRangeValue;
        });
    }

    @action
    onDateRangeChanged(selection) {
        this.ledgerDashboard.setDateRange(selection);
        this.dateRange = this.ledgerDashboard.dateRangeValue;
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.unsubscribeDashboard?.();
    }
}
