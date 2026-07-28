#!/usr/bin/env bash
#
# Install the WordPress core test suite so PHPUnit can run against real
# WordPress rather than a mock of it.
#
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Everything is also readable from the environment, which is how the Docker
# runner and CI both drive it:
#
#   WP_VERSION       WordPress version to install          (default 7.0.2)
#   WP_CORE_DIR      where WordPress itself is unpacked    (default /tmp/wordpress)
#   WP_TESTS_DIR     where the test library is unpacked    (default /tmp/wordpress-tests-lib)
#   WP_TESTS_DB_*    NAME / USER / PASS / HOST
#
# Both directories are left alone if they already look populated, so repeated
# runs are cheap. Pass --force to reinstall from scratch.
#
# This fetches over HTTPS with curl and tar rather than Subversion. The
# canonical upstream script needs an svn client, which is one more thing for a
# container and a CI image to carry for no benefit here.

set -euo pipefail

FORCE=0
ARGS=()
for arg in "$@"; do
	if [ "$arg" = "--force" ]; then FORCE=1; else ARGS+=("$arg"); fi
done
set -- ${ARGS[@]+"${ARGS[@]}"}

DB_NAME=${1-${WP_TESTS_DB_NAME-wordpress_test}}
DB_USER=${2-${WP_TESTS_DB_USER-root}}
DB_PASS=${3-${WP_TESTS_DB_PASS-root}}
DB_HOST=${4-${WP_TESTS_DB_HOST-localhost}}
WP_VERSION=${5-${WP_VERSION-7.0.2}}

WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}
WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR%/}
WP_TESTS_DIR=${WP_TESTS_DIR%/}

STAGING=""
cleanup() { [ -n "$STAGING" ] && rm -rf "$STAGING"; }
trap cleanup EXIT

say() { printf '==> %s\n' "$1"; }
download() { curl -fsSL --retry 3 --retry-delay 2 "$1"; }

install_core() {
	if [ "$FORCE" -eq 0 ] && [ -f "$WP_CORE_DIR/wp-settings.php" ]; then
		say "WordPress already at $WP_CORE_DIR"
		return
	fi

	say "Downloading WordPress $WP_VERSION"
	rm -rf "$WP_CORE_DIR"
	mkdir -p "$WP_CORE_DIR"
	download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" \
		| tar -xz -C "$WP_CORE_DIR" --strip-components=1

	# The suite exercises media handling, which needs a real uploads directory.
	mkdir -p "$WP_CORE_DIR/wp-content/uploads"
}

install_test_suite() {
	if [ "$FORCE" -eq 0 ] && [ -f "$WP_TESTS_DIR/includes/bootstrap.php" ]; then
		say "Test library already at $WP_TESTS_DIR"
		return
	fi

	say "Downloading the WordPress $WP_VERSION test library"
	rm -rf "$WP_TESTS_DIR"
	mkdir -p "$WP_TESTS_DIR"

	# The develop repository holds the test library. Only three paths from it
	# are wanted; its own src/ needs a JS build we have no use for, since core
	# comes from the released tarball above.
	STAGING=$(mktemp -d)
	download "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" \
		| tar -xz -C "$STAGING" --strip-components=1 --wildcards \
			'*/tests/phpunit/includes' \
			'*/tests/phpunit/data' \
			'*/wp-tests-config-sample.php'

	mv "$STAGING/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
	mv "$STAGING/tests/phpunit/data" "$WP_TESTS_DIR/data"
	mv "$STAGING/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
}

