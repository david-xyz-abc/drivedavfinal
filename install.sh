#!/bin/bash
# Beginner-Friendly Installer for Self Hosted Google Drive (DriveDAV)
# This script installs Apache, PHP, required modules, downloads your PHP files
# from GitHub, creates necessary folders, sets proper permissions, adjusts PHP's size limits
# for both CLI and Apache php.ini files, and verifies both.
# Run this as root (e.g., sudo bash install.sh)
#
# Your PHP files are hosted at:
#   https://github.com/david-xyz-abc/drivedavfinal
#
# They will be fetched from the "main" branch using the raw GitHub URL.

set -e  # Exit immediately if a command fails

# Log output for troubleshooting (optional)
LOGFILE="/var/log/selfhostedgdrive_install.log"
exec > >(tee -a "$LOGFILE") 2>&1

echo "======================================"
echo "Self Hosted Google Drive (DriveDAV) Installer"
echo "======================================"

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: This script must be run as root. Try: sudo bash install.sh"
  exit 1
fi

# Set the base URL where your PHP files are hosted (updated to new repo).
BASE_URL="https://raw.githubusercontent.com/david-xyz-abc/drivedavfinal/main"

# List of required PHP files (includes register.php)
FILES=("index.php" "authenticate.php" "explorer.php" "logout.php" "register.php")

# Update package lists
echo "Updating package lists..."
apt-get update

# Install Apache, PHP, and required PHP modules along with wget and curl for verification
echo "Installing Apache, PHP, and required modules..."
apt-get install -y apache2 php libapache2-mod-php php-cli php-json php-mbstring php-xml wget curl

# Define application directories
APP_DIR="/var/www/html/selfhostedgdrive"
WEBDAV_USERS_DIR="/var/www/html/webdav/users"
USERS_JSON="$APP_DIR/users.json"

# Create application directory
echo "Creating application directory at $APP_DIR..."
mkdir -p "$APP_DIR"

# Download PHP files from your GitHub repository into the application directory
echo "Downloading PHP files from GitHub..."
for file in "${FILES[@]}"; do
  FILE_URL="${BASE_URL}/${file}"
  echo "Fetching ${file} from ${FILE_URL}..."
  wget -q -O "$APP_DIR/$file" "$FILE_URL" || { echo "ERROR: Failed to download ${file}"; exit 1; }
done

# Create users.json if it doesn’t exist
echo "Setting up users.json at $USERS_JSON..."
if [ ! -f "$USERS_JSON" ]; then
  echo "{}" > "$USERS_JSON"
fi

# Set ownership and permissions for the application directory and users.json
echo "Setting permissions for $APP_DIR and $USERS_JSON..."
chown -R www-data:www-data "$APP_DIR"
chmod -R 755 "$APP_DIR"
chmod 664 "$USERS_JSON"  # Ensure www-data can write to users.json

# Create the WebDAV users directory for file storage
echo "Creating WebDAV users directory at $WEBDAV_USERS_DIR..."
mkdir -p "$WEBDAV_USERS_DIR"
chown -R www-data:www-data "/var/www/html/webdav"
chmod -R 775 "/var/www/html/webdav"  # 775 to allow group write access

# Determine PHP version
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

# Locate the php.ini files for CLI and Apache
CLI_PHP_INI="/etc/php/$PHP_VERSION/cli/php.ini"
APACHE_PHP_INI="/etc/php/$PHP_VERSION/apache2/php.ini"

# Check if both php.ini files exist
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

# Backup both php.ini files
echo "Backing up CLI php.ini to ${CLI_PHP_INI}.backup..."
cp "$CLI_PHP_INI" "${CLI_PHP_INI}.backup"
echo "Backing up Apache php.ini to ${APACHE_PHP_INI}.backup..."
cp "$APACHE_PHP_INI" "${APACHE_PHP_INI}.backup"

# Function to update PHP configuration
update_php_ini() {
  local ini_file="$1"
  echo "Adjusting PHP size limits in $ini_file..."
  sed -i 's/^\s*upload_max_filesize\s*=.*/upload_max_filesize = 10G/' "$ini_file"
  sed -i 's/^\s*post_max_size\s*=.*/post_max_size = 11G/' "$ini_file"
  sed -i 's/^\s*memory_limit\s*=.*/memory_limit = 12G/' "$ini_file"
  sed -i 's/^\s*max_execution_time\s*=.*/max_execution_time = 3600/' "$ini_file"
  sed -i 's/^\s*max_input_time\s*=.*/max_input_time = 3600/' "$ini_file"
}

# Update both php.ini files
update_php_ini "$CLI_PHP_INI"
update_php_ini "$APACHE_PHP_INI"
echo "PHP configuration updated for both CLI and Apache (backups saved)"

# Enable Apache mod_rewrite (optional)
echo "Enabling Apache mod_rewrite..."
a2enmod rewrite

# Restart Apache to apply changes
echo "Restarting Apache..."
systemctl restart apache2

# Verify the changes for both CLI and Apache
echo "======================================"
echo "Verifying CLI php.ini values..."
echo "--------------------------------------"
php -r '
echo "upload_max_filesize: " . ini_get("upload_max_filesize") . "\n";
echo "post_max_size: " . ini_get("post_max_size") . "\n";
echo "memory_limit: " . ini_get("memory_limit") . "\n";
echo "max_execution_time: " . ini_get("max_execution_time") . "\n";
echo "max_input_time: " . ini_get("max_input_time") . "\n";
'

echo "======================================"
echo "Verifying Apache php.ini values..."
echo "--------------------------------------"
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

# Fetch the server's public IP address
echo "Fetching public IP address..."
PUBLIC_IP=$(curl -s http://ifconfig.me || curl -s http://api.ipify.org || echo "Unable to fetch IP")
if [ "$PUBLIC_IP" = "Unable to fetch IP" ]; then
  echo "WARNING: Could not fetch public IP. Using 'your_server_address' instead."
  PUBLIC_IP="your_server_address"
fi

echo "======================================"
echo "Installation Complete!"
echo "Access your application at: http://$PUBLIC_IP/selfhostedgdrive/"
echo "If the IP doesn't work, check your server's network settings or use its local IP."
echo "======================================"
