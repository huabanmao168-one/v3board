#!/bin/bash
# Apply PHP 8.6 compatibility patches to vendor dependencies after composer install/update

set -e

LINFO_FILE="vendor/linfo/linfo/src/Linfo/Parsers/CallExt.php"

if [ -f "$LINFO_FILE" ]; then
    if grep -q '`\$command`' "$LINFO_FILE" 2>/dev/null; then
        echo "Patching linfo library for PHP 8.6 (backtick → shell_exec)..."
        sed -i.bak 's/\$result = `\$command`;/$result = shell_exec($command);/' "$LINFO_FILE"
        rm -f "$LINFO_FILE.bak"
        echo "✓ linfo patched"
    else
        echo "✓ linfo already patched"
    fi
else
    echo "⚠ linfo not installed, skipping"
fi
