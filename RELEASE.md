> v0.0.12 ~ "Extensions can add columns, actions and buttons to ledger tables and panels"

---
## Highlights

- **Resource view registries.** Extensions can add the following through `ledger:<resource>:table:<slot>` and `ledger:<resource>:details:<slot>`:
  - columns, row actions, bulk actions and toolbar buttons on every ledger table;
  - header buttons and "…" menu items on every details panel.

  Resources: `account`, `gateway`, `invoice`, `invoice-template`, `journal`, `transaction`, `wallet`. Built-in items carry stable ids, so registered items can be placed before or after them.

---
## Upgrading
Needs fleetbase/ember-core v0.3.25 and fleetbase/ember-ui v0.4.5.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---
