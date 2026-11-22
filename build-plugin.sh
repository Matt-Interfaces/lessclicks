#!/bin/bash

# Lessclicks Plugin Build Script
# Creates an installable ZIP archive for WordPress.

set -e

echo "🚀 Lessclicks Plugin Build Script"
echo "===================================="

# Variables
PLUGIN_NAME="lessclicks-analytics-for-matomo"
VERSION="0.9.3"
BUILD_DIR="./build"
DIST_DIR="./dist"

# Output colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Step 1: cleanup
echo -e "${YELLOW}📦 Cleaning old builds...${NC}"
rm -rf "$BUILD_DIR"
rm -rf "$DIST_DIR"
rm -f app/public/build/*.map.js
mkdir -p "$BUILD_DIR/$PLUGIN_NAME"
mkdir -p "$DIST_DIR"

# Step 2: install dependencies and build the Svelte apps
echo -e "${YELLOW}📦 Installing dependencies...${NC}"
cd app
if [ "${CI}" = "true" ]; then
    npm ci --legacy-peer-deps
else
    npm install --legacy-peer-deps
fi
echo -e "${YELLOW}🔨 Building Svelte components...${NC}"
npm run build
cd ..

# Step 3: copy files
echo -e "${YELLOW}📁 Copying plugin files...${NC}"

# Core files
cp lessclicks-analytics-for-matomo.php "$BUILD_DIR/$PLUGIN_NAME/"
cp uninstall.php "$BUILD_DIR/$PLUGIN_NAME/"
cp readme.txt "$BUILD_DIR/$PLUGIN_NAME/"
cp LICENSE "$BUILD_DIR/$PLUGIN_NAME/"

# Assets
mkdir -p "$BUILD_DIR/$PLUGIN_NAME/assets/css"
mkdir -p "$BUILD_DIR/$PLUGIN_NAME/assets/js"
cp -r assets/css/* "$BUILD_DIR/$PLUGIN_NAME/assets/css/"
if [ -d "assets/js" ]; then
    cp -r assets/js/* "$BUILD_DIR/$PLUGIN_NAME/assets/js/"
fi
# Screenshot files are WordPress.org directory assets and must never ship in the
# plugin ZIP. They are uploaded separately via SVN after approval.
find assets -maxdepth 1 -type f \( -name "*.gif" -o -name "*.png" \) \
    ! -name "screenshot-*" ! -name "share-icon.png" -exec cp {} "$BUILD_DIR/$PLUGIN_NAME/assets/" \;
if [ -d "assets/images" ]; then
    mkdir -p "$BUILD_DIR/$PLUGIN_NAME/assets/images"
    cp -r assets/images/* "$BUILD_DIR/$PLUGIN_NAME/assets/images/"
    find "$BUILD_DIR/$PLUGIN_NAME/assets/images" -type f \( -name "*.xcf" -o -name "*.psd" -o -name "*.ai" -o -name "*.sketch" \) -delete
    # Marketing image, not used by the plugin at runtime.
    rm -f "$BUILD_DIR/$PLUGIN_NAME/assets/images/lessclicks-og-1200x630.png"
    # WordPress.org directory icons live in SVN assets/ and are not needed in the package.
    rm -f "$BUILD_DIR/$PLUGIN_NAME/assets/images/icon-128x128.png" \
          "$BUILD_DIR/$PLUGIN_NAME/assets/images/icon-256x256.png"
fi

# Compiled Svelte builds (only JS and CSS)
mkdir -p "$BUILD_DIR/$PLUGIN_NAME/app/public/build"
cp app/public/build/*.js "$BUILD_DIR/$PLUGIN_NAME/app/public/build/"
cp app/public/build/*.css "$BUILD_DIR/$PLUGIN_NAME/app/public/build/"

# Includes (PHP classes)
mkdir -p "$BUILD_DIR/$PLUGIN_NAME/includes"
if [ -d "includes" ]; then
    cp -r includes/*.php "$BUILD_DIR/$PLUGIN_NAME/includes/"
fi

# Plugin Update Checker is intentionally excluded from WordPress.org builds.
# Updates will be delivered via the WordPress.org plugin directory once approved.

# Languages (.pot template + compiled .mo and source .po)
mkdir -p "$BUILD_DIR/$PLUGIN_NAME/languages"
if ls languages/*.{pot,po,mo} >/dev/null 2>&1; then
    cp languages/*.{pot,po,mo} "$BUILD_DIR/$PLUGIN_NAME/languages/"
fi

# Step 4: verify the staged build against WordPress.org review rules
echo -e "${YELLOW}🔍 Verifying build for WordPress.org...${NC}"
if grep -rq "cdnjs.cloudflare.com" "$BUILD_DIR/$PLUGIN_NAME"; then
    echo "❌ Remote CDN reference found in the build. Aborting."
    exit 1
fi
if find "$BUILD_DIR/$PLUGIN_NAME" -maxdepth 2 -name "screenshot-*" | grep -q .; then
    echo "❌ WordPress.org screenshot assets found in the build. Aborting."
    exit 1
fi

# Step 5: create ZIP archive
echo -e "${YELLOW}📦 Creating ZIP archive...${NC}"
cd "$BUILD_DIR"
zip -r "../$DIST_DIR/${PLUGIN_NAME}-${VERSION}.zip" "$PLUGIN_NAME" -x "*.DS_Store" -x "*__MACOSX*"
cd ..

# Step 6: cleanup build staging dir
rm -rf "$BUILD_DIR"

# Done
echo ""
echo -e "${GREEN}✅ Build successful!${NC}"
echo -e "${GREEN}📦 Plugin ZIP: $DIST_DIR/${PLUGIN_NAME}-${VERSION}.zip${NC}"
echo ""
echo "Installation:"
echo "1. Go to WordPress Admin → Plugins → Add New → Upload Plugin"
echo "2. Select the ZIP file: ${PLUGIN_NAME}-${VERSION}.zip"
echo "3. Click 'Install Now' and then 'Activate'"

