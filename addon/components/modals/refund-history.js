import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class ModalsRefundHistoryComponent extends Component {
    @tracked email = this.args.options.customerEmail ?? '';

    get refunds() {
        return this.args.options.refunds ?? [];
    }

    get hasRefunds() {
        return this.refunds.length > 0;
    }

    @action setEmail(event) {
        this.email = event.target.value;
    }

    @action sendRefundUri(refund) {
        return this.args.options.sendRefundUri?.(refund, this.email);
    }

    @action verifyRefundStatus(refund) {
        return this.args.options.verifyRefundStatus?.(refund);
    }
}
