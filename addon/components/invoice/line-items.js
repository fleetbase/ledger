import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class InvoiceLineItemsComponent extends Component {
    @service store;

    @tracked items = [];

    constructor() {
        super(...arguments);
        this.items = [...(this.args.items ?? [])];
        if (typeof this.args.registerRef === 'function') {
            this.args.registerRef(this);
        }
    }

    get subtotal() {
        return this.items.reduce((sum, item) => sum + (item.computedAmount ?? 0), 0);
    }

    get tax() {
        return this.items.reduce((sum, item) => sum + (item.computedTaxAmount ?? 0), 0);
    }

    get total() {
        return this.subtotal + this.tax;
    }

    getItems() {
        return this.items;
    }

    /**
     * Called by the controller after a successful save to replace any
     * store.createRecord stubs with the real persisted records returned
     * by the server response.  Prevents duplicate rows after save.
     */
    resetItems(invoice) {
        // Unload any store.createRecord stubs before reading the server response.
        // After save(), Ember Data pushes the real persisted records into the store
        // but does NOT automatically unload the isNew stubs, so invoice.items
        // would contain both — causing duplicate rows.
        for (const item of this.items) {
            if (item.isNew) {
                item.unloadRecord();
            }
        }
        this.items = invoice?.items?.toArray?.() ?? [];
    }

    @action addItem() {
        const newItem = this.store.createRecord('ledger-invoice-item', {
            invoice: this.args.invoice,
            description: '',
            quantity: 1,
            unit_price: '0',
            tax_rate: 0,
        });
        this.items = [...this.items, newItem];
    }

    @action removeItem(item) {
        if (item.isNew) {
            item.unloadRecord();
        } else {
            item.deleteRecord();
        }
        this.items = this.items.filter((i) => i !== item);
    }

    @action updateUnitPrice(item, storedValue) {
        item.unit_price = storedValue;
    }

    @action updateQuantity(item, event) {
        item.quantity = Math.max(1, parseInt(event.target.value, 10) || 1);
    }

    @action updateTaxRate(item, event) {
        item.tax_rate = Math.max(0, parseFloat(event.target.value) || 0);
    }
}
