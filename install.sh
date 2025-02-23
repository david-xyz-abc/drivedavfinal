#!/bin/bash
set -e
LOGFILE="/var/log/selfhostedgdrive_install.log"
exec > >(tee -a "$LOGFILE") 2>&1

echo "======================================"
echo "Self Hosted Google Drive (DriveDAV) Installer"
echo "======================================"

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: This script must be run as root. Try: sudo bash install.sh"
  exit 1
fi

BASE_URL="https://raw.githubusercontent.com/david-xyz-abc/drivedavfinal/main"
FILES=("index.php" "authenticate.php" "explorer.php" "logout.php" "register.php")

echo "Updating package lists..."
apt-get update

echo "Installing Apache, PHP, and required modules..."
apt-get install -y apache2 php libapache2-mod-php php-cli php-json php-mbstring php-xml wget curl ufw

APP_DIR="/var/www/html/selfhostedgdrive"
WEBDAV_USERS_DIR="/var/www/html/webdav/users"
USERS_JSON="$APP_DIR/users.json"

echo "Creating application directory at $APP_DIR..."
mkdir -p "$APP_DIR"

echo "Downloading PHP files from GitHub..."
for file in "${FILES[@]}"; do
  FILE_URL="${BASE_URL}/${file}"
  echo "Fetching ${file} from ${FILE_URL}..."
  wget -q -O "$APP_DIR/$file" "$FILE_URL" || { echo "ERROR: Failed to download ${file}"; exit 1; }
done

echo "Setting up users.json at $USERS_JSON..."
if [ ! -f "$USERS_JSON" ]; then
  echo "{}" > "$USERS_JSON"
fi

echo "Setting permissions for $APP_DIR and $USERS_JSON..."
chown -R www-data:www-data "$APP_DIR"
chmod -R 755 "$APP_DIR"
chmod 664 "$USERS_JSON"

echo "Creating WebDAV users directory at $WEBDAV_USERS_DIR..."
mkdir -p "$WEBDAV_USERS_DIR"
chown -R www-data:www-data "/var/www/html/webdav"
chmod -R 775 "/var/www/html/webdav"

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
CLI_PHP_INI="/etc/php/$PHP_VERSION/cli/php.ini"
APACHE_PHP_INI="/etc/php/$PHP_VERSION/apache2/php.ini"

if [ ! -f "$CLI_PHP_INI" ]; then
  echo "ERROR: CLI php.ini not found at $CLI_PHP_INI. Exiting."
  exit 1
fi
if [ ! -f "$APACHE_PHP_INI" ]; then
  echo "WARNING: Apache php.ini not found at $APACHE_PHP_INI. Copying from CLI..."
  mkdir -p "$(dirname "$APACHE_PHP_INI")"
  cp "$CLI_PHP_INI" "$APACHE_PHP_INI"
fi

echo "Found CLI php.ini at: $CLI_PHP_INI"
echo "Found Apache php.ini at: $APACHE_PHP_INI"

cp "$CLI_PHP_INI" "${CLI_PHP_INI}.backup"
cp "$APACHE_PHP_INI" "${APACHE_PHP_INI}.backup"

# Dynamically set PHP limits based on server RAM
TOTAL_RAM=$(free -m | awk '/^Mem:/{print $2}')
if [ "$TOTAL_RAM" -lt 2048 ]; then
  MEMORY_LIMIT="1G"
  UPLOAD_MAX="500M"
  POST_MAX="550M"
else
  MEMORY_LIMIT="4G"
  UPLOAD_MAX="2G"
  POST_MAX="2.5G"
fi

update_php_ini() {
  local ini_file="$1"
  echo "Adjusting PHP size limits in $ini_file..."
  sed -i "s/^\s*upload_max_filesize\s*=.*/upload_max_filesize = $UPLOAD_MAX/" "$ini_file"
  sed -i "s/^\s*post_max_size\s*=.*/post_max_size = $POST_MAX/" "$ini_file"
  sed -i "s/^\s*memory_limit\s*=.*/memory_limit = $MEMORY_LIMIT/" "$ini_file"
  sed -i "s/^\s*max_execution_time\s*=.*/max_execution_time = 3600/" "$ini_file"
  sed -i "s/^\s*max_input_time\s*=.*/max_input_time = 3600/" "$ini_file"
}

update_php_ini "$CLI_PHP_INI"
update_php_ini "$APACHE_PHP_INI"
echo "PHP configuration updated (Memory: $MEMORY_LIMIT, Upload: $UPLOAD_MAX)"

echo "Increasing file handle limit for www-data..."
echo "www-data soft nofile 4096" >> /etc/security/limits.conf
echo "www-data hard nofile 8192" >> /etc/security/limits.conf

echo "Enabling Apache mod_rewrite..."
a2enmod rewrite

echo "Setting up basic firewall..."
ufw allow 80
ufw allow 443
ufw --force enable

echo "Restarting Apache..."
systemctl restart apache2

echo "Verifying CLI php.ini values..."
php -r '
echo "upload_max_filesize: " . ini_get("upload_max_filesize") . "\n";
echo "post_max_size: " . ini_get("post_max_size") . "\n";
echo "memory_limit: " . ini_get("memory_limit") . "\n";
echo "max_execution_time: " . ini_get("max_execution_time") . "\n";
echo "max_input_time: " . ini_get("max_input_time") . "\n";
'

echo "Verifying Apache php.ini values..."
cat << 'EOF' > "$APP_DIR/check_ini.php"
<?php
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "max_input_time: " . ini_get('max_input_time') . "\n";
?>
EOF
chown www-data:www-data "$APP_DIR/check_ini.php"
chmod 644 "$APP_DIR/check_ini.php"
curl -s "http://localhost/selfhostedgdrive/check_ini.php" || echo "WARNING: Could not verify Apache settings."
rm -f "$APP_DIR/check_ini.php"

PUBLIC_IP=$(curl -s http://ifconfig.me || curl -s http://api.ipify.org || hostname -I | awk '{print $1}' || echo "Unable to fetch IP")
if [ "$PUBLIC_IP" = "Unable to fetch IP" ]; then
  echo "WARNING: Could not fetch public IP. Using 'your_server_address' instead."
  PUBLIC_IP="your_server_address"
fi

echo "======================================"
echo "Installation Complete!"
echo "Access your application at: http://$PUBLIC_IP/selfhostedgdrive/"
echo "======================================"
