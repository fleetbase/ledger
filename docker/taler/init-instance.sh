#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${TALER_MERCHANT_BASE_URL:-http://127.0.0.1:9966}"
PUBLIC_BASE_URL="${TALER_MERCHANT_PUBLIC_BASE_URL:-http://taler-merchant.localhost:9966}"
ADMIN_TOKEN="${TALER_MERCHANT_ADMIN_TOKEN:-secret-token:fleetbase-taler-admin-dev}"
INSTANCE_ID="${TALER_MERCHANT_INSTANCE_ID:-default}"
INSTANCE_PASSWORD="${TALER_MERCHANT_INSTANCE_PASSWORD:-fleetbase-taler-dev-password}"
TOKEN_FILE="${TALER_MERCHANT_TOKEN_FILE:-/var/lib/fleetbase-ledger/taler/generated-token.txt}"
WEBHOOK_URL="${TALER_MERCHANT_WEBHOOK_URL:-http://httpd/ledger/webhooks/taler}"
WEBHOOK_COMPANY_UUID="${TALER_MERCHANT_WEBHOOK_COMPANY_UUID:-}"
WEBHOOK_GATEWAY_ID="${TALER_MERCHANT_WEBHOOK_GATEWAY_ID:-}"
PAYTO_URI="${TALER_MERCHANT_PAYTO_URI:-payto://x-taler-bank/taler-bank.lvh.me/default?receiver-name=Fleetbase%20Ledger}"
MERCHANT_BANK_USER="${TALER_MERCHANT_BANK_USER:-default}"
MERCHANT_BANK_PASSWORD="${TALER_MERCHANT_BANK_PASSWORD:-fleetbase-merchant-bank-password}"
MERCHANT_BANK_REVENUE_URL="${TALER_MERCHANT_BANK_REVENUE_URL:-http://taler-bank.lvh.me:8082/accounts/${MERCHANT_BANK_USER}/taler-revenue/}"

api() {
  local method="$1"
  local path="$2"
  local data="${3:-}"
  local auth="${4:-Bearer ${ADMIN_TOKEN}}"
  local code
  local body_file
  local args

  body_file="$(mktemp)"
  args=(-sS -o "${body_file}" -w "%{http_code}" -X "${method}")
  if [ -n "${auth}" ]; then
    args+=(-H "Authorization: ${auth}")
  fi
  if [ -n "${data}" ]; then
    args+=(-H "Content-Type: application/json" --data "${data}")
  fi
  args+=("${BASE_URL}${path}")

  code="$(curl "${args[@]}")"

  cat "${body_file}"
  rm -f "${body_file}"
  printf '\n%s' "${code}"
}

until curl -fsS "${BASE_URL}/config" >/dev/null 2>&1; do
  echo "Waiting for Taler merchant backend at ${BASE_URL}..."
  sleep 2
done

instance_payload="$(jq -n \
  --arg id "${INSTANCE_ID}" \
  --arg name "Fleetbase Ledger Local Test Merchant" \
  --arg website "${PUBLIC_BASE_URL}" \
  --arg password "${INSTANCE_PASSWORD}" \
  '{
    id: $id,
    name: $name,
    email: "dev@fleetbase.io",
    website: $website,
    auth: {
      method: "token",
      password: $password
    },
    address: {
      country: "US",
      town: "Local Development",
      address_lines: ["Fleetbase Ledger Taler test merchant"]
    },
    jurisdiction: {
      country: "US",
      town: "Local Development",
      address_lines: ["Fleetbase Ledger Taler test merchant"]
    },
    use_stefan: true
  }')"

instance_response="$(api POST /instances "${instance_payload}" "")"
instance_code="$(printf '%s' "${instance_response}" | tail -n1)"
if [ "${instance_code}" != "200" ] && [ "${instance_code}" != "204" ] && [ "${instance_code}" != "409" ]; then
  echo "Unable to create Taler merchant instance '${INSTANCE_ID}' (HTTP ${instance_code})." >&2
  printf '%s\n' "${instance_response}" >&2
  exit 1
fi

token_payload="$(jq -n '{scope: "all", description: "Fleetbase Ledger local development"}')"
token_response="$(api POST "/instances/${INSTANCE_ID}/private/token" "${token_payload}" "Basic $(printf '%s:%s' "${INSTANCE_ID}" "${INSTANCE_PASSWORD}" | base64 | tr -d '\n')")"
token_code="$(printf '%s' "${token_response}" | tail -n1)"
token_body="$(printf '%s' "${token_response}" | sed '$d')"
if [ "${token_code}" != "200" ]; then
  echo "Unable to create Taler merchant API token (HTTP ${token_code})." >&2
  printf '%s\n' "${token_body}" >&2
  exit 1
fi

mkdir -p "$(dirname "${TOKEN_FILE}")"
token="$(printf '%s\n' "${token_body}" | jq -r '.access_token // .token')"
case "${token}" in
  Bearer\ *)
    ;;
  *)
    token="Bearer ${token}"
    ;;
esac
printf '%s\n' "${token}" > "${TOKEN_FILE}"
chmod 0600 "${TOKEN_FILE}"

auth_header="$(cat "${TOKEN_FILE}")"

account_payload="$(jq -n \
  --arg payto_uri "${PAYTO_URI}" \
  --arg credit_facade_url "${MERCHANT_BANK_REVENUE_URL}" \
  --arg bank_user "${MERCHANT_BANK_USER}" \
  --arg bank_password "${MERCHANT_BANK_PASSWORD}" \
  '{
    payto_uri: $payto_uri,
    credit_facade_url: $credit_facade_url,
    credit_facade_credentials: {
      type: "basic",
      username: $bank_user,
      password: $bank_password
    }
  }')"
account_response="$(api POST "/instances/${INSTANCE_ID}/private/accounts" "${account_payload}" "${auth_header}")"
account_code="$(printf '%s' "${account_response}" | tail -n1)"
if [ "${account_code}" != "200" ] && [ "${account_code}" != "409" ]; then
  echo "Unable to add Taler merchant payto account (HTTP ${account_code})." >&2
  printf '%s\n' "${account_response}" >&2
  exit 1
fi

webhook_payload="$(jq -n \
  --arg url "${WEBHOOK_URL}" \
  --arg company_uuid "${WEBHOOK_COMPANY_UUID}" \
  --arg gateway_id "${WEBHOOK_GATEWAY_ID}" \
  '{
    webhook_id: "fleetbase-ledger-pay",
    event_type: "pay",
    url: $url,
    http_method: "POST",
    header_template: "Content-Type: application/json",
    body_template: ("{\"order_id\":\"${ORDER_ID}\",\"event_type\":\"${EVENT_TYPE}\",\"company_uuid\":\"" + $company_uuid + "\",\"gateway_id\":\"" + $gateway_id + "\",\"gateway_uuid\":\"" + $gateway_id + "\"}")
  }')"

webhook_response="$(api POST "/instances/${INSTANCE_ID}/private/webhooks" "${webhook_payload}" "${auth_header}")"
webhook_code="$(printf '%s' "${webhook_response}" | tail -n1)"
if [ "${webhook_code}" != "204" ] && [ "${webhook_code}" != "409" ]; then
  echo "Unable to register Fleetbase Ledger Taler webhook (HTTP ${webhook_code})." >&2
  printf '%s\n' "${webhook_response}" >&2
  exit 1
fi

echo "Taler merchant instance '${INSTANCE_ID}' is ready."
echo "Ledger gateway API token written to ${TOKEN_FILE}."
