import Component from '@glimmer/component';
import { action } from '@ember/object';

const DRIVER_ICONS = {
    taler: 'wallet',
    stripe: 'credit-card',
    cash: 'money-bill-wave',
    qpay: 'qrcode',
};

export default class TableCellGatewayProviderComponent extends Component {
    get gateway() {
        return this.args.row;
    }

    get icon() {
        return DRIVER_ICONS[this.gateway?.driver] ?? 'plug';
    }

    get subtitle() {
        const driver = this.gateway?.driver_label ?? this.gateway?.driver;
        const environment = this.gateway?.environment;

        return [driver, environment].filter(Boolean).join(' / ');
    }

    @action onClick(event) {
        const { column, row } = this.args;

        if (typeof column?.action === 'function') {
            column.action(row, event);
        }
    }
}
