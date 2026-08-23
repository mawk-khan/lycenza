#!/bin/bash
# Creates a dedicated database for the automated test suite so tests
# never run against local dev data. See
# docs/architecture/adr/0024-real-postgresql-test-infrastructure.md.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE school_os_test OWNER $POSTGRES_USER;
EOSQL
