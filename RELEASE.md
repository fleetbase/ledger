> v0.0.10 ~ "The public wallet API is reachable"

---
## Highlights
All four public wallet routes answered `401` to every credential — including a driver's own Sanctum token, which authenticates fine against every other public endpoint. The API was effectively unusable for wallet operations.

---
## Bug Fixes
- **The four public wallet routes were unreachable by any credential.** The `fleetbase.api` middleware authenticates with `Auth::setSession()`, which writes the session keys but leaves `$login` false, so no user resolver is ever bound and `$request->user()` is null on every public API request. `WalletApiController` now falls back to the session identity that middleware actually records.
- **Unauthenticated requests returned an HTML page, not JSON.** `abort()` rendered Laravel's error page, so an API client parsing JSON received ~1.8 KB of markup titled "Unauthorized". The controller now throws `AuthenticationException`, which the API exception handler renders as `{"errors":["Unauthenticated."]}` with a 401.

---
## Continuous Integration
- The Postman API contract now runs against this branch's API code rather than the published package, so a release PR's own changes are actually exercised.
- The contract workflow tracks the current platform release instead of a pinned ref.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
