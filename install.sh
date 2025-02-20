#!/bin/bash
# Beginner-Friendly Installer for Self Hosted Google Drive (DriveDAV)
# This script installs Apache, PHP, required modules, downloads your PHP files
# from GitHub, creates necessary folders, sets proper permissions, and adjusts PHP's size limits.
# Run this as root (e.g., sudo bash install.sh)
#
# Your PHP files are hosted at:
#   https://github.com/david-xyz-abc/drivedav
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

# Set the base URL where your PHP files are hosted.
BASE_URL="https://raw.githubusercontent.com/david-xyz-abc/drivedav/main"

# List of required PHP files (updated to include register.php)
FILES=("index.php" "authenticate.php" "explorer.php" "logout.php" "register.php")

# Update package lists
echo "Updating package lists..."
apt-get update

# Install Apache, PHP, and required PHP modules along with wget for downloading files
echo "Installing Apache, PHP, and required modules..."
apt-get install -y apache2 php libapache2-mod-php php-cli php-json php-mbstring php-xml wget

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

# Locate the php.ini file used by Apache
PHP_INI=$(php --ini | grep "Loaded Configuration" | sed -E 's|^.*:\s+||' | head -n 1)
if [ -z "$PHP_INI" ]; then
  echo "ERROR: Unable to detect php.ini. Exiting."
  exit 1
fi

echo "Found php.ini at: $PHP_INI"
echo "Backing up php.ini to ${PHP_INI}.backup..."
cp "$PHP_INI" "${PHP_INI}.backup"

# Update PHP configuration to allow larger file uploads
echo "Adjusting PHP size limits in $PHP_INI..."
sed -i 's/^\s*upload_max_filesize\s*=.*/upload_max_filesize = 10G/' "$PHP_INI"
sed -i 's/^\s*post_max_size\s*=.*/post_max_size = 11G/' "$PHP_INI"
sed -i 's/^\s*memory_limit\s*=.*/memory_limit = 12G/' "$PHP_INI"
sed -i 's/^\s*max_execution_time\s*=.*/max_execution_time = 3600/' "$PHP_INI"
sed -i 's/^\s*max_input_time\s*=.*/max_input_time = 3600/' "$PHP_INI"
echo "PHP configuration updated (backup saved as ${PHP_INI}.backup)"

# Enable Apache mod_rewrite (optional)
echo "Enabling Apache mod_rewrite..."
a2enmod rewrite

# Restart Apache to apply changes
echo "Restarting Apache..."
systemctl restart apache2

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
