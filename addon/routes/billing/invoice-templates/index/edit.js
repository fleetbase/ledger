import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Normalise the context-schemas API response into the flat array that the
 * TemplateBuilder variable-picker component expects.
 *
 * API response shape:
 *   { schemas: { "ledger-invoice": { label, variables: [...], global_variables: [...] }, ... } }
 *
 * Expected component shape:
 *   [ { namespace, label, icon, variables: [ { path, label, type, description } ] } ]
 *
 * Rules:
 *  - Skip the "generic" namespace — its variables are already shown in the
 *    hard-coded "Global" section of the variable picker.
 *  - Merge `variables` and `global_variables` into a single `variables` array
 *    for every other namespace.
 *  - Normalise each variable so it always has `label` (falling back to `name`)
 *    and `description`.
 */
function normaliseContextSchemas(response) {
    if (Array.isArray(response)) return response; // already normalised

    const raw = response?.schemas ?? response ?? {};
    if (typeof raw !== 'object' || Array.isArray(raw)) return [];

    return Object.entries(raw)
        .filter(([ns]) => ns !== 'generic')
        .map(([namespace, schema]) => ({
            namespace,
            label: schema.label ?? namespace,
            icon: schema.icon ?? 'tag',
            variables: [
                ...(schema.variables ?? []),
                ...(schema.global_variables ?? []),
            ].map((v) => ({
                path: v.path,
                label: v.label ?? v.name ?? v.path,
                type: v.type ?? 'string',
                description: v.description ?? '',
                example: v.example ?? '',
            })),
        }))
        .filter((schema) => schema.variables.length > 0);
}

export default class BillingInvoiceTemplatesIndexEditRoute extends Route {
    @service store;
    @service fetch;

    async model({ id }) {
        const [template, rawSchemas] = await Promise.all([
            this.store.findRecord('template', id),
            this.fetch.get('templates/context-schemas', { for: 'ledger-invoice' }).catch(() => ({})),
        ]);

        return { template, contextSchemas: normaliseContextSchemas(rawSchemas) };
    }
}
