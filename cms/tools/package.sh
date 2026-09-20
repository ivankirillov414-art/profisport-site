#!/usr/bin/env bash
set -euo pipefail
release_dir="${1:?Specify a fresh output directory}"
if [ -e "$release_dir" ]; then echo 'Output directory already exists' >&2; exit 1; fi
mkdir -p "$release_dir/private" "$release_dir/media"
tar --exclude='private/config.php' --exclude='private/bindings.json' --exclude='private/templates.json' --exclude='media/*' --exclude=tests --exclude=tools --exclude='demo*' -C cms -cf - . | tar -C "$release_dir" -xf -
cp cms/media/.htaccess "$release_dir/media/.htaccess"
printf '%s\n' '{"site":"generic","pages":{}}' > "$release_dir/private/bindings.json"
(cd "$release_dir" && zip -qr "${release_dir}.zip" .)
echo "Portable release: ${release_dir}.zip"
