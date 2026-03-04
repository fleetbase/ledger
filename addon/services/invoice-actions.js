import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import { titleize } from '@ember/string';

export default class InvoiceActionsService extends ResourceActionService {
    @service fetch;
    @service store;

    constructor() {
        super(...arguments);
        this.initialize('ledger-invoice', {
            permissionPrefix: 'ledger',
            mountPrefix: 'console.ledger',
        });
    }

    transition = {
        view: (invoice) => this.transitionTo('billing.invoices.index.details', invoice),
        create: () => this.transitionTo('billing.invoices.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const invoice = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'invoice/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.invoice')?.toLowerCase() }),
                saveOptions: { callback: this.refresh },
                saveTask: this.saveTask,
                invoice,
                ...options,
            });
        },

        edit: (invoice, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'invoice/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: invoice.public_id }),
                saveTask: this.saveTask,
                invoice,
                ...options,
            });
        },

        view: (invoice, options = {}) => {
            return this.resourceContextPanel.open({
                invoice,
                tabs: [{ label: this.intl.t('common.overview'), component: 'invoice/details' }],
                ...options,
            });
        },
    };

    /**
     * Custom save task that sends the nested `items` array in the payload
     * alongside all scalar invoice attributes, bypassing Ember Data's default
     * serialiser which does not include hasMany relationships.
     *
     * The `_pendingItems` property is set by the invoice/form component's
     * `onItemsChange` action whenever the user edits line items.
     */
    @task *saveTask(record, options = {}) {
        const isNew = record?.isNew;

        try {
            // Collect pending items written by the form component
            const items = (record._pendingItems ?? record.items?.toArray?.() ?? []).map((item) => ({
                uuid:        item.uuid?.startsWith?.('_') ? null : (item.uuid ?? null),
                description: item.description ?? '',
                quantity:    item.quantity ?? 1,
                unit_price:  item.unit_price ?? 0,
                tax_rate:    item.tax_rate ?? 0,
            }));

            const payload = {
                invoice: {
                    number:        record.number,
                    status:        record.status,
                    currency:      record.currency,
                    customer_uuid: record.customer_uuid,
                    customer_type: record.customer_type,
                    template_uuid: record.template_uuid,
                    date:          record.date,
                    due_date:      record.due_date,
                    notes:         record.notes,
                    terms:         record.terms,
                    meta:          record.meta,
                    items,
                },
            };

            let response;

            if (isNew) {
                response = yield this.fetch.post('invoices', payload);
            } else {
                const id = record.id ?? record.uuid;
                response = yield this.fetch.put(`invoices/${id}`, payload);
            }

            // Push the response back into the Ember Data store
            const normalised = this.store.normalize('ledger-invoice', response.invoice ?? response);
            this.store.push(normalised);

            this.notifications.success(
                this.intl.t('common.resource-action-success', {
                    resource: titleize('invoice'),
                    resourceName: response.invoice?.number ?? record.number ?? '',
                    action: isNew ? 'created' : 'updated',
                })
            );

            if (isNew) {
                this.events.trackResourceCreated(record);
            } else {
                this.events.trackResourceUpdated(record);
            }

            if (options.refresh) {
                this.refresh();
            }

            if (typeof options.callback === 'function') {
                options.callback(record);
            }

            return record;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }
}
