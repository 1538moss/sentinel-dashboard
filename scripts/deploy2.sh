#!/bin/bash
# Deploy Sentinel Dashboard til sekundær server (10.9.1.10) via jump host
# Krav: WSL eller Git Bash med rsync installert
# Bruk: bash scripts/deploy2.sh
#
# Ruting: ps1@81.93.174.117:10022 (jump host) -> ps1@10.9.1.10 — begge hopp
# nøkkelbasert/automatisk, samme ed25519-nøkkel autorisert på begge serverne.
# Krever en Host-oppføring for 81.93.174.117 og 10.9.1.10 i ~/.ssh/config med
# riktig IdentityFile (satt opp lokalt, ikke en del av dette repoet — må
# gjøres på nytt per maskin/miljø som skal deploye).
# images/ og data/ synces IKKE — serveren henter og genererer disse selv.

set -e

JUMP_USER="ps1"
JUMP_HOST="81.93.174.117"
JUMP_PORT="10022"
SERVER_USER="ps1"
SERVER_HOST="10.9.1.10"
APP_DIR="/var/www/kart"
LOCAL_DIR="$(cd "$(dirname "$0")/.." && pwd)"

JUMP="${JUMP_USER}@${JUMP_HOST}:${JUMP_PORT}"
SSH_JUMP_OPTS="-J ${JUMP}"

echo "=== Deployer Sentinel til ${SERVER_USER}@${SERVER_HOST} (via jump host ${JUMP_HOST}:${JUMP_PORT}) ==="

echo "--- gi ${SERVER_USER} skrivetilgang ---"
ssh ${SSH_JUMP_OPTS} "${SERVER_USER}@${SERVER_HOST}" \
    "sudo chown -R ${SERVER_USER}:${SERVER_USER} ${APP_DIR}"

echo "--- sjekk GDAL + unzip (kreves for Landsat- og S3 LST-pipeline) ---"
ssh ${SSH_JUMP_OPTS} "${SERVER_USER}@${SERVER_HOST}" \
    "if ! command -v gdalwarp >/dev/null || ! command -v gdalbuildvrt >/dev/null || ! command -v gdal_calc.py >/dev/null || ! command -v unzip >/dev/null; then
        echo '  Mangler GDAL/unzip — installerer gdal-bin python3-gdal unzip...'
        sudo apt-get update -qq && sudo apt-get install -y gdal-bin python3-gdal unzip
    else
        echo '  GDAL og unzip er allerede installert'
    fi
    if gdalinfo --formats | grep -qi netcdf; then
        echo '  netCDF-driver: OK (kreves for s3_lst_enabled)'
    else
        echo '  netCDF-driver: IKKE FUNNET — s3_lst_enabled vil feile før dette er løst (se CLAUDE.md)'
    fi"

echo "--- rsync filer ---"
rsync -avz --delete \
    -e "ssh ${SSH_JUMP_OPTS}" \
    --exclude='.git/' \
    --exclude='.claude/' \
    --exclude='.vscode/' \
    --exclude='images/' \
    --exclude='data/' \
    --exclude='scripts/' \
    --exclude='*.env' \
    --exclude='.sentinel.env' \
    "$LOCAL_DIR/" "${SERVER_USER}@${SERVER_HOST}:${APP_DIR}/"

echo "--- rettigheter ---"
ssh ${SSH_JUMP_OPTS} "${SERVER_USER}@${SERVER_HOST}" \
    "sudo mkdir -p ${APP_DIR}/images ${APP_DIR}/images/thumbs ${APP_DIR}/data && \
     sudo chown -R www-data:www-data ${APP_DIR} && \
     sudo find ${APP_DIR} -type d -exec chmod 755 {} + && \
     sudo find ${APP_DIR} -type f -exec chmod 644 {} + && \
     sudo chmod -R 775 ${APP_DIR}/images ${APP_DIR}/data"

echo "--- reload apache ---"
ssh ${SSH_JUMP_OPTS} "${SERVER_USER}@${SERVER_HOST}" \
    "sudo systemctl reload apache2"

echo ""
echo "=== Deploy ferdig ==="
echo "  ${SERVER_USER}@${SERVER_HOST}:${APP_DIR}"
