#!/usr/bin/env sh
# Build the wordpress.org submission/deploy tree.
#
# The directory lists this plugin as "Klarsmith Ops Kit" with the slug
# klarsmith-ops-kit (wordpress.org bans "wp" and "wordpress" in new slugs, and
# wants a distinctive brand-first name), while GitHub, Packagist and Composer
# installs keep klarsmith/wp-ops-kit. So the
# distributable tree is the git export (see .gitattributes export-ignore) under
# the directory slug, with the main file renamed to match. Nothing inside the
# code depends on either name.
#
# Usage: bin/build-wporg-zip.sh [git-ref] [out-dir]   (defaults: HEAD, build/)
set -eu

ref="${1:-HEAD}"
out="${2:-build}"
slug="klarsmith-ops-kit"

rm -rf "$out/$slug"
mkdir -p "$out/$slug"
git archive --worktree-attributes "$ref" | tar -x -C "$out/$slug"
mv "$out/$slug/wp-ops-kit.php" "$out/$slug/$slug.php"
# examples/ and the Composer manifest are for the git/Packagist audience only.
rm -rf "$out/$slug/examples" "$out/$slug/composer.json"

version="$(sed -n 's/^ \* Version: *//p' "$out/$slug/$slug.php")"
(cd "$out" && rm -f "$slug-$version.zip" && zip -qr "$slug-$version.zip" "$slug")
echo "$out/$slug-$version.zip"
