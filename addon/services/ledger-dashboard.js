import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';

function formatDate(date) {
    const year = date.getFullYear();
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${year}-${month}-${day}`;
}

export default class LedgerDashboardService extends Service {
    @tracked startDate = null;
    @tracked endDate = null;
    @tracked dateRange = null;
    @tracked version = 0;

    subscribers = new Set();

    constructor() {
        super(...arguments);
        this.resetPeriod({ notify: false });
    }

    get periodParams() {
        return {
            start_date: this.startDate,
            end_date: this.endDate,
        };
    }

    get walletPeriodParams() {
        return {
            date_from: this.startDate,
            date_to: this.endDate,
        };
    }

    get asOfParams() {
        return {
            as_of_date: this.endDate,
        };
    }

    get periodLabel() {
        return this.startDate && this.endDate ? `${this.startDate} - ${this.endDate}` : 'Month to date';
    }

    get dateRangeValue() {
        return this.startDate && this.endDate ? `${this.startDate},${this.endDate}` : null;
    }

    setDateRange({ formattedDate } = {}) {
        if (Array.isArray(formattedDate) && formattedDate.length === 2) {
            this.setPeriod(formattedDate[0], formattedDate[1]);
            return;
        }

        this.resetPeriod();
    }

    setPeriod(startDate, endDate) {
        this.startDate = startDate;
        this.endDate = endDate;
        this.dateRange = [startDate, endDate];
        this.notify();
    }

    resetPeriod({ notify = true } = {}) {
        const today = new Date();
        const start = new Date(today.getFullYear(), today.getMonth(), 1);

        this.startDate = formatDate(start);
        this.endDate = formatDate(today);
        this.dateRange = [this.startDate, this.endDate];

        if (notify) {
            this.notify();
        }
    }

    subscribe(callback) {
        this.subscribers.add(callback);

        return () => {
            this.subscribers.delete(callback);
        };
    }

    notify() {
        this.version++;
        this.subscribers.forEach((callback) => callback(this));
    }
}
