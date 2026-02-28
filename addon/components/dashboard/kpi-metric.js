import Component from '@glimmer/component';
import { computed } from '@ember/object';

export default class DashboardKpiMetricComponent extends Component {
    get formattedValue() {
        const { value, currency } = this.args;
        if (value === undefined || value === null) return '—';
        const amount = value / 100;
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: currency || 'USD' }).format(amount);
    }

    get changeLabel() {
        const change = this.args.change;
        if (change === undefined || change === null) return null;
        const sign = change >= 0 ? '+' : '';
        return `${sign}${change.toFixed(1)}%`;
    }

    get changeColor() {
        const change = this.args.change;
        if (change === undefined || change === null) return 'gray';
        const positive = change >= 0;
        return this.args.inverse ? (positive ? 'red' : 'green') : (positive ? 'green' : 'red');
    }
}
