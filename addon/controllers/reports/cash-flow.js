import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';

export default class ReportsCashFlowController extends Controller {
    @service fetch;
    @service currentUser;

    @tracked data = null;
    @tracked dateRange = null;
    @tracked start_date = null;
    @tracked end_date = null;

    get companyCurrency() {
        return this.currentUser.getCompany()?.currency ?? 'USD';
    }

    @task *loadReport() {
        try {
            const params = {};
            if (this.start_date) params.start_date = this.start_date;
            if (this.end_date) params.end_date = this.end_date;
            const result = yield this.fetch.get('reports/cash-flow', params, { namespace: 'ledger/int/v1' });
            const raw = result?.data ?? null;
            if (!raw) {
                this.data = null;
                return;
            }
            // Normalise: backend returns operating_activities.{items, net_flow}, etc.
            // Each item has {type, direction, currency, total, count}.
            // Template expects item.net (positive = inflow, negative = outflow).
            const periodStr = raw.period?.from && raw.period?.to
                ? `${raw.period.from} – ${raw.period.to}`
                : null;
            const mapFlowItems = (items) => (items ?? []).map(item => ({
                ...item,
                net: item.direction === 'credit' ? item.total : -item.total,
            }));
            this.data = {
                period:          periodStr,
                operating:       mapFlowItems(raw.operating_activities?.items),
                net_operating:   raw.operating_activities?.net_flow ?? 0,
                financing:       mapFlowItems(raw.financing_activities?.items),
                net_financing:   raw.financing_activities?.net_flow ?? 0,
                investing:       mapFlowItems(raw.investing_activities?.items),
                net_investing:   raw.investing_activities?.net_flow ?? 0,
                net_change:      raw.net_cash_change ?? 0,
                cash_account:    raw.cash_account ?? null,
            };
        } catch {
            this.data = null;
        }
    }

    @action reload() {
        this.loadReport.perform();
    }

    @action onDateRangeChanged({ formattedDate }) {
        if (isArray(formattedDate) && formattedDate.length === 2) {
            this.start_date = formattedDate[0];
            this.end_date = formattedDate[1];
            this.loadReport.perform();
        } else if (!formattedDate || formattedDate.length === 0) {
            this.start_date = null;
            this.end_date = null;
            this.loadReport.perform();
        }
    }
}
