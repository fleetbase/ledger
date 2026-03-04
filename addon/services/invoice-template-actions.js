import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';

export default class InvoiceTemplateActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('template', {
            permissionPrefix: 'ledger',
            mountPrefix: 'console.ledger',
        });
    }

    transition = {
        create: () => this.transitionTo('billing.invoice-templates.index.new'),
        edit: (template) => this.transitionTo('billing.invoice-templates.index.edit', template.id),
    };

    /**
     * Fetch a rendered HTML preview of the given template data and display it
     * inside a modal, following the same pattern as fleetops viewLabel.
     *
     * @param {Object} templateData - The current (possibly unsaved) template payload
     *   from the builder, or a saved Template model for a quick-preview from the list.
     * @param {Object} [options={}]  - Optional overrides forwarded to modalsManager.show
     */
    @action async preview(templateData, options = {}) {
        const title = templateData?.name ?? this.intl.t('ledger.modals.template-preview.title');

        // Open the modal immediately with a loading state so the user gets
        // instant feedback, then populate it once the fetch resolves.
        this.modalsManager.show('modals/template-preview', {
            title,
            modalClass: 'modal-xl',
            acceptButtonText: this.intl.t('common.done'),
            hideDeclineButton: true,
            isLoading: true,
            html: null,
            ...options,
        });

        try {
            const isPersistedTemplate = templateData?.id && !templateData?.isNew;
            const endpoint = isPersistedTemplate ? `templates/${templateData.id}/preview` : 'templates/preview';

            const { html } = await this.fetch.post(endpoint, { template: templateData });

            this.modalsManager.setOptions({
                isLoading: false,
                html,
            });
        } catch (err) {
            this.notifications.serverError(err);
            this.modalsManager.done();
        }
    }
}
