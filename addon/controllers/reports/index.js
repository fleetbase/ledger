import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class ReportsIndexController extends Controller {
    @service fetch;

    @tracked activeReport = 'balance-sheet';
    @tracked reportData = null;
    @tracked isLoading = false;
    @tracked dateFrom = null;
    @tracked dateTo = null;
    @tracked asOf = null;

    reportTabs = [
        { id: 'balance-sheet', label: 'Balance Sheet' },
        { id: 'income-statement', label: 'Income Statement' },
        { id: 'cash-flow', label: 'Cash Flow' },
        { id: 'ar-aging', label: 'AR Aging' },
    ];

    @task *loadReport() {
        this.isLoading = true;
        try {
            const params = {};
            if (this.dateFrom) params.date_from = this.dateFrom;
            if (this.dateTo) params.date_to = this.dateTo;
            if (this.asOf) params.as_of = this.asOf;

            const endpointMap = {
                'balance-sheet': 'reports/balance-sheet',
                'income-statement': 'reports/income-statement',
                'cash-flow': 'reports/cash-flow',
                'ar-aging': 'reports/ar-aging',
            };

            const result = yield this.fetch.get(endpointMap[this.activeReport], params, { namespace: 'ledger/int/v1' });
            this.reportData = result?.data ?? null;
        } catch {
            this.reportData = null;
        } finally {
            this.isLoading = false;
        }
    }

    @action selectReport(reportId) {
        this.activeReport = reportId;
        this.reportData = null;
        this.loadReport.perform();
    }

    @action runReport() {
        this.loadReport.perform();
    }
}
