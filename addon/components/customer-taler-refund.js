import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

export default class CustomerTalerRefundComponent extends Component {
    @service fetch;
    @service notifications;
    @service urlSearchParams;

    @tracked refund = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.installTalerSupportMeta();
        this.loadRefund.perform();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.removeTalerSupportMeta();
    }

    get refundId() {
        return this.urlSearchParams.get('id');
    }

    get talerRefundUri() {
        return this.refund?.taler_refund_uri;
    }

    get qrImageSrc() {
        const qrImage = this.refund?.qr_image;

        if (typeof qrImage !== 'string' || qrImage.length === 0) {
            return null;
        }

        if (qrImage.startsWith('data:') || qrImage.startsWith('http://') || qrImage.startsWith('https://')) {
            return qrImage;
        }

        return `data:image/png;base64,${qrImage}`;
    }

    @task({ restartable: true })
    *loadRefund() {
        this.error = null;

        if (!this.refundId) {
            this.error = 'No refund identifier provided. Please check the link and try again.';
            return;
        }

        try {
            const result = yield this.fetch.get(`refunds/${this.refundId}`, {}, { namespace: 'ledger/public' });
            this.refund = result?.refund ?? result;
            this.updateTalerUriMeta();
        } catch (error) {
            const status = error?.status ?? error?.response?.status;
            this.error = status === 404 ? 'Refund not found. Please check the link and try again.' : error?.message ?? 'Failed to load refund. Please try again later.';
        }
    }

    @action copyRefundUri() {
        if (!this.talerRefundUri) {
            return;
        }

        navigator.clipboard?.writeText(this.talerRefundUri);
        this.notifications.success('Refund URI copied.');
    }

    installTalerSupportMeta() {
        if (typeof document === 'undefined') {
            return;
        }

        let support = document.querySelector('meta[name="taler-support"]');

        if (!support) {
            support = document.createElement('meta');
            support.name = 'taler-support';
            support.dataset.ledgerTalerSupport = 'true';
            document.head.appendChild(support);
        }

        support.content = 'uri';
        this.updateTalerUriMeta();
    }

    updateTalerUriMeta() {
        if (typeof document === 'undefined' || !this.talerRefundUri) {
            return;
        }

        let uri = document.querySelector('meta[name="taler-uri"]');

        if (!uri) {
            uri = document.createElement('meta');
            uri.name = 'taler-uri';
            uri.dataset.ledgerTalerSupport = 'true';
            document.head.appendChild(uri);
        }

        uri.content = this.talerRefundUri;
    }

    removeTalerSupportMeta() {
        if (typeof document === 'undefined') {
            return;
        }

        document.querySelectorAll('meta[data-ledger-taler-support="true"]').forEach((meta) => meta.remove());
    }
}
