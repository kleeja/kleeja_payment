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

# Without the PayPal and Stripe SDKs the plugin offers no payment method, so
# an archive missing either of them is useless to the sites that install it.
for required in \
    vendor/autoload.php \
    vendor/paypal/paypal-server-sdk/src/PaypalServerSdkClientBuilder.php \
    vendor/stripe/stripe-php/lib/StripeClient.php; do
    if [ ! -f "${BUILD_DIR}/${required}" ]; then
        echo "composer did not install ${required}, refusing to build" >&2
        exit 1
    fi
done

# 3) create the release archive
( cd build && zip -qr "../${PLUGIN_NAME}-${VERSION}.zip" "${PLUGIN_NAME}" )

echo "Created ${PLUGIN_NAME}-${VERSION}.zip"
