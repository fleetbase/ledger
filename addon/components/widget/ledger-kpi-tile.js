import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

export default class WidgetLedgerKpiTileComponent extends Component {
    @service fetch;
    @service ledgerDashboard;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.unsubscribeDashboard = this.ledgerDashboard.subscribe(() => this.load.perform());
        this.load.perform();
    }

    get metric() {
        return this.data?.metrics?.[this.args.metric] ?? {};
    }

    get title() {
        return this.args.title ?? this.metric.label ?? 'Metric';
    }

    get formattedValue() {
        if (this.metric.multi_currency) {
            return 'Multi';
        }

        const value = this.metric.value ?? 0;

        if (this.metric.format === 'money') {
            return formatCurrency(value, this.metric.currency ?? this.data?.currency ?? 'USD');
        }

        if (this.metric.format === 'percent') {
            return `${value}%`;
        }

        return Number(value).toLocaleString();
    }

    get deltaText() {
        const delta = this.metric.delta_percent;
        if (typeof delta !== 'number') {
            return 'Current';
        }

        return `${delta > 0 ? '+' : ''}${delta}%`;
    }

    get deltaDirection() {
        const delta = this.metric.delta_percent;
        if (typeof delta !== 'number' || delta === 0) {
            return 'neutral';
        }

        const isGood = this.metric.inverse ? delta < 0 : delta > 0;

        return isGood ? 'good' : 'bad';
    }

    get deltaIcon() {
        if (this.deltaDirection === 'neutral') {
            return 'minus';
        }

        return (this.metric.delta_percent ?? 0) > 0 ? 'arrow-up' : 'arrow-down';
    }

    get accentClass() {
        return `ledger-kpi-accent-${this.args.accent ?? 'blue'} ledger-kpi-trend-${this.deltaDirection}`;
    }

    get footnote() {
        if (this.metric.multi_currency) {
            return 'Grouped by currency';
        }

        return this.args.period ?? 'vs previous period';
    }

    @task *load() {
        try {
            const response = yield this.fetch.get('reports/dashboard/summary', this.ledgerDashboard.periodParams, { namespace: 'ledger/int/v1' });
            this.data = response?.data ?? response;
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? 'Unable to load metric';
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.unsubscribeDashboard?.();
    }
}
