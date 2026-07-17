import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class ModalsIssueRefundComponent extends Component {
    @tracked selectedGatewayTransactionId = this.args.options.selectedGatewayTransactionId;
    @tracked refundMode = this.args.options.refundMode ?? 'full';
    @tracked amount = this.args.options.amount ?? 0;
    @tracked reason = this.args.options.reason ?? '';

    get refundModes() {
        return [
            { label: 'Full remaining amount', value: 'full' },
            { label: 'Custom partial amount', value: 'partial' },
        ];
    }

    get selectedOption() {
        return this.args.options.refundOptions?.find((option) => option.gateway_transaction_id === this.selectedGatewayTransactionId);
    }

    get isCustomRefund() {
        return this.refundMode === 'partial';
    }

    get requiresCustomerAction() {
        return Boolean(this.selectedOption?.requires_customer_action);
    }

    @action selectGatewayTransaction(gatewayTransactionId) {
        this.selectedGatewayTransactionId = gatewayTransactionId;
        this.args.options.selectedGatewayTransactionId = gatewayTransactionId;

        if (this.refundMode === 'full') {
            this.setAmount(this.selectedOption?.refundable_amount ?? 0);
        }
    }

    @action setRefundMode(refundMode) {
        this.refundMode = refundMode;
        this.args.options.refundMode = refundMode;

        if (refundMode === 'full') {
            this.setAmount(this.selectedOption?.refundable_amount ?? 0);
        }
    }

    @action setAmount(amount) {
        this.amount = amount;
        this.args.options.amount = amount;
    }

    @action setReason(event) {
        this.reason = event.target.value;
        this.args.options.reason = this.reason;
    }
}
