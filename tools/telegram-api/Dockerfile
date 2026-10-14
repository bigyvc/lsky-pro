# aiogram's image packages tdlib/telegram-bot-api; no server compilation on the VPS.
# Match Lsky Apache's www-data UID/GID so even owner-only local downloads are readable.
ARG TELEGRAM_API_BASE_IMAGE=aiogram/telegram-bot-api:latest
FROM ${TELEGRAM_API_BASE_IMAGE}
USER root
RUN awk -F: 'BEGIN {OFS=":"} $1=="telegram-bot-api" {$3=33; $4=33} {print}' /etc/passwd > /tmp/lsky-passwd \
    && cat /tmp/lsky-passwd > /etc/passwd \
    && awk -F: 'BEGIN {OFS=":"} $1=="telegram-bot-api" {$3=33} {print}' /etc/group > /tmp/lsky-group \
    && cat /tmp/lsky-group > /etc/group \
    && rm /tmp/lsky-passwd /tmp/lsky-group \
    && chown telegram-bot-api:telegram-bot-api "${TELEGRAM_WORK_DIR}" "${TELEGRAM_TEMP_DIR}"
