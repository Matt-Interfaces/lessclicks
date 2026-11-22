#!/bin/bash
#
# Deploy Lessclicks to the WordPress.org plugin directory via SVN.
# Run from the repository root:  ./deploy-svn.sh
#
# Credentials come from ./.env (see .env.example). Without .env, svn asks
# interactively. The password is handed to svn via stdin, so it never shows
# up in `ps`, in the shell history or in a log.

set -euo pipefail
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

# Read .env without sourcing it: only the keys we expect, no shell expansion.
load_env() {
    local file="$1" line key value
    [ -f "$file" ] || return 0
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            ''|'#'*) continue ;;
        esac
        case "$line" in
            *=*) ;;
            *) continue ;;
        esac
        key="${line%%=*}"
        value="${line#*=}"
        case "$value" in
            \"*\") value="${value#\"}"; value="${value%\"}" ;;
            \'*\') value="${value#\'}"; value="${value%\'}" ;;
        esac
        case "$key" in
            SVN_USERNAME|SVN_PASSWORD) export "$key=$value" ;;
        esac
    done < "$file"
}

load_env "$ROOT/.env"

MAIN_PHP="lessclicks-analytics-for-matomo.php"
PLUGIN="lessclicks-analytics-for-matomo"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN}"
SVN_USER="${SVN_USERNAME:-choooomedia}"

VERSION="$(sed -n 's/^[[:space:]]*[*][[:space:]]*Version:[[:space:]]*//p' "$MAIN_PHP" | head -1 | tr -d '\r[:space:]')"
VERSION="${VERSION:-0.9.3}"
ZIP="dist/${PLUGIN}-${VERSION}.zip"

if ! command -v svn >/dev/null 2>&1; then
    echo "svn is not installed. Run: brew install subversion"
    exit 1
fi

if [ ! -f "$ZIP" ]; then
    echo "Missing $ZIP. Run ./build-plugin.sh first."
    exit 1
fi

if [ -z "${SVN_PASSWORD:-}" ]; then
    echo "No SVN_PASSWORD in .env, svn will ask for it. Fill .env to avoid that."
fi

# Password goes in via stdin, so it never lands in the process list.
if svn help commit --password-from-stdin </dev/null >/dev/null 2>&1; then
    SVN_HAS_PASSWORD_STDIN=1
else
    SVN_HAS_PASSWORD_STDIN=0
fi

if [ -n "${SVN_PASSWORD:-}" ] && [ "$SVN_HAS_PASSWORD_STDIN" -eq 0 ]; then
    echo "This svn build cannot read the password from stdin. Update subversion"
    echo "or run without SVN_PASSWORD and type it once."
    exit 1
fi

svn_auth() {
    if [ -n "${SVN_PASSWORD:-}" ]; then
        printf '%s\n' "$SVN_PASSWORD" \
            | svn "$@" --username "$SVN_USER" --password-from-stdin \
                  --non-interactive --no-auth-cache
    else
        svn "$@" --username "$SVN_USER"
    fi
}

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "Version: ${VERSION}"
echo "User:    ${SVN_USER}"
echo "Repo:    ${SVN_URL}"
echo

if ! svn_auth info "$SVN_URL" >/dev/null 2>"$WORK/repo.err"; then
    echo "Cannot reach the repository. Check SVN_USERNAME and SVN_PASSWORD in .env."
    sed 's/^/  /' "$WORK/repo.err"
    exit 1
fi

if svn_auth info "${SVN_URL}/tags" >/dev/null 2>&1 \
   && svn_auth ls "${SVN_URL}/tags" | grep -qx "${VERSION}/"; then
    echo "Tag ${VERSION} already exists. Bump the version before deploying."
    exit 1
fi

echo "Unpacking $ZIP"
unzip -q "$ZIP" -d "$WORK/unpacked"

echo "Checking out $SVN_URL"
svn_auth checkout "$SVN_URL" "$WORK/svn"

# Make sure the standard layout exists.
for dir in trunk tags branches assets; do
    if [ ! -d "$WORK/svn/$dir" ]; then
        echo "Creating $dir"
        svn_auth mkdir "$SVN_URL/$dir" -m "Create $dir directory"
        svn_auth update "$WORK/svn"
    fi
done

echo "Syncing trunk"
find "$WORK/svn/trunk" -mindepth 1 -maxdepth 1 ! -name .svn -exec rm -rf {} +
cp -R "$WORK/unpacked/${PLUGIN}/." "$WORK/svn/trunk/"
svn add --force "$WORK/svn/trunk" >/dev/null 2>&1 || true

echo "Staged changes:"
svn status "$WORK/svn/trunk"

read -r -p "Commit trunk as ${VERSION}? [y/N] " answer
if [ "$answer" != "y" ]; then
    echo "Aborted. Nothing was committed."
    exit 0
fi

svn_auth commit "$WORK/svn/trunk" -m "Release ${VERSION}"

echo "Tagging ${VERSION}"
svn_auth copy "${SVN_URL}/trunk" "${SVN_URL}/tags/${VERSION}" -m "Tag ${VERSION}"

echo "Verifying tag"
if svn_auth ls "${SVN_URL}/tags/" | grep -qx "${VERSION}/"; then
    echo "Tag ${VERSION} is in the repository."
else
    echo "Tag ${VERSION} is not visible yet. Check the log above."
fi

echo
echo "Done. Public page: https://wordpress.org/plugins/${PLUGIN}/"
echo "Directory sync can take a few minutes."
