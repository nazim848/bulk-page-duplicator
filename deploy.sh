#!/usr/bin/env bash
#
# Release Bulk Page Duplicator to WordPress.org SVN.
#
# The release is built from the pushed Git tag v<VERSION>, never from the
# working tree, so uncommitted or unpushed changes cannot be shipped. The same
# script works on any machine; per-machine paths and credentials live in a
# config file outside the repository (see CONFIG below).

set -euo pipefail

PLUGIN_SLUG="bulk-page-duplicator"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Per-machine settings. Example ~/.config/wporg/bulk-page-duplicator.env:
#   PLUGIN_DIR="/path/to/git/checkout"          # default: this script's folder
#   SVN_ROOT="/path/to/svn/bulk-page-duplicator" # default: ~/svn/bulk-page-duplicator
#   WP_ORG_USER="nazim848"
#   WP_ORG_SVN_PASSWORD="..."                    # optional; svn prompts if unset
# Keep the file private: chmod 600.
CONFIG="${BPD_DEPLOY_CONFIG:-${HOME}/.config/wporg/${PLUGIN_SLUG}.env}"
if [ -f "$CONFIG" ]; then
	# shellcheck source=/dev/null
	. "$CONFIG"
fi

PLUGIN_DIR="${PLUGIN_DIR:-$SCRIPT_DIR}"
SVN_ROOT="${SVN_ROOT:-${HOME}/svn/${PLUGIN_SLUG}}"
SVN_TRUNK="${SVN_ROOT}/trunk"
SVN_ASSETS="${SVN_ROOT}/assets"
WP_ORG_USER="${WP_ORG_USER:-nazim848}"
WP_ORG_SVN_PASSWORD="${WP_ORG_SVN_PASSWORD:-}"

DRY_RUN=false
TRUNK_ONLY=false
ASSUME_YES=false
VERSION=""
BUILD_DIR=""

usage() {
	cat <<'EOF'
Usage: ./deploy.sh VERSION [--dry-run] [--trunk-only] [--yes]

  VERSION       Release version, for example 1.2.0. Git tag vVERSION must be pushed.
  --dry-run     Prepare and display SVN changes without committing.
  --trunk-only  Commit trunk and assets without creating a version tag.
  --yes         Do not ask for confirmation before committing and tagging.

Settings are read from ~/.config/wporg/bulk-page-duplicator.env
(override the path with BPD_DEPLOY_CONFIG).
EOF
}

fail() {
	echo "Error: $*" >&2
	exit 1
}

cleanup() {
	if [ -n "$BUILD_DIR" ] && [ -d "$BUILD_DIR" ]; then
		rm -rf "$BUILD_DIR"
	fi
}
trap cleanup EXIT

confirm() {
	local prompt="$1"
	local answer

	if [ "$ASSUME_YES" = true ]; then
		return 0
	fi

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

# Run an SVN command that writes to WordPress.org. The password, when set, is
# passed on stdin so it never appears in the process list or shell history.
svn_write() {
	if [ -n "$WP_ORG_SVN_PASSWORD" ]; then
		printf '%s' "$WP_ORG_SVN_PASSWORD" | svn "$@" --username "$WP_ORG_USER" --password-from-stdin --no-auth-cache --non-interactive
	else
		svn "$@" --username "$WP_ORG_USER" --no-auth-cache
	fi
}

for argument in "$@"; do
	case "$argument" in
		--dry-run)
			DRY_RUN=true
			;;
		--trunk-only)
			TRUNK_ONLY=true
			;;
		--yes|-y)
			ASSUME_YES=true
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
command -v tar >/dev/null 2>&1 || fail "tar is required."

if [ -f "$CONFIG" ] && [ -n "$WP_ORG_SVN_PASSWORD" ]; then
	config_mode=$(stat -c '%a' "$CONFIG" 2>/dev/null || stat -f '%Lp' "$CONFIG")
	case "$config_mode" in
		600|400) ;;
		*) fail "${CONFIG} contains a password but is readable by others. Run: chmod 600 \"${CONFIG}\"" ;;
	esac
fi

git -C "$PLUGIN_DIR" rev-parse --git-dir >/dev/null 2>&1 || fail "Git checkout not found: ${PLUGIN_DIR}"

