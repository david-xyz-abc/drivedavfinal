#!/bin/bash
# Beginner-Friendly Installer for Self Hosted Google Drive (DriveDAV)
# Installs Apache, PHP, required modules, downloads PHP files from GitHub,
# sets up directories, permissions, adjusts PHP settings, and verifies the setup.

set -e  # Exit on command failure
set -u  # Treat unset variables as errors

# Log output for troubleshooting
LOGFILE="/var/log/selfhostedgdrive_install.log"
touch "$LOGFILE" 2>/dev/null || { echo "ERROR: Cannot write to $LOGFILE. Check permissions."; exit 1; }
exec > >(tee -a "$LOGFILE") 2>&1

echo "======================================"
echo "Self Hosted Google Drive (DriveDAV) Installer"
echo "======================================"

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: This script must be run as root. Try: sudo bash install.sh"
  exit 1
fi

# Set base URL and files
BASE_URL="https://raw.githubusercontent.com/david-xyz-abc/drivedavfinal/main"
FILES=("index.php" "authenticate.php" "explorer.php" "logout.php" "register.php")

# Update package lists
echo "Updating package lists..."
apt-get update -y || { echo "ERROR: Failed to update package lists"; exit 1; }

# Install dependencies
echo "Installing Apache, PHP, and required modules..."
apt-get install -y apache2 php libapache2-mod-php php-cli php-json php-mbstring php-xml php-fileinfo wget curl || {
  echo "ERROR: Failed to install dependencies"; exit 1;
}

# Define directories
APP_DIR="/var/www/html/selfhostedgdrive"
WEBDAV_USERS_DIR="/var/www/html/webdav/users"
USERS_JSON="$APP_DIR/users.json"
DEBUG_LOG="$APP_DIR/debug.log"

# Create and configure application directory
echo "Creating application directory at $APP_DIR..."
mkdir -p "$APP_DIR" || { echo "ERROR: Failed to create $APP_DIR"; exit 1; }
chown www-data:www-data "$APP_DIR"
chmod 755 "$APP_DIR"

# Download PHP files
echo "Downloading PHP files from GitHub..."
for file in "${FILES[@]}"; do
  FILE_URL="${BASE_URL}/${file}"
  echo "Fetching ${file} from ${FILE_URL}..."
  wget -q -O "$APP_DIR/$file" "$FILE_URL" || { echo "ERROR: Failed to download ${file}"; exit 1; }
  chown www-data:www-data "$APP_DIR/$file"
  chmod 644 "$APP_DIR/$file"
done

# Set up users.json
echo "Setting up users.json at $USERS_JSON..."
if [ ! -f "$USERS_JSON" ]; then
  echo "{}" > "$USERS_JSON" || { echo "ERROR: Failed to create $USERS_JSON"; exit 1; }
fi
chown www-data:www-data "$USERS_JSON"
chmod 664 "$USERS_JSON"

# Create and configure WebDAV users directory
echo "Creating WebDAV users directory at $WEBDAV_USERS_DIR..."
mkdir -p "$WEBDAV_USERS_DIR" || { echo "ERROR: Failed to create $WEBDAV_USERS_DIR"; exit 1; }
chown -R www-data:www-data "/var/www/html/webdav"
chmod -R 775 "/var/www/html/webdav"

# Set up debug log
echo "Setting up debug log at $DEBUG_LOG..."
if [ ! -f "$DEBUG_LOG" ]; then
  echo "Debug log initialized" > "$DEBUG_LOG" || { echo "ERROR: Failed to create $DEBUG_LOG"; exit 1; }
fi
chown www-data:www-data "$DEBUG_LOG"
chmod 666 "$DEBUG_LOG"

# Determine PHP version
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null) || {
  echo "ERROR: Failed to determine PHP version"; exit 1;
}
CLI_PHP_INI="/etc/php/$PHP_VERSION/cli/php.ini"
APACHE_PHP_INI="/etc/php/$PHP_VERSION/apache2/php.ini"

# Verify php.ini files
if [ ! -f "$CLI_PHP_INI" ]; then
  echo "ERROR: CLI php.ini not found at $CLI_PHP_INI"
  exit 1
fi
if [ ! -f "$APACHE_PHP_INI" ]; then
  echo "WARNING: Apache php.ini not found at $APACHE_PHP_INI. Copying from CLI..."
  mkdir -p "$(dirname "$APACHE_PHP_INI")"
  cp "$CLI_PHP_INI" "$APACHE_PHP_INI" || { echo "ERROR: Failed to copy php.ini"; exit 1; }
