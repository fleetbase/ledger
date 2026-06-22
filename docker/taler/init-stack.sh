#!/usr/bin/env bash
set -euo pipefail

CONFIG_TEMPLATE="${TALER_CONFIG_TEMPLATE:-/etc/taler/taler-local.conf}"
CONFIG_FILE="${TALER_CONFIG_FILE:-/var/lib/fleetbase-ledger/taler/taler.conf}"
POSTGRES_HOST="${TALER_POSTGRES_HOST:-taler-merchant-db}"
POSTGRES_PORT="${TALER_POSTGRES_PORT:-5432}"
POSTGRES_USER="${TALER_POSTGRES_USER:-taler_merchant}"
POSTGRES_PASSWORD="${TALER_POSTGRES_PASSWORD:-taler_merchant}"
TALER_CURRENCY="${TALER_CURRENCY:-KUDOS}"
EXCHANGE_BANK_USER="${TALER_EXCHANGE_BANK_USER:-exchange}"
EXCHANGE_BANK_PASSWORD="${TALER_EXCHANGE_BANK_PASSWORD:-fleetbase-exchange-bank-password}"
MERCHANT_BANK_USER="${TALER_MERCHANT_BANK_USER:-default}"
MERCHANT_BANK_PASSWORD="${TALER_MERCHANT_BANK_PASSWORD:-fleetbase-merchant-bank-password}"
EXCHANGE_PAYTO_URI="${TALER_EXCHANGE_PAYTO_URI:-payto://x-taler-bank/taler-bank.lvh.me/exchange?receiver-name=Fleetbase%20Local%20Exchange}"
MERCHANT_PAYTO_URI="${TALER_MERCHANT_PAYTO_URI:-payto://x-taler-bank/taler-bank.lvh.me/default?receiver-name=Fleetbase%20Ledger}"

export PGPASSWORD="${POSTGRES_PASSWORD}"

postgres_url() {
  local db="$1"
  printf 'postgres://%s:%s@%s:%s/%s' "${POSTGRES_USER}" "${POSTGRES_PASSWORD}" "${POSTGRES_HOST}" "${POSTGRES_PORT}" "${db}"
}

create_database() {
  local db="$1"

  if ! psql "$(postgres_url postgres)" -Atqc "SELECT 1 FROM pg_database WHERE datname = '${db}'" | grep -q 1; then
    psql "$(postgres_url postgres)" -v ON_ERROR_STOP=1 -qc "CREATE DATABASE ${db} OWNER ${POSTGRES_USER}"
  fi
}

until pg_isready -h "${POSTGRES_HOST}" -p "${POSTGRES_PORT}" -U "${POSTGRES_USER}" >/dev/null 2>&1; do
  echo "Waiting for Taler PostgreSQL at ${POSTGRES_HOST}:${POSTGRES_PORT}..."
  sleep 2
done

install -d -m 0755 \
  "$(dirname "${CONFIG_FILE}")" \
  /var/lib/fleetbase-ledger/taler/data/exchange/offline \
  /var/lib/fleetbase-ledger/taler/data/exchange/revocations \
  /var/lib/fleetbase-ledger/taler/run/secmod-eddsa \
  /var/lib/fleetbase-ledger/taler/run/secmod-rsa \
  /var/lib/fleetbase-ledger/taler/run/secmod-cs \
  /var/lib/fleetbase-ledger/taler/tmp

cp "${CONFIG_TEMPLATE}" "${CONFIG_FILE}"

master_pub="$(taler-exchange-offline -c "${CONFIG_FILE}" setup | tail -n1 | tr -d '[:space:]')"
if [ -z "${master_pub}" ]; then
  echo "Unable to create or read local exchange master public key." >&2
  exit 1
fi

sed -i "s/SET_BY_BOOTSTRAP/${master_pub}/g" "${CONFIG_FILE}"
printf '%s\n' "${master_pub}" > /var/lib/fleetbase-ledger/taler/local-exchange-master-public-key.txt

create_database taler_bank
create_database taler_exchange
create_database taler_merchant

libeufin-bank dbinit -c "${CONFIG_FILE}"
libeufin-bank passwd -c "${CONFIG_FILE}" admin admin-password || true
libeufin-bank edit-account -c "${CONFIG_FILE}" admin --debit_threshold="${TALER_CURRENCY}:1000000" || true

libeufin-bank create-account \
  -c "${CONFIG_FILE}" \
  --user "${EXCHANGE_BANK_USER}" \
  --password "${EXCHANGE_BANK_PASSWORD}" \
  --name "Fleetbase Local Exchange" \
  --exchange \
  --public \
  --payto_uri "${EXCHANGE_PAYTO_URI}" \
  --debit_threshold "${TALER_CURRENCY}:1000000" >/dev/null 2>&1 || true

libeufin-bank create-account \
  -c "${CONFIG_FILE}" \
  --user "${MERCHANT_BANK_USER}" \
  --password "${MERCHANT_BANK_PASSWORD}" \
  --name "Fleetbase Ledger" \
  --public \
  --payto_uri "${MERCHANT_PAYTO_URI}" \
  --debit_threshold "${TALER_CURRENCY}:1000000" >/dev/null 2>&1 || true

taler-exchange-dbinit -c "${CONFIG_FILE}"
taler-merchant-dbinit -c "${CONFIG_FILE}"

echo "Local Taler stack configuration initialized."
echo "Local exchange master public key: ${master_pub}"
