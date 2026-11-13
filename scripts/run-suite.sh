#!/bin/sh
# Run the bundled Phalcon 3.4 unit suite (the port's acceptance metric)
# against a locally built extension.
#
# Usage: scripts/run-suite.sh <php-binary> <path-to-phalcon.so-dir>
#
# Requires: MySQL on 127.0.0.1:3306 (root, no password) loaded with
# tests/_data/schemas/mysql/phalcon_test.sql, and composer deps installed:
#   COMPOSER=composer-port.json composer install
set -e
HERE=$(cd "$(dirname "$0")/.." && pwd)
PHP_BIN="${1:-$(command -v php)}"
EXT_DIR="${2:-$HERE/build/php7/64bits/modules}"

"$PHP_BIN" \
    -d extension="$EXT_DIR/phalcon.so" \
    -d auto_prepend_file="$HERE/tests-port/_bootstrap.php" \
    -d error_reporting=0 \
    "$HERE/vendor/bin/codecept" run -c "$HERE/codeception.port.yml" unit --no-colors
