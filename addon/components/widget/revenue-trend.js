import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import getCurrency from '@fleetbase/ember-ui/utils/get-currency';

export default class WidgetRevenueTrendComponent extends Component {
    @service fetch;
    @service ledgerDashboard;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.unsubscribeDashboard = this.ledgerDashboard.subscribe(() => this.load.perform());
        this.load.perform();
    }

    get queryParams() {
        return this.ledgerDashboard.periodParams;
    }

    get formattedRevenue() {
        return this.formatMinorCurrency(this.data?.summary?.revenue ?? 0);
    }

    get formattedNet() {
        return this.formatMinorCurrency(this.data?.summary?.net ?? 0);
    }

    get currencyCode() {
        return this.data?.currency ?? 'USD';
    }

    get currency() {
        return getCurrency(this.currencyCode) ?? getCurrency('USD');
    }

    get currencyDivisor() {
        const precision = Number(this.currency?.precision ?? 2);

        return precision > 0 ? 10 ** precision : 1;
    }

    get chartDatasets() {
        return (
            this.data?.datasets?.map((dataset) => ({
                ...dataset,
                data: dataset.data?.map((value) => this.normalizeMoneyValue(value)) ?? [],
            })) ?? []
        );
    }

    normalizeMoneyValue(value) {
        const numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            return value;
        }

        return numericValue / this.currencyDivisor;
    }

    formatChartCurrency(value) {
        const numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            return value;
        }

        const precision = Number(this.currency?.precision ?? 2);

        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: this.currencyCode,
                minimumFractionDigits: precision,
                maximumFractionDigits: precision,
            }).format(numericValue);
        } catch {
            return `${this.currency?.symbol ?? this.currencyCode} ${numericValue.toLocaleString()}`;
        }
    }

    formatMinorCurrency(value) {
        return this.formatChartCurrency(this.normalizeMoneyValue(value));
    }

    get chartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 6,
                        boxHeight: 6,
                        padding: 10,
                        font: { size: 10, weight: '600' },
                    },
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: (context) => `${context.dataset?.label ?? 'Value'}: ${this.formatChartCurrency(context.parsed?.y ?? context.raw)}`,
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { autoSkip: true, maxTicksLimit: 6, maxRotation: 0, minRotation: 0, font: { size: 10 } } },
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: this.currency?.precision ?? 2,
                        font: { size: 10 },
                        callback: (value) => this.formatChartCurrency(value),
                    },
                },
            },
            elements: { point: { radius: 0, hoverRadius: 4 } },
        };
    }

    @task *load() {
        try {
            const response = yield this.fetch.get('reports/dashboard/revenue-trend', this.queryParams, { namespace: 'ledger/int/v1' });
            this.data = response?.data ?? response;
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? 'Unable to load revenue trend';
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.unsubscribeDashboard?.();
    }
}
