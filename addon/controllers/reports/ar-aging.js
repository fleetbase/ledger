import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';

export default class ReportsArAgingController extends Controller {
    @service fetch;
    @service currentUser;

    @tracked data = null;
    @tracked as_of = null;

    get companyCurrency() {
        return this.currentUser.getCompany()?.currency ?? 'USD';
    }

    get bucketList() {
        if (!this.data?.buckets) return [];
        return Object.values(this.data.buckets);
    }

    get bucketSummary() {
        if (!this.data?.buckets) return [];
        return Object.values(this.data.buckets).map((b) => ({
            label: b.label,
            total: b.total ?? 0,
        }));
    }

    get allInvoices() {
        if (!this.data?.buckets) return [];
        return Object.values(this.data.buckets).flatMap((b) =>
            (b.invoices ?? []).map((inv) => ({ ...inv, bucketLabel: b.label, daysRange: b.days_range }))
        );
    }

    @task *loadReport() {
        try {
            const params = {};
            if (this.as_of) params.as_of_date = this.as_of;
            const result = yield this.fetch.get('reports/ar-aging', params, { namespace: 'ledger/int/v1' });
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
