#!/usr/bin/env bash
set -euo pipefail

CONFIG_TEMPLATE="${TALER_CONFIG_TEMPLATE:-/etc/taler/taler-local.conf}"
CONFIG_FILE="${TALER_CONFIG_FILE:-/var/lib/fleetbase-ledger/taler/taler.conf}"
BANK_BASE_URL="${TALER_BANK_BASE_URL:-http://127.0.0.1:8082}"
EXCHANGE_BASE_URL="${TALER_EXCHANGE_BASE_URL:-http://127.0.0.1:8081}"
MERCHANT_BASE_URL="${TALER_MERCHANT_BASE_URL:-http://127.0.0.1:9966}"
EXCHANGE_PAYTO_URI="${TALER_EXCHANGE_PAYTO_URI:-payto://x-taler-bank/taler-bank.lvh.me/exchange?receiver-name=Fleetbase%20Local%20Exchange}"
TALER_CURRENCY="${TALER_CURRENCY:-KUDOS}"

pids=()
last_pid=""

start_service() {
  local name="$1"
  shift
  echo "Starting ${name}..."
  "$@" &
  last_pid="$!"
  pids+=("${last_pid}")
}

cleanup() {
  for pid in "${pids[@]:-}"; do
    if kill -0 "${pid}" >/dev/null 2>&1; then
      kill "${pid}" >/dev/null 2>&1 || true
    fi
  done
}

wait_json_endpoint() {
  local name="$1"
  local url="$2"
  local attempts="${3:-120}"

  for _ in $(seq 1 "${attempts}"); do
    if curl -fsS "${url}" | jq -e type >/dev/null 2>&1; then
      echo "${name} is ready at ${url}."
      return 0
    fi

    sleep 1
  done

  echo "Timed out waiting for ${name} at ${url}." >&2
  return 1
}

trap cleanup EXIT INT TERM

fleetbase-taler-init-stack

start_service "libeufin bank" libeufin-bank serve -c "${CONFIG_FILE}" -L INFO
wait_json_endpoint "libeufin bank" "${BANK_BASE_URL}/config"

start_service "exchange secmod eddsa" taler-exchange-secmod-eddsa -c "${CONFIG_FILE}" -L INFO
start_service "exchange secmod rsa" taler-exchange-secmod-rsa -c "${CONFIG_FILE}" -L INFO
start_service "exchange secmod cs" taler-exchange-secmod-cs -c "${CONFIG_FILE}" -L INFO
start_service "exchange wirewatch" taler-exchange-wirewatch -c "${CONFIG_FILE}" --longpoll-timeout=5s -L INFO
start_service "exchange transfer" taler-exchange-transfer -c "${CONFIG_FILE}" -L INFO
start_service "exchange aggregator" taler-exchange-aggregator -c "${CONFIG_FILE}" -L INFO
start_service "exchange httpd" taler-exchange-httpd -c "${CONFIG_FILE}" -L INFO
wait_json_endpoint "exchange management" "${EXCHANGE_BASE_URL}/management/keys"

taler-exchange-offline -c "${CONFIG_FILE}" download sign upload
taler-exchange-offline -c "${CONFIG_FILE}" enable-account "${EXCHANGE_PAYTO_URI}" upload
for year in $(seq "$(date +%Y)" "$(( $(date +%Y) + 4 ))"); do
  taler-exchange-offline -c "${CONFIG_FILE}" wire-fee "${year}" x-taler-bank "${TALER_CURRENCY}:0.00" "${TALER_CURRENCY}:0.00" upload
done
taler-exchange-offline -c "${CONFIG_FILE}" global-fee now "${TALER_CURRENCY}:0.00" "${TALER_CURRENCY}:0.00" "${TALER_CURRENCY}:0.00" 1h 1year 5 upload
wait_json_endpoint "exchange" "${EXCHANGE_BASE_URL}/keys"

start_service "merchant webhook worker" taler-merchant-webhook -c "${CONFIG_FILE}" -L INFO
start_service "merchant wirewatch" taler-merchant-wirewatch -c "${CONFIG_FILE}" -L INFO
start_service "merchant depositcheck" taler-merchant-depositcheck -c "${CONFIG_FILE}" -L INFO
start_service "merchant exchangekeyupdate" taler-merchant-exchangekeyupdate -c "${CONFIG_FILE}" -L INFO
start_service "merchant reconciliation" taler-merchant-reconciliation -c "${CONFIG_FILE}" -L INFO
start_service "merchant httpd" taler-merchant-httpd -c "${CONFIG_FILE}" -L INFO
merchant_httpd_pid="${last_pid}"
wait_json_endpoint "merchant" "${MERCHANT_BASE_URL}/config"

fleetbase-taler-init-instance

echo "Fleetbase Ledger local Taler stack is ready."
wait "${merchant_httpd_pid}"
