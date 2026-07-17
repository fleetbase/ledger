import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

const DRIVER_COPY = {
    taler: {
        name: 'GNU Taler',
        icon: 'wallet',
        type: 'Wallet payment',
        description: 'Privacy-preserving wallet payments, QR checkout, webhook confirmation, refunds, and settlement verification.',
    },
    stripe: {
        name: 'Stripe',
        icon: 'credit-card',
        type: 'Cards and wallets',
        description: 'Card and digital wallet processing with webhook confirmation and refund support.',
    },
    cash: {
        name: 'Cash',
        icon: 'money-bill-wave',
        type: 'Manual payment',
        description: 'Record offline or in-person payments without external webhook provisioning.',
    },
    qpay: {
        name: 'QPay',
        icon: 'qrcode',
        type: 'Regional payment',
        description: 'Regional gateway support for QR and local payment collection flows.',
    },
};

export default class GatewayHubComponent extends Component {
    @service gatewayActions;
    @tracked table;

    get gateways() {
        return Array.from(this.args.gateways ?? []);
    }

    get summary() {
        return this.args.summary?.summary ?? {};
    }

    get drivers() {
        return Array.from(this.args.drivers ?? []);
    }

    get driverCards() {
        const configuredByDriver = new Map();

        this.gateways.forEach((gateway) => {
            const list = configuredByDriver.get(gateway.driver) ?? [];
            list.push(gateway);
            configuredByDriver.set(gateway.driver, list);
        });

        return ['taler', 'stripe', 'cash', 'qpay'].map((code) => {
            const manifest = this.drivers.find((driver) => driver.code === code) ?? {};
            const copy = DRIVER_COPY[code];
            const configured = configuredByDriver.get(code) ?? [];
            const active = configured.filter((gateway) => gateway.status === 'active');

            return {
                code,
                name: manifest.name ?? copy.name,
                icon: copy.icon,
                type: copy.type,
                description: copy.description,
                capabilities: manifest.capabilities ?? [],
                configuredCount: configured.length,
                activeCount: active.length,
                primaryGateway: configured[0],
                connected: configured.length > 0,
            };
        });
    }

    get hasGatewayConnections() {
        return this.gateways.length > 0;
    }

    get warningCount() {
        return Number(this.summary.webhook_warnings ?? 0);
    }

    get statusTiles() {
        return [
            {
                label: 'Active gateways',
                value: this.summary.active_gateways ?? 0,
                caption: 'Ready to collect payments',
                icon: 'plug',
                accentClass: Number(this.summary.active_gateways ?? 0) > 0 ? 'ledger-gateway-kpi-accent-green' : 'ledger-gateway-kpi-accent-blue',
            },
            {
                label: 'Live ready',
                value: this.summary.live_gateways ?? 0,
                caption: 'Production connections',
                icon: 'bolt',
                accentClass: Number(this.summary.live_gateways ?? 0) > 0 ? 'ledger-gateway-kpi-accent-blue' : 'ledger-gateway-kpi-accent-slate',
            },
            {
                label: 'Webhook issues',
                value: this.warningCount,
                caption: this.warningCount > 0 ? 'Need provisioning review' : 'No open warnings',
                icon: 'link',
                accentClass: this.warningCount > 0 ? 'ledger-gateway-kpi-accent-amber' : 'ledger-gateway-kpi-accent-green',
            },
            {
                label: 'Sandbox',
                value: this.summary.sandbox_gateways ?? 0,
                caption: 'Testing connections',
                icon: 'flask',
                accentClass: Number(this.summary.sandbox_gateways ?? 0) > 0 ? 'ledger-gateway-kpi-accent-amber' : 'ledger-gateway-kpi-accent-slate',
            },
        ];
    }

    @action setupTable(table) {
        this.table = table;
    }

    @action refresh() {
        if (typeof this.args.refresh === 'function') {
            this.args.refresh();
        }

        return this.gatewayActions.refresh();
    }

    @action openDriver(driver) {
        if (driver.primaryGateway) {
            return this.gatewayActions.transition.view(driver.primaryGateway);
        }

        return this.gatewayActions.transition.create(driver.code);
    }
}
