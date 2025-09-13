#!/usr/bin/env bash
# dump-php-src.sh
# Collect all .php files under ./src and combine into a single file in ./dump/
# Each file is preceded by a header containing its relative path.
# Safe: writes to a temp file then atomically moves to final output.

set -euo pipefail

SRC_DIR="./src"
OUT_DIR="./dump"
OUT_FILE="combined_src_dump.txt"
TMP_FILE="$(mktemp "$(basename "$OUT_FILE").XXXXXX")"

# Optional: change these if you want different output naming
FULL_OUT_PATH="$OUT_DIR/$OUT_FILE"

# Ensure source exists
if [[ ! -d "$SRC_DIR" ]]; then
  echo "Source directory '$SRC_DIR' not found." >&2
  exit 2
fi

# Create output dir
mkdir -p "$OUT_DIR"

# Header for combined file
cat > "$TMP_FILE" <<'EOF'
/*
  Combined PHP source dump
  Generated: '"'"'$(date -u +"%Y-%m-%d %H:%M:%SZ")'"'"'
  Source directory: ./src
  --- Files concatenated below ---
*/
EOF

# Find php files (sorted), then append with headers
# Use -print0 and read -d '' to handle spaces/newlines in filenames
# sort -z ensures deterministic order (lexicographic)
if ! find "$SRC_DIR" -type f -name '*.php' -print0 \
     | sort -z \
     | while IFS= read -r -d '' file; do
         # Print a clear separator + relative path (POSIX style)
         printf '\n\n/* ======================================================\n   FILE: %s\n   ====================================================== */\n\n' "$file" >> "$TMP_FILE"
         # Append file content
         cat "$file" >> "$TMP_FILE"
       done
then
  echo "Error while reading PHP files." >&2
  rm -f "$TMP_FILE"
  exit 3
fi

# Final newline
printf "\n\n/* End of combined dump */\n" >> "$TMP_FILE"

# Move temp to final (atomic on same FS)
mv -f "$TMP_FILE" "$FULL_OUT_PATH"
chmod 644 "$FULL_OUT_PATH"

echo "Combined PHP dump written to: $FULL_OUT_PATH"