fi

echo "Found CLI php.ini at: $CLI_PHP_INI"
echo "Found Apache php.ini at: $APACHE_PHP_INI"

# Backup php.ini files
echo "Backing up php.ini files..."
cp "$CLI_PHP_INI" "${CLI_PHP_INI}.backup" || { echo "ERROR: Failed to backup CLI php.ini"; exit 1; }
cp "$APACHE_PHP_INI" "${APACHE_PHP_INI}.backup" || { echo "ERROR: Failed to backup Apache php.ini"; exit 1; }

# Function to update PHP configuration
update_php_ini() {
  local ini_file="$1"
  echo "Adjusting PHP settings in $ini_file..."
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

# Update php.ini files
update_php_ini "$CLI_PHP_INI"
update_php_ini "$APACHE_PHP_INI"
echo "PHP configuration updated (backups saved)"

# Configure Apache virtual host
echo "Configuring Apache virtual host..."
cat << EOF > /etc/apache2/sites-available/selfhostedgdrive.conf
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot $APP_DIR
    ErrorLog /var/log/apache2/selfhostedgdrive_error.log
    CustomLog /var/log/apache2/selfhostedgdrive_access.log combined
    <Directory $APP_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex explorer.php
    </Directory>
</VirtualHost>
EOF
a2enmod rewrite || { echo "ERROR: Failed to enable rewrite module"; exit 1; }
a2dissite 000-default.conf 2>/dev/null || true
a2ensite selfhostedgdrive.conf || { echo "ERROR: Failed to enable selfhostedgdrive site"; exit 1; }

# Restart Apache
echo "Restarting Apache..."
systemctl restart apache2 || { echo "ERROR: Failed to restart Apache"; exit 1; }

# Verify PHP settings
echo "======================================"
echo "Verifying CLI PHP settings..."
php -r '
echo "upload_max_filesize: " . ini_get("upload_max_filesize") . "\n";
echo "post_max_size: " . ini_get("post_max_size") . "\n";
echo "memory_limit: " . ini_get("memory_limit") . "\n";
echo "max_execution_time: " . ini_get("max_execution_time") . "\n";
echo "max_input_time: " . ini_get("max_input_time") . "\n";
echo "output_buffering: " . ini_get("output_buffering") . "\n";
' || { echo "ERROR: Failed to verify CLI PHP settings"; exit 1; }

echo "======================================"
echo "Verifying Apache PHP settings..."
cat << 'EOF' > "$APP_DIR/check_ini.php" || { echo "ERROR: Failed to create $APP_DIR/check_ini.php"; exit 1; }
<?php
echo "upload_max_filesize: " . ini_get("upload_max_filesize") . "\n";
echo "post_max_size: " . ini_get("post_max_size") . "\n";
echo "memory_limit: " . ini_get("memory_limit") . "\n";
echo "max_execution_time: " . ini_get("max_execution_time") . "\n";
echo "max_input_time: " . ini_get("max_input_time") . "\n";
echo "output_buffering: " . ini_get("output_buffering") . "\n";
?>
EOF
chown www-data:www-data "$APP_DIR/check_ini.php"
chmod 644 "$APP_DIR/check_ini.php"
curl -s --retry 3 --retry-delay 2 "http://localhost/selfhostedgdrive/check_ini.php" > /tmp/apache_php_check || {
  echo "ERROR: Apache verification failed. Check logs: /var/log/apache2/selfhostedgdrive_error.log"
  cat /var/log/apache2/selfhostedgdrive_error.log 2>/dev/null || echo "No error log available"
  exit 1
}
cat /tmp/apache_php_check
rm -f "$APP_DIR/check_ini.php" /tmp/apache_php_check

# Fetch public IP
echo "Fetching public IP address..."
PUBLIC_IP=$(curl -s --retry 3 http://ifconfig.me || curl -s --retry 3 http://api.ipify.org || echo "Unable to fetch IP")
if [ "$PUBLIC_IP" = "Unable to fetch IP" ]; then
  echo "WARNING: Could not fetch public IP. Using 'your_server_address' instead."
  PUBLIC_IP="your_server_address"
fi

echo "======================================"
echo "Installation Complete!"
echo "Access your application at: http://$PUBLIC_IP/selfhostedgdrive/"
echo "If the IP doesn't work, check your server's network settings or use its local IP."
echo "Log file: $LOGFILE"
echo "======================================"
