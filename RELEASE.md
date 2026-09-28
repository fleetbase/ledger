> v0.0.11 ~ "Currencies that save, and ledger on the default dashboard"

---
## Highlights
Ledger's widgets take a planned place on the console's Default Dashboard, and the base and invoice currencies chosen in settings are saved.

---
## Improvements
- **Ledger on the Default Dashboard.** Revenue sits in the top KPI row beside Fleet-Ops' Radar, Active Orders and Drivers Online. Expenses, Net Income, Outstanding AR and Overdue AR form the row under it; Overdue AR is new on the default dashboard. Recent Financial Activity and Cash Flow Summary sit in the lower left, as tall as the Blog and GitHub cards beside them. Ledger's own dashboard keeps its full widget set. The layout needs `@fleetbase/ember-ui` v0.4.4; on older versions the widgets appear as before.

---
## Bug Fixes
- **The base and invoice currencies never saved** ([fleetbase/fleetbase#678](https://github.com/fleetbase/fleetbase/issues/678)). `CurrencySelect` passes the ISO code first, but the Accounting and Invoice settings read `.code` from it, so Save stored `null` and the page fell back to the default after a reload.
- **A wallet's currency couldn't be changed.** Editing a wallet failed with `Column 'balance' cannot be null`, because the console sent the whole record back. The serializer no longer sends `balance` or `formatted_balance`, and `WalletController::updateRecord` drops them, so a balance only moves through transactions.

---
## Continuous Integration
- The release workflow accepts `release/v*` branches alongside `dev-v*`.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
