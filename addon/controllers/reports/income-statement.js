import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';

export default class ReportsIncomeStatementController extends Controller {
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
            const result = yield this.fetch.get('reports/income-statement', params, { namespace: 'ledger/int/v1' });
            const raw = result?.data ?? null;
            if (!raw) {
                this.data = null;
                return;
            }
            // Normalise: backend returns revenues/expenses (not revenue/expenses)
            // period is an object {from, to} not a string
            const periodStr = raw.period?.from && raw.period?.to
                ? `${raw.period.from} – ${raw.period.to}`
                : null;
            const mapItems = (arr) => (arr ?? []).map(item => ({
                ...item,
                amount: item.amount ?? item.balance ?? 0,
            }));
            this.data = {
                period:         periodStr,
                revenue:        mapItems(raw.revenues ?? raw.revenue ?? []),
                expenses:       mapItems(raw.expenses ?? []),
                total_revenue:  raw.total_revenue  ?? 0,
                total_expenses: raw.total_expenses ?? 0,
                net_income:     raw.net_income ?? 0,
                profitable:     raw.profitable ?? false,
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
