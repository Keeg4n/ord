#!/usr/bin/env bash
# Локальное обновление данных рейтинга и пуш на GitHub.
# Запуск: bash refresh.sh   (из папки ord/)
set -e
cd "$(dirname "$0")"

echo "[$(date '+%H:%M:%S')] Сбор данных с abit.cfuv.ru…"
php collect.php

if git diff --quiet -- data.json; then
  echo "Данные не изменились — пуш не нужен."
else
  git add data.json enroll_1_8.json
  git commit -q -m "refresh data ($(date -u '+%Y-%m-%d %H:%M UTC'))"
  git push -q
  echo "Обновлено и запушено."
fi
