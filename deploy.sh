#!/usr/bin/env bash

set -euo pipefail

PLUGIN_SLUG="bulk-page-duplicator"
PLUGIN_DIR="/Users/nazim/Local Sites/test/app/public/wp-content/plugins/${PLUGIN_SLUG}"
SVN_ROOT="/Users/nazim/Documents/Web Development/SVN/${PLUGIN_SLUG}"
SVN_TRUNK="${SVN_ROOT}/trunk"
SVN_ASSETS="${SVN_ROOT}/assets"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
WP_ORG_USER="${WP_ORG_USER:-nazim848}"

DRY_RUN=false
TRUNK_ONLY=false
VERSION=""

usage() {
	cat <<'EOF'
Usage: ./deploy.sh VERSION [--dry-run] [--trunk-only]

  VERSION       Release version, for example 1.2.0.
  --dry-run     Prepare and display SVN changes without committing.
  --trunk-only  Commit trunk and assets without creating a version tag.
EOF
}

fail() {
	echo "Error: $*" >&2
	exit 1
}

confirm() {
	local prompt="$1"
	local answer
	read -r -p "${prompt} [y/N] " answer
	case "$answer" in
		y|Y|yes|YES)
			return 0
			;;
		*)
			return 1
			;;
	esac
}

for argument in "$@"; do
	case "$argument" in
		--dry-run)
			DRY_RUN=true
			;;
		--trunk-only)
			TRUNK_ONLY=true
			;;
		-h|--help)
			usage
			exit 0
			;;
		-*)
			fail "Unknown option: ${argument}"
			;;
		*)
			if [ -n "$VERSION" ]; then
				fail "Only one version may be provided."
			fi
			VERSION="$argument"
			;;
	esac
done

[ -n "$VERSION" ] || {
	usage
	exit 1
}

printf '%s' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$' || fail "Invalid version: ${VERSION}"

command -v git >/dev/null 2>&1 || fail "git is required."
command -v rsync >/dev/null 2>&1 || fail "rsync is required."
command -v svn >/dev/null 2>&1 || fail "svn is required."

[ -d "${PLUGIN_DIR}/.git" ] || fail "Git checkout not found: ${PLUGIN_DIR}"
[ -d "${SVN_ROOT}/.svn" ] || fail "SVN checkout not found: ${SVN_ROOT}"
[ -d "$SVN_TRUNK" ] || fail "SVN trunk not found: ${SVN_TRUNK}"
[ -d "$SVN_ASSETS" ] || fail "SVN assets directory not found: ${SVN_ASSETS}"

if [ -n "$(git -C "$PLUGIN_DIR" status --porcelain)" ]; then
	fail "Git working tree is not clean. Commit or stash changes before deploying."
fi

HEADER_VERSION=$(sed -n 's/^[[:space:]]*Version:[[:space:]]*//p' "${PLUGIN_DIR}/bulk-page-duplicator.php" | head -n 1)
CONSTANT_VERSION=$(sed -n "s/.*define('BULK_PAGE_DUPLICATOR_VERSION',[[:space:]]*'\([^']*\)').*/\1/p" "${PLUGIN_DIR}/bulk-page-duplicator.php" | head -n 1)
STABLE_TAG=$(sed -n 's/^Stable tag:[[:space:]]*//p' "${PLUGIN_DIR}/readme.txt" | head -n 1)

[ "$HEADER_VERSION" = "$VERSION" ] || fail "Plugin header version is ${HEADER_VERSION}; expected ${VERSION}."
[ "$CONSTANT_VERSION" = "$VERSION" ] || fail "BULK_PAGE_DUPLICATOR_VERSION is ${CONSTANT_VERSION}; expected ${VERSION}."
[ "$STABLE_TAG" = "$VERSION" ] || fail "README stable tag is ${STABLE_TAG}; expected ${VERSION}."

TAG_URL="${SVN_URL}/tags/${VERSION}"
if [ "$TRUNK_ONLY" = false ] && svn ls "$TAG_URL" >/dev/null 2>&1; then
	fail "SVN tag ${VERSION} already exists."
fi

echo "Release: ${PLUGIN_SLUG} ${VERSION}"
echo "Git source: ${PLUGIN_DIR}"
echo "SVN checkout: ${SVN_ROOT}"
echo

echo "Updating SVN working copy..."
svn update "$SVN_ROOT"

echo "Synchronizing Git files to SVN trunk..."
rsync -a --delete "${PLUGIN_DIR}/" "${SVN_TRUNK}/" \
	--exclude='.git' \
	--exclude='.env' \
	--exclude='.env.*' \
	--exclude='.DS_Store' \
	--exclude='.commandcode' \
	--exclude='.cursor' \
	--exclude='.cursorignore' \
	--exclude='.gitignore' \
	--exclude='deploy.sh' \
	--exclude='node_modules' \
	--exclude='vendor' \
	--exclude='README.md'

# Remove metadata or secrets that may exist from an older deployment.
find "$SVN_ROOT" -name '.DS_Store' -type f -delete
find "$SVN_TRUNK" -maxdepth 1 -type f \( -name '.env' -o -name '.env.*' \) -delete

if find "$SVN_TRUNK" -type f \( -name '.env' -o -name '.env.*' \) | grep -q .; then
	fail "A secret-bearing environment file exists in SVN trunk."
fi

echo "Registering new SVN files..."
svn add "$SVN_TRUNK" --force --quiet
svn add "$SVN_ASSETS" --force --quiet

echo "Registering SVN deletions..."
svn status "$SVN_TRUNK" "$SVN_ASSETS" | while IFS= read -r status_line; do
	if [ "${status_line:0:1}" = '!' ]; then
		missing_path="${status_line:8}"
		svn delete --force "$missing_path"
	fi
done

echo
echo "SVN changes:"
svn status "$SVN_TRUNK" "$SVN_ASSETS"
echo
svn diff --summarize "$SVN_TRUNK" "$SVN_ASSETS"
echo

if [ -z "$(svn status "$SVN_TRUNK" "$SVN_ASSETS")" ]; then
	echo "No SVN changes to deploy."
	exit 0
fi

if [ "$DRY_RUN" = true ]; then
	echo "Dry run complete. The SVN working copy was prepared but not committed."
	exit 0
fi

confirm "Commit these changes to WordPress.org SVN?" || {
	echo "Deployment cancelled. The SVN working copy still contains the prepared changes."
	exit 0
}

svn commit "$SVN_TRUNK" "$SVN_ASSETS" -m "Release ${VERSION}" --username "$WP_ORG_USER"

if [ "$TRUNK_ONLY" = false ]; then
	confirm "Create SVN tag ${VERSION}?" || {
		echo "Trunk was committed, but the release tag was not created."
		exit 0
	}

	svn copy "${SVN_URL}/trunk" "$TAG_URL" -m "Tagging version ${VERSION}" --username "$WP_ORG_USER"
fi

echo "Deployment complete."
