#!/bin/bash
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE USER forgejo WITH PASSWORD 'forgejopass';
    CREATE DATABASE forgejo OWNER forgejo;
EOSQL

psql -v ON_ERROR_STOP=1 --username forgejo --dbname forgejo < /docker-entrypoint-initdb.d/forgejo_dump.sql
