#!/bin/sh
# Builds build/clikgate/ and build/clikgate.zip from the plugin folder, without the files in .distignore.
# This is the zip the owner uploads for the first WordPress.org review, and what the SVN deploy copies.
set -eu
cd "$(dirname "$0")/.."
rm -rf build
mkdir -p build/clikgate
# Copy everything, then delete the .distignore entries (each line is a path from the plugin root)
tar --exclude=./build -cf - . | tar -xf - -C build/clikgate
while IFS= read -r line; do
  case "$line" in ''|'#'*) continue ;; esac
  rm -rf "build/clikgate/${line#/}"
done < .distignore
find build/clikgate -name '.DS_Store' -delete
(cd build && zip -qr clikgate.zip clikgate)
echo "built build/clikgate.zip"
