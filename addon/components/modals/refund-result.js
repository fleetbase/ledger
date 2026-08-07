import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class ModalsRefundResultComponent extends Component {
    @tracked email = this.args.options.customerEmail ?? '';

    get isCompleted() {
        return this.args.options.walletStatus === 'accepted' || this.args.options.refundStatus === 'refunded';
    }

    get statusTitle() {
        return this.isCompleted ? 'Refund completed' : 'Refund URI issued';
    }

    get statusMessage() {
        if (this.isCompleted) {
            return 'The refund was accepted by the customer wallet and marked complete.';
        }

        return 'Share this refund URI with the customer so they can open it with their GNU Taler wallet and accept the refund.';
    }

    @action setEmail(event) {
        this.email = event.target.value;
    }

    @action sendRefundUri() {
        return this.args.options.sendRefundUri?.(this.args.options.refund, this.email);
    }
}
