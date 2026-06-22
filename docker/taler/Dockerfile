FROM debian:trixie-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        jq \
        postgresql-client \
        procps \
        wget \
    && install -d -m 0755 /etc/apt/keyrings \
    && wget -O /etc/apt/keyrings/taler-systems.gpg https://taler.net/taler-systems.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/taler-systems.gpg] https://deb.taler.net/apt/debian trixie main" > /etc/apt/sources.list.d/taler.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        libeufin-bank \
        taler-exchange \
        taler-exchange-database \
        taler-exchange-offline \
        taler-harness \
        taler-merchant \
        taler-wallet-cli \
    && rm -rf /var/lib/apt/lists/*

RUN install -d -m 0755 /etc/taler /var/lib/fleetbase-ledger/taler

COPY docker/taler/taler-local.conf /etc/taler/taler-local.conf
COPY docker/taler/entrypoint.sh /usr/local/bin/fleetbase-taler-entrypoint
COPY docker/taler/init-instance.sh /usr/local/bin/fleetbase-taler-init-instance
COPY docker/taler/init-stack.sh /usr/local/bin/fleetbase-taler-init-stack
COPY docker/taler/create-withdrawal.sh /usr/local/bin/fleetbase-taler-create-withdrawal
COPY docker/taler/smoke-test.sh /usr/local/bin/fleetbase-taler-smoke-test

RUN chmod +x \
        /usr/local/bin/fleetbase-taler-entrypoint \
        /usr/local/bin/fleetbase-taler-init-instance \
        /usr/local/bin/fleetbase-taler-init-stack \
        /usr/local/bin/fleetbase-taler-create-withdrawal \
        /usr/local/bin/fleetbase-taler-smoke-test

EXPOSE 8081 8082 9966

ENTRYPOINT ["fleetbase-taler-entrypoint"]
