#!/bin/bash
# Скрипт восстановления на другом ПК (Linux/Mac)
# На Windows используй restore.ps1

set -e

echo "=== Загрузка образов ==="
docker load -i forgejo-image.tar
docker load -i postgres-image.tar
docker load -i redis-image.tar

echo "=== Создание volumes ==="
docker volume create livecode-dev_redis_data
docker volume create livecode-dev_forgejo_db_data

echo "=== Создание сети forgejo-net ==="
docker network create forgejo-net 2>/dev/null || echo "forgejo-net уже существует"

echo "=== Восстановление данных Postgres ==="
docker run --rm \
  -v "$(pwd)/postgres-volume.tar.gz:/backup/postgres-volume.tar.gz" \
  -v livecode-dev_forgejo_db_data:/target \
  alpine tar xzf /backup/postgres-volume.tar.gz -C /target

echo "=== Восстановление данных Redis ==="
docker run --rm \
  -v "$(pwd)/redis-volume.tar.gz:/backup/redis-volume.tar.gz" \
  -v livecode-dev_redis_data:/target \
  alpine tar xzf /backup/redis-volume.tar.gz -C /target

echo "=== Восстановление данных Forgejo ==="
mkdir -p services/forgejo/data
docker run --rm \
  -v "$(pwd)/forgejo-data.tar.gz:/backup/forgejo-data.tar.gz" \
  -v "$(pwd)/services/forgejo/data:/target" \
  alpine tar xzf /backup/forgejo-data.tar.gz -C /target

mkdir -p docker
cp forgejo-app.ini docker/forgejo-app.ini

echo "=== Готово! Запусти: docker compose up -d ==="