# The release tag must exist on GitHub; fetch it so the local tag matches.
GIT_TAG="v${VERSION}"
echo "Fetching ${GIT_TAG} from origin..."
git -C "$PLUGIN_DIR" ls-remote --exit-code --tags origin "refs/tags/${GIT_TAG}" >/dev/null 2>&1 || fail "Tag ${GIT_TAG} is not on origin. Push it first: git push origin ${GIT_TAG}"
git -C "$PLUGIN_DIR" fetch --quiet --force origin "refs/tags/${GIT_TAG}:refs/tags/${GIT_TAG}"
GIT_COMMIT=$(git -C "$PLUGIN_DIR" rev-parse "${GIT_TAG}^{commit}")

# Build the release from the tag only.
BUILD_DIR=$(mktemp -d)
git -C "$PLUGIN_DIR" archive "$GIT_TAG" | tar -x -C "$BUILD_DIR"

HEADER_VERSION=$(sed -n 's/^[[:space:]]*Version:[[:space:]]*//p' "${BUILD_DIR}/bulk-page-duplicator.php" | head -n 1)
CONSTANT_VERSION=$(sed -n "s/.*define('BULK_PAGE_DUPLICATOR_VERSION',[[:space:]]*'\([^']*\)').*/\1/p" "${BUILD_DIR}/bulk-page-duplicator.php" | head -n 1)
STABLE_TAG=$(sed -n 's/^Stable tag:[[:space:]]*//p' "${BUILD_DIR}/readme.txt" | head -n 1)

[ "$HEADER_VERSION" = "$VERSION" ] || fail "Plugin header version in ${GIT_TAG} is ${HEADER_VERSION}; expected ${VERSION}."
[ "$CONSTANT_VERSION" = "$VERSION" ] || fail "BULK_PAGE_DUPLICATOR_VERSION in ${GIT_TAG} is ${CONSTANT_VERSION}; expected ${VERSION}."
[ "$STABLE_TAG" = "$VERSION" ] || fail "README stable tag in ${GIT_TAG} is ${STABLE_TAG}; expected ${VERSION}."

TAG_URL="${SVN_URL}/tags/${VERSION}"
if [ "$TRUNK_ONLY" = false ] && svn ls --non-interactive "$TAG_URL" >/dev/null 2>&1; then
	fail "SVN tag ${VERSION} already exists."
fi

# First run on a machine: create a small checkout (trunk and assets only).
if [ ! -d "${SVN_ROOT}/.svn" ]; then
	echo "Creating SVN checkout in ${SVN_ROOT}..."
	mkdir -p "$(dirname "$SVN_ROOT")"
	svn checkout --quiet --non-interactive --depth immediates "$SVN_URL" "$SVN_ROOT"
	svn update --quiet --non-interactive --set-depth infinity "$SVN_TRUNK" "$SVN_ASSETS"
fi
[ -d "$SVN_TRUNK" ] || fail "SVN trunk not found: ${SVN_TRUNK}"
[ -d "$SVN_ASSETS" ] || fail "SVN assets directory not found: ${SVN_ASSETS}"

echo "Release: ${PLUGIN_SLUG} ${VERSION}"
echo "Git source: ${GIT_TAG} (${GIT_COMMIT})"
echo "SVN checkout: ${SVN_ROOT}"
echo "SVN user: ${WP_ORG_USER}"
echo

echo "Updating SVN working copy..."
svn update --non-interactive "$SVN_ROOT"

echo "Synchronizing ${GIT_TAG} to SVN trunk..."
rsync -a --delete "${BUILD_DIR}/" "${SVN_TRUNK}/" \
	--exclude='.git' \
	--exclude='.env' \
	--exclude='.env.*' \
	--exclude='.DS_Store' \
	--exclude='.commandcode' \
	--exclude='.cursor' \
	--exclude='.cursorignore' \
	--exclude='.gitignore' \
	--exclude='.gitattributes' \
	--exclude='.github' \
	--exclude='.wordpress-org' \
	--exclude='.claude' \
	--exclude='AGENTS.md' \
	--exclude='CLAUDE.md' \
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

svn_write commit "$SVN_TRUNK" "$SVN_ASSETS" -m "Release ${VERSION}"

if [ "$TRUNK_ONLY" = false ]; then
	confirm "Create SVN tag ${VERSION}?" || {
		echo "Trunk was committed, but the release tag was not created."
		exit 0
	}

	svn_write copy "${SVN_URL}/trunk" "$TAG_URL" -m "Tagging version ${VERSION}"
fi

echo "Deployment complete: ${PLUGIN_SLUG} ${VERSION} from ${GIT_TAG} (${GIT_COMMIT})."
