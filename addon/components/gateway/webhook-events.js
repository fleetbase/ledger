import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class GatewayWebhookEventsComponent extends Component {
    @service fetch;
    @tracked events = [];
    @tracked meta = null;

    constructor(owner, args) {
        super(owner, args);
        this.loadEvents.perform();
    }

    @task *loadEvents() {
        const gateway = this.args.gateway;
        if (!gateway?.public_id) return;
        try {
            const result = yield this.fetch.get(`ledger/int/v1/gateways/${gateway.public_id}/transactions`, {
                params: { limit: 20 },
            });
            this.events = result?.data ?? [];
            this.meta = result?.meta ?? null;
        } catch {
            this.events = [];
        }
    }
}
