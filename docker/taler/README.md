# Fleetbase Ledger GNU Taler local stack

This directory owns the Docker artifacts for Ledger's local GNU Taler E2E test
stack. It runs matching local services instead of mixing a local merchant
backend with the public demo exchange:

- libeufin bank: `http://taler-bank.lvh.me:8082`
- Taler exchange: `http://taler-exchange.lvh.me:8081`
- Taler merchant backend: `http://taler-merchant.lvh.me:9966`

Start the stack from the Fleetbase repo root:

```sh
docker compose up -d --build taler-merchant application queue scheduler httpd console
```

After startup, use the generated merchant token in the Ledger Taler gateway
config:

```sh
cat packages/ledger/docker/taler/generated-token.txt
```

Use these local gateway values:

```txt
backend_url=http://taler-merchant.lvh.me:9966
instance_id=default
api_token=<exact contents of generated-token.txt>
```

For tenant-safe webhook routing on a multi-company instance, provide the target
Ledger company and gateway identifier before bootstrapping/registering the local
merchant webhook:

```sh
TALER_MERCHANT_WEBHOOK_COMPANY_UUID=<company_uuid>
TALER_MERCHANT_WEBHOOK_GATEWAY_ID=<gateway_public_id_or_uuid>
```

The registered Taler webhook body template posts:

```json
{
  "order_id": "${ORDER_ID}",
  "event_type": "${EVENT_TYPE}",
  "company_uuid": "<company_uuid>",
  "gateway_id": "<gateway_public_id_or_uuid>",
  "gateway_uuid": "<gateway_public_id_or_uuid>"
}
```

## Validate the Taler stack

Run the built-in smoke test:

```sh
docker compose exec taler-merchant fleetbase-taler-smoke-test
```

Or check the services manually:

```sh
curl http://taler-bank.lvh.me:8082/config
curl http://taler-exchange.lvh.me:8081/keys
curl http://taler-merchant.lvh.me:9966/config
```

The merchant logs should not show `Failed to download .../keys` or `Could not
decode /keys response`. Those errors were symptoms of using the public demo
exchange with a local merchant package.

## Demo KUDOS invoice fixture

Fleetbase does not expose `KUDOS` as a normal currency option. Ledger provides
a dedicated local fixture so the Taler wallet flow can be tested without making
KUDOS a business currency.

Run Ledger migrations and seed the demo invoice from the Fleetbase repo root:

```sh
docker compose exec application php artisan migrate
docker compose exec application php artisan db:seed --class="Fleetbase\\Ledger\\Seeders\\Testing\\TalerDemoSeeder"
```

To seed for a specific company:

```sh
docker compose exec -e TALER_DEMO_COMPANY_UUID=<company_uuid> application php artisan db:seed --class="Fleetbase\\Ledger\\Seeders\\Testing\\TalerDemoSeeder"
```

The default demo invoice amount is `KUDOS 0.50`. To seed a specific invoice
amount, use a decimal `KUDOS` value:

```sh
docker compose exec -e TALER_DEMO_COMPANY_UUID=<company_uuid> -e TALER_DEMO_AMOUNT=5.00 application php artisan db:seed --class="Fleetbase\\Ledger\\Seeders\\Testing\\TalerDemoSeeder"
```

By default, each seeder run creates a fresh payable FleetOps-style payload,
order, service quote, purchase rate, tracking number, core transaction,
transaction items, and sent Ledger invoice in `KUDOS`. This lets you repeat the
wallet checkout flow without reusing a Taler order that has already been paid.
The order should appear in FleetOps Orders as `TALER-DEMO-KUDOS-<run_suffix>`.
Open the seeded public payment link shown by the seeder:

```txt
/~/invoice?id=<invoice_public_id>
```

To intentionally update the same demo fixture, provide a stable run id:

```sh
docker compose exec -e TALER_DEMO_COMPANY_UUID=<company_uuid> -e TALER_DEMO_RUN_ID=nlnet-demo-001 application php artisan db:seed --class="Fleetbase\\Ledger\\Seeders\\Testing\\TalerDemoSeeder"
```

## Wallet notes

The local bank suggests the local exchange to wallets. To add KUDOS to the GNU
Taler browser wallet, create a bank withdrawal operation:

```sh
docker compose exec taler-merchant fleetbase-taler-create-withdrawal KUDOS:5.00
```

Open the normal HTTP page printed by the command:

```txt
http://taler-bank.lvh.me:8082/webui/#/operation/<withdrawal_id>
```

Then click the wallet action from that page. Do not paste the raw
`taler+http://withdraw/...` URI into the browser address bar; browser extensions
generally intercept Taler wallet links from supported pages, not direct address
bar navigation.

The fake bank admin credentials are:

```txt
Username: admin
Password: admin-password
```

If your wallet blocks plain HTTP, use the wallet's development/test setting that
allows unsafe HTTP for local Taler services, or use `taler-wallet-cli --no-http`
for CLI testing.
