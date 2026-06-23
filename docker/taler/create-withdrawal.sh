#!/usr/bin/env bash
set -euo pipefail

AMOUNT="${1:-KUDOS:5.00}"
BANK_BASE_URL="${TALER_BANK_BASE_URL:-http://127.0.0.1:8082}"
BANK_PUBLIC_BASE_URL="${TALER_BANK_PUBLIC_BASE_URL:-http://taler-bank.lvh.me:8082}"
BANK_USER="${TALER_WITHDRAW_BANK_USER:-admin}"
BANK_PASSWORD="${TALER_WITHDRAW_BANK_PASSWORD:-admin-password}"

if [[ "${AMOUNT}" != *:* ]]; then
  AMOUNT="KUDOS:${AMOUNT}"
fi

for _ in $(seq 1 60); do
  if curl -fsS "${BANK_BASE_URL}/config" >/dev/null 2>&1; then
    break
  fi

  sleep 1
done

response="$(curl -fsS \
  -u "${BANK_USER}:${BANK_PASSWORD}" \
  -H "Content-Type: application/json" \
  --data "$(jq -n --arg amount "${AMOUNT}" '{amount: $amount}')" \
  "${BANK_BASE_URL}/accounts/${BANK_USER}/withdrawals")"

withdrawal_id="$(printf '%s' "${response}" | jq -r '.withdrawal_id')"
withdraw_uri="$(printf '%s' "${response}" | jq -r '.taler_withdraw_uri')"

if [ -z "${withdrawal_id}" ] || [ "${withdrawal_id}" = "null" ]; then
  echo "Unable to create Taler withdrawal operation." >&2
  printf '%s\n' "${response}" >&2
  exit 1
fi

cat <<EOF
Created local Taler wallet withdrawal for ${AMOUNT}.

Open this normal HTTP page in the browser with the GNU Taler extension:
${BANK_PUBLIC_BASE_URL}/webui/#/operation/${withdrawal_id}

Do not paste the raw Taler URI into the address bar. The wallet extension
intercepts it when it is clicked from a page with Taler browser support.

Raw Taler URI:
${withdraw_uri}
EOF
