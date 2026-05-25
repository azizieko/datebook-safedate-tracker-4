#!/usr/bin/env bash

set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-127.0.0.1}
WP_VERSION=${5:-latest}
SKIP_DB_CREATE=${6:-false}

TMPDIR=${TMPDIR:-/tmp}
WP_TESTS_DIR=${WP_TESTS_DIR:-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-$TMPDIR/wordpress}
WP_TESTS_TAG_DIR="$TMPDIR/wordpress-develop"

download() {
  local url="$1"
  local file="$2"

  if command -v curl >/dev/null 2>&1; then
    curl -fsSL "$url" -o "$file"
  elif command -v wget >/dev/null 2>&1; then
    wget -q -O "$file" "$url"
  else
    echo "Error: curl or wget is required." >&2
    exit 1
  fi
}

install_wordpress_core() {
  rm -rf "$WP_CORE_DIR"
  mkdir -p "$WP_CORE_DIR"

  if [ "$WP_VERSION" = "latest" ]; then
    local wp_archive_url="https://wordpress.org/latest.tar.gz"
  else
    local wp_archive_url="https://wordpress.org/wordpress-$WP_VERSION.tar.gz"
  fi

  echo "Downloading WordPress core: $wp_archive_url"
  download "$wp_archive_url" "$TMPDIR/wordpress.tar.gz"

  echo "Extracting WordPress core to $WP_CORE_DIR"
  tar --strip-components=1 -zxf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"

  if [ ! -f "$WP_CORE_DIR/wp-settings.php" ]; then
    echo "Error: WordPress core install failed; wp-settings.php missing." >&2
    ls -la "$WP_CORE_DIR" || true
    exit 1
  fi
}

install_wordpress_tests() {
  rm -rf "$WP_TESTS_DIR"
  mkdir -p "$WP_TESTS_DIR"

  if [ "$WP_VERSION" = "latest" ]; then
    local svn_base="https://develop.svn.wordpress.org/trunk"
  else
    local svn_base="https://develop.svn.wordpress.org/tags/$WP_VERSION"
  fi

  echo "Installing WordPress PHPUnit test suite from $svn_base"
  svn checkout --quiet "$svn_base/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
  svn checkout --quiet "$svn_base/tests/phpunit/data" "$WP_TESTS_DIR/data"

  if [ ! -f "$WP_TESTS_DIR/includes/functions.php" ]; then
    echo "Error: WordPress test includes were not installed correctly." >&2
    find "$WP_TESTS_DIR" -maxdepth 3 -type f | sort || true
    exit 1
  fi

  echo "Preparing wp-tests-config.php"
  local sample_url="$svn_base/wp-tests-config-sample.php"
  if ! download "$sample_url" "$WP_TESTS_DIR/wp-tests-config.php"; then
    echo "Version-specific wp-tests-config-sample.php failed. Trying trunk fallback."
    download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
  fi

  if [ ! -s "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    echo "Error: wp-tests-config.php was not created." >&2
    exit 1
  fi
}

configure_wordpress_tests() {
  echo "Configuring WordPress PHPUnit test suite"

  sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"

  echo "Created $WP_TESTS_DIR/wp-tests-config.php"
}

create_database() {
  if [ "$SKIP_DB_CREATE" = "true" ]; then
    echo "Skipping DB creation because SKIP_DB_CREATE=true"
    return
  fi

  echo "Creating database $DB_NAME"
  mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" || true
}

install_wordpress_core
install_wordpress_tests
configure_wordpress_tests
create_database

echo "WordPress test environment installed successfully."
echo "WP_CORE_DIR=$WP_CORE_DIR"
echo "WP_TESTS_DIR=$WP_TESTS_DIR"
