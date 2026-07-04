Импорт на другом ПК
1. Скопировать всю папку docker-export/ на новый ПК (вместе с docker-compose.yml)
2. Запустить скрипт восстановления:
Windows (PowerShell):
.\docker-export\restore.ps1
Linux/Mac:
chmod +x docker-export/restore.sh
./docker-export/restore.sh
3. После завершения скрипта:
docker compose up -d
Скрипт сам загрузит образы, создаст тома, восстановит данные и сеть — останется только запустить контейнеры.
