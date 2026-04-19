#!/usr/bin/env bash
set -euo pipefail

APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"
APP_ROOT="${APP_ROOT:-/var/www/greenloop}"
PRIVATE_DATA_ROOT="${PRIVATE_DATA_ROOT:-/var/www/greenloop-data}"
PHP_VERSION="${PHP_VERSION:-8.2}"

echo "[1/6] Updating package index..."
sudo apt update

echo "[2/6] Installing Nginx, PHP-FPM, MariaDB and common extensions..."
sudo apt install -y nginx mariadb-server "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip"

echo "[3/6] Creating application directories..."
sudo mkdir -p "${APP_ROOT}"
sudo mkdir -p "${PRIVATE_DATA_ROOT}"

echo "[4/6] Setting permissions..."
sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_ROOT}"
sudo chown -R "${APP_USER}:${APP_GROUP}" "${PRIVATE_DATA_ROOT}"
sudo chmod -R 775 "${APP_ROOT}"
sudo chmod -R 775 "${PRIVATE_DATA_ROOT}"

echo "[5/6] Ensuring services are enabled..."
sudo systemctl enable nginx
sudo systemctl enable mariadb
sudo systemctl enable "php${PHP_VERSION}-fpm"
sudo systemctl restart mariadb
sudo systemctl restart "php${PHP_VERSION}-fpm"
sudo systemctl restart nginx

echo "[6/6] Done."
echo "APP_ROOT=${APP_ROOT}"
echo "PRIVATE_DATA_ROOT=${PRIVATE_DATA_ROOT}"
echo "Next: upload project files to ${APP_ROOT} and copy config.example.php to config.php."
