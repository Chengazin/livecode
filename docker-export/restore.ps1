# Скрипт восстановления на Windows (PowerShell)

Write-Host "=== Загрузка образов ==="
docker load -i forgejo-image.tar
docker load -i postgres-image.tar
docker load -i redis-image.tar

Write-Host "=== Создание volumes ==="
docker volume create livecode-dev_redis_data
docker volume create livecode-dev_forgejo_db_data

Write-Host "=== Создание сети forgejo-net ==="
docker network create forgejo-net 2>$null

Write-Host "=== Восстановление данных Postgres ==="
docker run --rm `
  -v "$($pwd)\postgres-volume.tar.gz:/backup/postgres-volume.tar.gz" `
  -v livecode-dev_forgejo_db_data:/target `
  alpine tar xzf /backup/postgres-volume.tar.gz -C /target

Write-Host "=== Восстановление данных Redis ==="
docker run --rm `
  -v "$($pwd)\redis-volume.tar.gz:/backup/redis-volume.tar.gz" `
  -v livecode-dev_redis_data:/target `
  alpine tar xzf /backup/redis-volume.tar.gz -C /target

Write-Host "=== Восстановление данных Forgejo ==="
New-Item -ItemType Directory -Force -Path "services\forgejo\data" | Out-Null
docker run --rm `
  -v "$($pwd)\forgejo-data.tar.gz:/backup/forgejo-data.tar.gz" `
  -v "$($pwd)\services\forgejo\data:/target" `
  alpine tar xzf /backup/forgejo-data.tar.gz -C /target

New-Item -ItemType Directory -Force -Path "docker" | Out-Null
Copy-Item -Path "forgejo-app.ini" -Destination "docker\forgejo-app.ini" -Force

Write-Host "=== Готово! Запусти: docker compose up -d ==="
