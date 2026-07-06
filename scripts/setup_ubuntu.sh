#!/bin/bash
# Kjøres én gang på Azure-serveren for å sette opp Sentinel Dashboard.
# Kjør lokalt med:
#   ssh ps1@51.120.69.99 'bash -s' < scripts/setup_ubuntu.sh

set -e

APP_DIR="/var/www/sentinel"
PORT=8082

echo "=== Setter opp Sentinel Dashboard ==="

echo "--- installer PHP og utvidelser ---"
sudo apt-get update -qq
sudo apt-get install -y php libapache2-mod-php php-curl php-gd

echo "--- installer GDAL (Landsat-pipeline) ---"
sudo apt-get install -y gdal-bin python3-gdal
echo "  gdalwarp: $(command -v gdalwarp || echo 'IKKE FUNNET')"
echo "  gdalbuildvrt: $(command -v gdalbuildvrt || echo 'IKKE FUNNET')"
echo "  gdal_calc.py: $(command -v gdal_calc.py || echo 'IKKE FUNNET — sjekk at python3-gdal ga kjørbare scripts på PATH')"

echo "--- aktiver Apache-moduler ---"
sudo a2enmod rewrite headers

echo "--- legg til port ${PORT} i Apache ---"
if ! grep -q "Listen ${PORT}" /etc/apache2/ports.conf; then
    echo "Listen ${PORT}" | sudo tee -a /etc/apache2/ports.conf
    echo "  Lagt til: Listen ${PORT}"
else
    echo "  Port ${PORT} allerede konfigurert"
fi

echo "--- opprett app-kataloger ---"
sudo mkdir -p "${APP_DIR}/images" "${APP_DIR}/data"
sudo chown -R www-data:www-data "${APP_DIR}"
sudo chmod -R 755 "${APP_DIR}"
sudo chmod -R 775 "${APP_DIR}/images" "${APP_DIR}/data"

echo "--- opprett vhost ---"
sudo tee /etc/apache2/sites-available/sentinel.conf > /dev/null << 'VHOST'
<VirtualHost *:8082>
    ServerName kart.vansjo.top
    DocumentRoot /var/www/sentinel

    <Directory /var/www/sentinel>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/sentinel_error.log
    CustomLog ${APACHE_LOG_DIR}/sentinel_access.log combined
</VirtualHost>
VHOST

echo "--- aktiver vhost og reload Apache ---"
sudo a2ensite sentinel.conf
sudo systemctl reload apache2

echo ""
echo "=== Setup ferdig ==="
echo "  Apache lytter på port ${PORT}"
echo "  App-katalog: ${APP_DIR}"
echo ""
echo "  Neste steg:"
echo "  1. Kjør deploy.sh lokalt for å synce filene"
echo "  2. Konfigurer Traefik: kart.vansjo.top → localhost:${PORT}"
echo "  3. Hent bilder via https://kart.vansjo.top → knappen '↓ Hent'"
