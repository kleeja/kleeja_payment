#!/usr/bin/env bash
set -euo pipefail

PLUGIN_NAME="kleeja_payment"
VERSION="${1:-dev}"
BUILD_DIR="build/${PLUGIN_NAME}"

# The archive has to unpack into a folder named after the plugin: kleeja keys
# every hook off plugins/<name>, so a differently named folder installs a
# plugin whose hooks are never called.
rm -rf build
mkdir -p "${BUILD_DIR}"

# 1) copy only the application files (exclude dev artifacts)
rsync -a --exclude-from='.distignore' ./ "${BUILD_DIR}/"

# 2) install production dependencies inside the build dir
composer install \
    --working-dir="${BUILD_DIR}" \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --classmap-authoritative \
    --no-interaction

# PHPMailer is the whole point of the plugin; shipping without it would leave
# the plugin permanently idle on the sites that install this archive.
if [ ! -f "${BUILD_DIR}/vendor/phpmailer/phpmailer/src/PHPMailer.php" ]; then
    echo "composer did not install PHPMailer, refusing to build" >&2
    exit 1
fi

# 3) create the release archive
( cd build && zip -qr "../${PLUGIN_NAME}-${VERSION}.zip" "${PLUGIN_NAME}" )

echo "Created ${PLUGIN_NAME}-${VERSION}.zip"
