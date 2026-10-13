> v0.0.13 ~ "Transaction lists stay current when what they show changes"

---
## Bug Fixes

- **Stale transaction lists.** The cached transactions list holds its line items, journal entries with their debit and credit accounts, and each transaction's subject, payer, payee, initiator and context. Edits to any of those didn't invalidate the list, so they could show stale values until the cache expired. The list's cache key now carries the versions of those tables.

  Two exceptions: orders and drivers are written constantly, so a transaction's order or driver snapshot can lag by up to the five-minute list cache.

- **Public API requests weren't logged.** Writes to the public wallet API (`ledger/v1/...`) and the customer invoice routes (`ledger/public/...`, such as paying an invoice) are now written to the API request logs, like core's public API. Webhooks and the console's internal routes aren't logged.

---
## Upgrading
The transaction list fix takes effect with fleetbase/core-api's cache dependency support ([fleetbase/core-api#301](https://github.com/fleetbase/core-api/pull/301)). With an older core-api the change is inert.

API request logging takes effect with [fleetbase/core-api#302](https://github.com/fleetbase/core-api/pull/302), which logs every route that runs `LogApiRequests` rather than only `v1/...`. With an older core-api, ledger requests are still not logged.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---
