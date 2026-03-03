import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';

export default class ReportsTrialBalanceController extends Controller {
    @service fetch;
    @service currentUser;

    @tracked data = null;
    @tracked as_of = null;

    get companyCurrency() {
        return this.currentUser.getCompany()?.currency ?? 'USD';
    }

    @task *loadReport() {
        try {
            const params = {};
            if (this.as_of) params.as_of_date = this.as_of;
            const result = yield this.fetch.get('reports/trial-balance', params, { namespace: 'ledger/int/v1' });
            const raw = result?.data ?? null;
            if (!raw) {
                this.data = null;
                return;
            }
            // Normalise: backend returns accounts[].account (full model), debit_total, credit_total
            // Template expects accounts[].{code, name, type, debit, credit}, total_debits, total_credits
            this.data = {
                as_of_date: raw.as_of_date,
                balanced: raw.balanced,
                total_debits: raw.debit_total,
                total_credits: raw.credit_total,
                accounts: (raw.accounts ?? []).map((row) => ({
                    code: row.account?.code ?? row.code ?? '',
                    name: row.account?.name ?? row.name ?? '',
                    type: row.account?.type ?? row.type ?? '',
                    debit: row.debit_total ?? (row.balance > 0 && row.account?.is_debit_normal ? row.balance : 0),
                    credit: row.credit_total ?? (row.balance > 0 && !row.account?.is_debit_normal ? row.balance : 0),
                })),
            };
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
