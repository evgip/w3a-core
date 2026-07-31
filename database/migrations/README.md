# Миграции w3a-core

Эта папка содержит SQL-схемы для таблиц, которые использует ядро.

## Установка

### Автоматическая (рекомендуется)

```bash
# Только таблицы авторизации
php bin/w3a auth:install

# Авторизация + аудит
php bin/w3a auth:install --with-audit

# Всё (авторизация + аудит + rate limits)
php bin/w3a auth:install --with-audit --with-rate-limits