# Generated on every run from the pristine sample, even when the library was
# cached: the credentials and paths belong to the caller, and they differ
# between the Docker runner and CI. Generating rather than patching in place is
# what makes a second run behave like the first.
write_config() {
	if [ ! -f "$WP_TESTS_DIR/wp-tests-config-sample.php" ]; then
		say "Fetching the sample config"
		download "https://raw.githubusercontent.com/WordPress/wordpress-develop/${WP_VERSION}/wp-tests-config-sample.php" \
			> "$WP_TESTS_DIR/wp-tests-config-sample.php"
	fi

	say "Writing $WP_TESTS_DIR/wp-tests-config.php"
	cp "$WP_TESTS_DIR/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"

	# php rather than sed because one replacement is a PHP expression and the
	# rest are values that may contain slashes.
	WP_TESTS_ABSPATH="$WP_CORE_DIR/" \
	WP_TESTS_CONFIG="$WP_TESTS_DIR/wp-tests-config.php" \
	WP_TESTS_DB_NAME="$DB_NAME" \
	WP_TESTS_DB_USER="$DB_USER" \
	WP_TESTS_DB_PASS="$DB_PASS" \
	WP_TESTS_DB_HOST="$DB_HOST" \
	php -r '
		$file   = getenv( "WP_TESTS_CONFIG" );
		$config = file_get_contents( $file );

		$replacements = [
			"dirname( __FILE__ ) . \x27/src/\x27" => var_export( getenv( "WP_TESTS_ABSPATH" ), true ),
			"\x27youremptytestdbnamehere\x27"      => var_export( getenv( "WP_TESTS_DB_NAME" ), true ),
			"\x27yourusernamehere\x27"             => var_export( getenv( "WP_TESTS_DB_USER" ), true ),
			"\x27yourpasswordhere\x27"             => var_export( getenv( "WP_TESTS_DB_PASS" ), true ),
			"\x27localhost\x27"                    => var_export( getenv( "WP_TESTS_DB_HOST" ), true ),
		];

		foreach ( $replacements as $from => $to ) {
			if ( ! str_contains( $config, $from ) ) {
				fwrite( STDERR, "Could not find {$from} in the sample config.\n" );
				exit( 1 );
			}
			$config = str_replace( $from, $to, $config );
		}

		file_put_contents( $file, $config );
	'
}

# Uses PHP's mysqli rather than a mysql client binary. WordPress requires
# mysqli anyway, so this needs nothing installed that the suite does not
# already depend on — and it sidesteps the MySQL and MariaDB clients disagreeing
# about how to spell "do not require TLS".
create_db() {
	say "Creating database $DB_NAME"

	WP_TESTS_DB_NAME="$DB_NAME" \
	WP_TESTS_DB_USER="$DB_USER" \
	WP_TESTS_DB_PASS="$DB_PASS" \
	WP_TESTS_DB_HOST="$DB_HOST" \
	php -r '
		// WordPress spells a host as "host", "host:port" or "host:/socket/path".
		$raw    = (string) getenv( "WP_TESTS_DB_HOST" );
		$host   = $raw;
		$port   = 3306;
		$socket = null;

		if ( str_contains( $raw, ":" ) ) {
			[ $host, $tail ] = explode( ":", $raw, 2 );
			if ( str_starts_with( $tail, "/" ) ) {
				$socket = $tail;
			} else {
				$port = (int) $tail;
			}
		}

		mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );

		try {
			$db = new mysqli( $host, getenv( "WP_TESTS_DB_USER" ), getenv( "WP_TESTS_DB_PASS" ), "", $port, $socket );
		} catch ( Throwable $e ) {
			fwrite( STDERR, "Could not connect to {$raw}: {$e->getMessage()}\n" );
			exit( 1 );
		}

		$name = $db->real_escape_string( (string) getenv( "WP_TESTS_DB_NAME" ) );

		// Dropped first so a half-installed run leaves nothing behind. The
		// suite owns this database exclusively — it is never the site\x27s own.
		$db->query( "DROP DATABASE IF EXISTS `{$name}`" );
		$db->query( "CREATE DATABASE `{$name}`" );
	'
}

install_core
install_test_suite
write_config
create_db

say "Ready. WP_TESTS_DIR=$WP_TESTS_DIR WP_CORE_DIR=$WP_CORE_DIR"
