#!/usr/bin/env bash
set -euo pipefail

TOKEN_FILE="${TALER_MERCHANT_TOKEN_FILE:-/ledger/docker/taler/generated-token.txt}"
MERCHANT_BASE_URL="${TALER_MERCHANT_PUBLIC_BASE_URL:-http://taler-merchant.lvh.me:9966}"
EXCHANGE_BASE_URL="${TALER_EXCHANGE_PUBLIC_BASE_URL:-http://taler-exchange.lvh.me:8081}"
BANK_BASE_URL="${TALER_BANK_PUBLIC_BASE_URL:-http://taler-bank.lvh.me:8082}"
INSTANCE_ID="${TALER_MERCHANT_INSTANCE_ID:-default}"

wait_json_endpoint() {
  local name="$1"
  local url="$2"

  for _ in $(seq 1 120); do
    if curl -fsS "${url}" | jq -e type >/dev/null 2>&1; then
      return 0
    fi

    sleep 1
  done

  echo "Timed out waiting for ${name} at ${url}." >&2
  return 1
}

wait_json_endpoint bank "${BANK_BASE_URL}/config"
wait_json_endpoint exchange "${EXCHANGE_BASE_URL}/keys"
wait_json_endpoint merchant "${MERCHANT_BASE_URL}/config"

echo "Checking bank config..."
curl -fsS "${BANK_BASE_URL}/config" | jq '.name, .currency'

echo "Checking exchange keys..."
curl -fsS "${EXCHANGE_BASE_URL}/keys" | jq '.base_url, .currency, .master_public_key'

echo "Checking merchant config..."
curl -fsS "${MERCHANT_BASE_URL}/config" | jq '.currency, .exchanges'

echo "Checking merchant instance accounts..."
token="$(cat "${TOKEN_FILE}")"
curl -fsS -H "Authorization: ${token}" "${MERCHANT_BASE_URL}/instances/${INSTANCE_ID}/private/accounts" | jq '.accounts'

echo "Creating a minimal merchant order..."
order_id="fleetbase-smoke-$(date +%s)"
curl -fsS \
  -H "Authorization: ${token}" \
  -H "Content-Type: application/json" \
  --data "$(jq -n --arg order_id "${order_id}" '{order_id: $order_id, order: {amount: "KUDOS:0.01", summary: "Fleetbase Taler smoke test"}}')" \
  "${MERCHANT_BASE_URL}/instances/${INSTANCE_ID}/private/orders" | jq '.'

echo "Smoke test passed."
