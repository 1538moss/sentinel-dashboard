#!/bin/bash
# Deploy Sentinel Dashboard til Azure Ubuntu Server
# Krav: WSL eller Git Bash med rsync installert
# Bruk: bash scripts/deploy.sh
#
# Første gang: kjør setup_ubuntu.sh på serveren først.
# images/ og data/ synces IKKE — serveren henter og genererer disse selv.

set -e

SERVER_USER="ps1"
SERVER_HOST="51.120.69.99"
APP_DIR="/var/www/sentinel"
LOCAL_DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo "=== Deployer Sentinel til ${SERVER_USER}@${SERVER_HOST} ==="

echo "--- gi ${SERVER_USER} skrivetilgang ---"
ssh "${SERVER_USER}@${SERVER_HOST}" \
    "sudo chown -R ${SERVER_USER}:${SERVER_USER} ${APP_DIR}"

echo "--- sjekk GDAL + unzip (kreves for Landsat- og S3 LST-pipeline) ---"
ssh "${SERVER_USER}@${SERVER_HOST}" \
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
    --exclude='.git/' \
    --exclude='.claude/' \
    --exclude='.vscode/' \
    --exclude='images/' \
    --exclude='data/' \
    --exclude='scripts/' \
    --exclude='docs/' \
    --exclude='*.env' \
    --exclude='.sentinel.env' \
    "$LOCAL_DIR/" "${SERVER_USER}@${SERVER_HOST}:${APP_DIR}/"

echo "--- rettigheter ---"
ssh "${SERVER_USER}@${SERVER_HOST}" \
    "sudo chown -R www-data:www-data ${APP_DIR} && \
     sudo find ${APP_DIR} -type d -exec chmod 755 {} + && \
     sudo find ${APP_DIR} -type f -exec chmod 644 {} + && \
     sudo chmod -R 775 ${APP_DIR}/images ${APP_DIR}/data"

echo "--- reload apache ---"
ssh "${SERVER_USER}@${SERVER_HOST}" \
    "sudo systemctl reload apache2"

echo ""
echo "=== Deploy ferdig ==="
echo "  https://kart.vansjo.top"
