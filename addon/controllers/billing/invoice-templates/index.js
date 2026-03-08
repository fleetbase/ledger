import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class BillingInvoiceTemplatesIndexController extends Controller {
    @service invoiceTemplateActions;
    @service tableContext;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query'];
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked table = null;

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.invoiceTemplateActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.invoiceTemplateActions.transition.create,
            },
        ];
    }

    get bulkActions() {
        const selected = this.tableContext.getSelectedRows();
        return [
            {
                label: this.intl.t('common.delete-selected-count', { count: selected.length }),
                class: 'text-red-500',
                fn: this.invoiceTemplateActions.bulkDelete,
            },
        ];
    }

    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                action: this.invoiceTemplateActions.transition.edit,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.description'),
                valuePath: 'description',
                resizable: true,
            },
            {
                label: this.intl.t('column.orientation'),
                valuePath: 'orientation',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.default'),
                valuePath: 'is_default',
                resizable: true,
                sortable: true,
            },
            {
                label:     this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                resizable: true,
                sortable:  true,
            },
            // ── Row actions dropdown ─────────────────────────────────────────
            {
                label:              '',
                cellComponent:      'table/cell/dropdown',
                ddButtonText:       false,
                ddButtonIcon:       'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel:        this.intl.t('common.resource-actions', { resource: this.intl.t('resource.invoice-template') }),
                cellClassNames:     'overflow-visible',
                wrapperClass:       'flex items-center justify-end mx-2',
                sticky:             'right',
                width:              60,
                sortable:           false,
                filterable:         false,
                resizable:          false,
                searchable:         false,
                actions: [
                    {
                        label:      this.intl.t('common.edit-resource', { resource: this.intl.t('resource.invoice-template') }),
                        icon:       'pencil',
                        fn:         this.invoiceTemplateActions.transition.edit,
                        permission: 'ledger update template',
                    },
                    {
                        label:      this.intl.t('common.view-resource', { resource: this.intl.t('resource.invoice-template') }),
                        icon:       'eye',
                        fn:         this.invoiceTemplateActions.preview,
                        permission: 'ledger view template',
                    },
                    {
                        label:      this.intl.t('common.delete-resource', { resource: this.intl.t('resource.invoice-template') }),
                        icon:       'trash',
                        fn:         this.invoiceTemplateActions.delete,
                        className:  'text-red-500 hover:text-red-700',
                        permission: 'ledger delete template',
                    },
                ],
            },
        ];
    }
}
