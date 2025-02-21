#!/bin/bash
# Installer for Self Hosted Google Drive (DriveDAV)
set -e
set -u

LOGFILE="/var/log/selfhostedgdrive_install.log"
touch "$LOGFILE" 2>/dev/null || { echo "ERROR: Cannot write to $LOGFILE"; exit 1; }
exec > >(tee -a "$LOGFILE") 2>&1

echo "======================================"
echo "Self Hosted Google Drive (DriveDAV) Installer"
echo "======================================"

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: Run as root: sudo bash install.sh"
  exit 1
fi

echo "Updating package lists..."
apt-get update -y || { echo "ERROR: Failed to update package lists"; exit 1; }

echo "Installing dependencies..."
apt-get install -y apache2 php libapache2-mod-php php-cli php-json php-mbstring php-xml php-fileinfo || {
  echo "ERROR: Failed to install dependencies"; exit 1;
}

APP_DIR="/var/www/html/selfhostedgdrive"
WEBDAV_USERS_DIR="/var/www/html/webdav/users"
USERS_JSON="$APP_DIR/users.json"
DEBUG_LOG="$APP_DIR/debug.log"

echo "Creating directories..."
mkdir -p "$APP_DIR" "$WEBDAV_USERS_DIR" || { echo "ERROR: Failed to create directories"; exit 1; }

echo "Copying PHP files (assuming local files are present)..."
FILES=("index.php" "authenticate.php" "explorer.php" "logout.php" "register.php")
for file in "${FILES[@]}"; do
  if [ -f "$file" ]; then
    cp "$file" "$APP_DIR/$file" || { echo "ERROR: Failed to copy $file"; exit 1; }
  else
    if [ "$file" = "logout.php" ]; then
      echo '<?php session_start(); session_unset(); session_destroy(); header("Location: /selfhostedgdrive/index.php"); exit; ?>' > "$APP_DIR/$file"
    else
      echo "ERROR: $file not found locally"; exit 1
    fi
  fi
  chown www-data:www-data "$APP_DIR/$file"
  chmod 644 "$APP_DIR/$file"
done

echo "Setting up users.json..."
[ -f "$USERS_JSON" ] || echo "{}" > "$USERS_JSON" || { echo "ERROR: Failed to create $USERS_JSON"; exit 1; }
chown www-data:www-data "$USERS_JSON"
chmod 664 "$USERS_JSON"

echo "Setting up debug log..."
[ -f "$DEBUG_LOG" ] || echo "Debug log initialized" > "$DEBUG_LOG" || { echo "ERROR: Failed to create $DEBUG_LOG"; exit 1; }
chown www-data:www-data "$DEBUG_LOG"
chmod 666 "$DEBUG_LOG"

echo "Setting permissions..."
chown -R www-data:www-data "/var/www/html/webdav" "$APP_DIR"
chmod -R 775 "/var/www/html/webdav"
chmod 755 "$APP_DIR"

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null) || {
  echo "ERROR: Failed to determine PHP version"; exit 1;
}
CLI_PHP_INI="/etc/php/$PHP_VERSION/cli/php.ini"
APACHE_PHP_INI="/etc/php/$PHP_VERSION/apache2/php.ini"

[ -f "$CLI_PHP_INI" ] || { echo "ERROR: CLI php.ini not found at $CLI_PHP_INI"; exit 1; }
[ -f "$APACHE_PHP_INI" ] || {
  echo "WARNING: Apache php.ini not found at $APACHE_PHP_INI. Copying from CLI..."
  mkdir -p "$(dirname "$APACHE_PHP_INI")"
  cp "$CLI_PHP_INI" "$APACHE_PHP_INI" || { echo "ERROR: Failed to copy php.ini"; exit 1; }
}

echo "Backing up php.ini files..."
cp "$CLI_PHP_INI" "${CLI_PHP_INI}.backup" || { echo "ERROR: Failed to backup CLI php.ini"; exit 1; }
cp "$APACHE_PHP_INI" "${APACHE_PHP_INI}.backup" || { echo "ERROR: Failed to backup Apache php.ini"; exit 1; }

echo "Updating PHP settings..."
update_php_ini() {
  local ini_file="$1"
  for setting in \
    "upload_max_filesize=10G" \
    "post_max_size=11G" \
    "memory_limit=512M" \
    "max_execution_time=3600" \
    "max_input_time=3600" \
    "output_buffering=Off"; do
    key="${setting%%=*}"
    value="${setting#*=}"
    if grep -q "^\s*${key}\s*=" "$ini_file"; then
      sed -i "s|^\s*${key}\s*=.*|${key} = ${value}|" "$ini_file"
    else
      echo "${key} = ${value}" >> "$ini_file"
    fi
  done
}
update_php_ini "$CLI_PHP_INI"
update_php_ini "$APACHE_PHP_INI"

echo "Configuring Apache..."
cat << EOF > /etc/apache2/sites-available/selfhostedgdrive.conf
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/selfhostedgdrive
    ErrorLog /var/log/apache2/selfhostedgdrive_error.log
    CustomLog /var/log/apache2/selfhostedgdrive_access.log combined
    <Directory /var/www/html/selfhostedgdrive>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>
</VirtualHost>
EOF
a2enmod rewrite || { echo "ERROR: Failed to enable rewrite module"; exit 1; }
a2enmod php"$PHP_VERSION" || { echo "ERROR: Failed to enable PHP module"; exit 1; }
a2dissite 000-default.conf 2>/dev/null || true
a2ensite selfhostedgdrive.conf || { echo "ERROR: Failed to enable site"; exit 1; }

echo "Restarting Apache..."
systemctl restart apache2 || { echo "ERROR: Failed to restart Apache"; exit 1; }
systemctl is-active apache2 >/dev/null || { echo "ERROR: Apache is not running"; exit 1; }

echo "Verifying installation..."
curl -s -I "http://localhost/selfhostedgdrive/index.php" | grep -q "200 OK" || {
  echo "ERROR: Failed to access index.php locally. Check Apache status and logs:"
  systemctl status apache2
  cat /var/log/apache2/selfhostedgdrive_error.log
  cat /var/log/apache2/error.log
  exit 1
}

PUBLIC_IP=$(curl -s --retry 3 http://ifconfig.me || curl -s --retry 3 http://api.ipify.org || echo "your_server_ip_here")
if [ "$PUBLIC_IP" = "your_server_ip_here" ]; then
  echo "WARNING: Could not fetch public IP. Replace 'your_server_ip_here' with your server's IP."
fi

echo "======================================"
echo "Installation Complete!"
echo "Access at: http://$PUBLIC_IP/selfhostedgdrive/"
echo "Log file: $LOGFILE"
echo "======================================"
