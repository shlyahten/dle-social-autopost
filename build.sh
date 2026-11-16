#!/usr/bin/env bash
# ==============================================================================
# DLE Social AutoPost Plugin - Build & Release Packaging Script
# ==============================================================================

set -e

VERSION="1.1.0"
DIST_DIR="dist"
ARCHIVE_NAME="dle-social-autopost-v${VERSION}.zip"
XML_NAME="dle-social-autopost.xml"

echo "=========================================================="
echo " Building DLE Social AutoPost v${VERSION}"
echo "=========================================================="

# 1. PHP Syntax Check
echo "[1/4] Running PHP syntax linting..."
find engine/ cron/ -name "*.php" -exec php -l {} \; > /dev/null
echo "  ✓ All PHP files passed syntax check."

# 2. XML Validation
echo "[2/4] Validating plugin.xml structure..."
php -r '
$xml = simplexml_load_file("plugin.xml");
if ($xml === false) {
    fwrite(STDERR, "Error: Invalid plugin.xml!\n");
    exit(1);
}
if (!isset($xml->name) || !isset($xml->version) || !isset($xml->mysqlinstall)) {
    fwrite(STDERR, "Error: Missing mandatory DLE plugin tags in plugin.xml!\n");
    exit(1);
}
echo "  ✓ plugin.xml is valid.\n";
'

# 3. Prepare dist directory
echo "[3/4] Preparing output directory..."
mkdir -p "${DIST_DIR}"
rm -f "${DIST_DIR}/${ARCHIVE_NAME}"
rm -f "${DIST_DIR}/${XML_NAME}"

cp plugin.xml "${DIST_DIR}/${XML_NAME}"
cp plugin.xml "docs/${XML_NAME}"
cp dist/update.json "docs/update.json"

# 4. Create ZIP distribution for DLE Plugin Manager
echo "[4/4] Packing distribution ZIP archive..."
zip -q -r "${DIST_DIR}/${ARCHIVE_NAME}" \
    plugin.xml \
    engine/classes/autopost/*.php \
    engine/inc/autopost.php \
    engine/skins/images/autopost.png \
    cron/autopost.php \
    docs/*.md \
    README.md

cp "${DIST_DIR}/${ARCHIVE_NAME}" "docs/${ARCHIVE_NAME}"

echo "=========================================================="
echo " Build successful!"
echo " XML file:    ${DIST_DIR}/${XML_NAME}"
echo " ZIP package: ${DIST_DIR}/${ARCHIVE_NAME}"
echo " Size:        $(du -h "${DIST_DIR}/${ARCHIVE_NAME}" | cut -f1)"
echo "=========================================================="
