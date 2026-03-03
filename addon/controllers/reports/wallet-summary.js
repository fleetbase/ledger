import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';

export default class ReportsWalletSummaryController extends Controller {
    @service fetch;
    @service currentUser;

    @tracked data = null;
    @tracked dateRange = null;
    @tracked date_from = null;
    @tracked date_to = null;

    get companyCurrency() {
        return this.currentUser.getCompany()?.currency ?? 'USD';
    }

    get periodStats() {
        if (!this.data?.period_stats) return [];
        return Object.entries(this.data.period_stats).map(([currency, stats]) => ({
            currency,
            ...stats,
        }));
    }

    @task *loadReport() {
        try {
            const params = {};
            if (this.date_from) params.date_from = this.date_from;
            if (this.date_to) params.date_to = this.date_to;
            const result = yield this.fetch.get('reports/wallet-summary', params, { namespace: 'ledger/int/v1' });
            this.data = result?.data ?? null;
        } catch {
            this.data = null;
        }
    }

    @action reload() {
        this.loadReport.perform();
    }

    @action onDateRangeChanged({ formattedDate }) {
        if (isArray(formattedDate) && formattedDate.length === 2) {
            this.date_from = formattedDate[0];
            this.date_to = formattedDate[1];
            this.loadReport.perform();
        } else if (!formattedDate || formattedDate.length === 0) {
            this.date_from = null;
            this.date_to = null;
            this.loadReport.perform();
        }
    }
}
