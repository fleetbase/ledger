import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';

export default class ReportsBalanceSheetController extends Controller {
    @service fetch;
    @service currentUser;

    @tracked data = null;
    @tracked dateRange = null;
    @tracked as_of = null;

    get companyCurrency() {
        return this.currentUser.getCompany()?.currency ?? 'USD';
    }

    @task *loadReport() {
        try {
            const params = {};
            if (this.as_of) params.as_of_date = this.as_of;
            const result = yield this.fetch.get('reports/balance-sheet', params, { namespace: 'ledger/int/v1' });
            this.data = result?.data ?? null;
        } catch {
            this.data = null;
        }
    }

    @action reload() {
        this.loadReport.perform();
    }

    @action onDateChanged({ formattedDate }) {
        if (isArray(formattedDate) && formattedDate.length > 0) {
            this.as_of = formattedDate[0];
        } else {
            this.as_of = null;
        }
        this.loadReport.perform();
    }
}
