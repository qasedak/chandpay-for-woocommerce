#!/usr/bin/env bash
# Build a distributable WordPress plugin zip (slug folder at archive root).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SLUG="chandpay-for-woocommerce"
MAIN_FILE="${ROOT}/${SLUG}.php"

if [[ ! -f "${MAIN_FILE}" ]]; then
	echo "error: expected plugin bootstrap at ${MAIN_FILE}" >&2
	exit 1
fi

VERSION="$(
	grep -m1 '^[[:space:]]*\*[[:space:]]*Version:' "${MAIN_FILE}" \
		| sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//;s/[[:space:]]*\*.*$//'
)"
if [[ -z "${VERSION}" ]]; then
	VERSION="dev"
fi

OUT_DIR="${ROOT}/dist"
ZIP_NAME="${SLUG}-${VERSION}.zip"
ZIP_PATH="${OUT_DIR}/${ZIP_NAME}"

STAGING="$(mktemp -d)"
trap 'rm -rf "${STAGING}"' EXIT

mkdir -p "${OUT_DIR}" "${STAGING}/${SLUG}"

rsync -a \
	--exclude='.git/' \
	--exclude='.gitignore' \
	--exclude='.phpunit.result.cache' \
	--exclude='dist/' \
	--exclude='tests/' \
	--exclude='phpunit.xml.dist' \
	--exclude='vendor/' \
	--exclude='make-zip.sh' \
	--exclude='*.zip' \
	"${ROOT}/" "${STAGING}/${SLUG}/"

(
	cd "${STAGING}"
	zip -rq "${ZIP_PATH}" "${SLUG}"
)

echo "Created ${ZIP_PATH}"