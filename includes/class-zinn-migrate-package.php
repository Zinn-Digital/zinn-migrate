<?php
/**
 * Building the package: the site's files and its database, in one archive, in bounded steps.
 *
 * ⛔⛔ **EVERY DESIGN DECISION HERE IS FORCED BY THE POPULATION THIS PLUGIN EXISTS FOR.** The
 * customers who need it are on hosts where `exec` is disabled, `mysqldump` is absent,
 * `max_execution_time` is 30 seconds and `memory_limit` is 128M — because a host that gave
 * them SSH or a working control-panel API would have been migrated by an adapter and they
 * would never have installed this. So:
 *
 * * **the dump is written by `$wpdb`**, not by `mysqldump`. Slower, and the only method that
 *   runs at all on the hosts in question;
 * * **every step is bounded and resumable**, driven from the browser, so nothing depends on
 *   a request surviving longer than the host allows. State lives in an option, not in memory;
 * * **rows are read in windows and written straight out**, never accumulated. A 400 MB
 *   `wp_posts` read into an array is a fatal on a 128M host, and a fatal mid-package leaves
 *   a half-written archive that looks finished.
 *
 * ⛔⛔ **DISK IS MEASURED BEFORE ANYTHING IS WRITTEN, AND THE REFUSAL IS THE POINT** (W41-P's
 * finding on the Connector, one plugin over, and it applies harder here). A package is
 * roughly the size of the site; writing one onto a full disk on a **live WordPress site is a
 * white screen**, not a failed export. `require_headroom()` declines with a sentence rather
 * than discovering it at 90%.
 *
 * ⛔ `disk_free_space()` is itself commonly disabled on shared hosting. An unreadable answer
 * is **not** treated as room (§2.44 — the ambiguous value must not be the reassuring one); it
 * is reported to the customer as *"we cannot tell how much room you have"* alongside an
 * estimate of what the package needs, so the decision is theirs and is informed.
 *
 * @package ZinnMigrate
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The archiver.
 *
 * ⛔ No REST route, no AJAX for logged-out users, no public entry point. Every method here is
 * reached from `wp-admin` by a user with `manage_options` and a valid nonce, or from the
 * expiry cron.
 */
final class Zinn_Migrate_Package {

	/** The option holding the in-progress job. One site, one package, one job. */
	private const STATE = 'zinn_migrate_state';

	/** Files per step. Small enough for a 30-second host, large enough to finish a site. */
	private const FILES_PER_STEP = 400;

	/** Database rows per window. Bounded by MEMORY, not by time — see the class docblock. */
	private const ROWS_PER_WINDOW = 500;

	/**
	 * Directories never packaged, relative to the WordPress root.
	 *
	 * ⛔⛔ **`uploads/zinn-migrate` is the load-bearing entry and its absence is a recursion.**
	 * The package is written inside `uploads/`, so a naive walk packages the archive it is
	 * writing — which grows as it is read, and the export never terminates. It fills the
	 * customer's disk instead, which is the white screen the class docblock is about.
	 *
	 * ⛔ Caches are excluded because they are worthless on the far side and are frequently
	 * the largest thing on a WordPress site. `.git` is excluded because it is a copy of the
	 * whole history of the files we are already copying.
	 */
	private const SKIP = array(
		'wp-content/uploads/zinn-migrate',
		'wp-content/cache',
		'wp-content/upgrade',
		'wp-content/backup',
		'wp-content/ai1wm-backups',
		'wp-content/updraft',
		'.git',
		'node_modules',
	);

	/**
	 * Attach the expiry sweep and make sure it is scheduled.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'zinn_migrate_expire', array( self::class, 'purge_expired' ) );
		if ( ! wp_next_scheduled( 'zinn_migrate_expire' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'zinn_migrate_expire' );
		}
	}

	/** The job as it stands, or an empty array when there is none. */
	public static function state(): array {
		$state = get_option( self::STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * How much room a package will need, in bytes, and how much there is.
	 *
	 * ⛔ Returns `null` for `free` when the host will not say. `0` would read as *"no room"*
	 * and `PHP_INT_MAX` as *"plenty"*; both are claims this function cannot make, and the
	 * caller renders the difference (§2.44).
	 */
	public static function headroom(): array {
		$needed = self::estimate_bytes();

		/*
		 * ⛔ The `@` is load-bearing. `disk_free_space` is commonly in `disable_functions` on
		 * exactly the shared hosts this plugin exists for, where it emits a warning AND
		 * returns false. The `false` is what is handled below — reported to the customer as
		 * "we cannot tell", never as room (§2.44) — so the warning adds nothing except our
		 * plugin's name in their own debug log for a condition already covered.
		 */
		$free = @disk_free_space( WP_CONTENT_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		return array(
			'needed' => $needed,
			'free'   => ( false === $free || null === $free ) ? null : (int) $free,
		);
	}

	/**
	 * A rough size for the package: the files we would walk, plus the database's own figure.
	 *
	 * ⭐ Deliberately an over-estimate rather than an exact one. The compressed archive is
	 * smaller than this, and a customer told they need more room than they do buys a moment's
	 * inconvenience — while a customer told they need less than they do gets a full disk.
	 */
	private static function estimate_bytes(): int {
		global $wpdb;
		$bytes    = 0;
		$root     = self::root();
		$iterator = self::iterator( $root );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$bytes += (int) $file->getSize();
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A size estimate for the export about to be written must read the database as it is NOW; there is no core API for table sizes and a cached answer is the wrong one.
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		foreach ( (array) $tables as $table ) {
			$bytes += (int) ( $table['Data_length'] ?? 0 ) + (int) ( $table['Index_length'] ?? 0 );
		}
		return $bytes;
	}

	/** The WordPress root, with a trailing slash. */
	private static function root(): string {
		return trailingslashit( ABSPATH );
	}

	/**
	 * A recursive iterator over the site, skipping {@see self::SKIP} and unreadable paths.
	 *
	 * ⛔ `SKIP_DOTS` plus an explicit unreadable-directory filter: a shared host frequently
	 * has a directory the PHP user cannot enter, and an unhandled `UnexpectedValueException`
	 * from the iterator kills the export with a fatal rather than skipping one folder.
	 *
	 * @param string $root Absolute path to walk, with a trailing slash.
	 * @return RecursiveIteratorIterator
	 */
	private static function iterator( string $root ): RecursiveIteratorIterator {
		$directory = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
		$filtered  = new RecursiveCallbackFilterIterator(
			$directory,
			static function ( SplFileInfo $current ) use ( $root ): bool {
				$relative = str_replace( '\\', '/', substr( $current->getPathname(), strlen( $root ) ) );
				foreach ( self::SKIP as $skip ) {
					if ( $relative === $skip || str_starts_with( $relative, $skip . '/' ) ) {
						return false;
					}
				}
				return $current->isFile() || $current->isReadable();
			}
		);
		return new RecursiveIteratorIterator( $filtered, RecursiveIteratorIterator::SELF_FIRST );
	}

	/**
	 * Start a package. Deletes any previous one first.
	 *
	 * ⛔ The previous package is deleted before the new one is created, not after: two
	 * downloadable copies of a customer's site on their own server is twice the exposure for
	 * no benefit, and "after" is the branch that does not run when the new export fails.
	 */
	public static function start(): array {
		self::purge_all();
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array(
				'error' => __(
					'This server does not have PHP’s ZipArchive extension, so we cannot build a package here. Ask your host to enable it, or use the migration form on Zinn® with your FTP or SSH details instead.',
					'zinn-migrate'
				),
			);
		}
		$token = bin2hex( random_bytes( 16 ) );
		$dir   = zinn_migrate_base_dir() . '/' . $token;
		if ( ! wp_mkdir_p( $dir ) ) {
			return array(
				'error' => __(
					'We could not create a folder inside your uploads directory, so there is nowhere to write the package. Check that wp-content/uploads is writable.',
					'zinn-migrate'
				),
			);
		}
		self::seal( zinn_migrate_base_dir() );
		self::seal( $dir );

		$state = array(
			'token'      => $token,
			'stage'      => 'files',
			'started_at' => time(),
			'files_done' => 0,
			'table'      => 0,
			'offset'     => 0,
			'bytes'      => 0,
		);
		update_option( self::STATE, $state, false );
		return $state;
	}

	/**
	 * Make a directory unlistable and its contents unservable-by-accident.
	 *
	 * ⛔ Three files, because one is not enough on the estate this runs on. `index.php` and
	 * `index.html` stop Apache and nginx serving a listing when `Options +Indexes` is on;
	 * `.htaccess` denies listing explicitly on Apache. On nginx with `autoindex on` the index
	 * files are what works, which is why both are written rather than the `.htaccess` alone.
	 *
	 * @param string $dir Absolute path to the directory to seal.
	 * @return void
	 */
	private static function seal( string $dir ): void {
		foreach ( array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		) as $name => $body ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * The archive path for a job.
	 *
	 * @param string $token The job token.
	 * @return string
	 */
	private static function archive_path( string $token ): string {
		return zinn_migrate_base_dir() . '/' . $token . '/zinn-package.zip';
	}

	/**
	 * The URL a customer pastes into Zinn®.
	 *
	 * @param string $token The job token.
	 * @return string
	 */
	public static function archive_url( string $token ): string {
		return zinn_migrate_base_url() . '/' . $token . '/zinn-package.zip';
	}

	/**
	 * Do one bounded step of work. Returns the state, with `done` true when finished.
	 *
	 * ⛔⛤ **The archive is CLOSED at the end of every step and reopened at the start of the
	 * next.** `ZipArchive` buffers, and a package left open across a request that the host
	 * kills at `max_execution_time` is a truncated zip — which opens, lists some files, and
	 * looks like a smaller site. That is the worst outcome available here: a migration that
	 * completes and silently omits half the media library.
	 */
	public static function step(): array {
		$state = self::state();
		if ( empty( $state['token'] ) ) {
			return array( 'error' => __( 'There is no package being built. Start one first.', 'zinn-migrate' ) );
		}
		$zip  = new ZipArchive();
		$path = self::archive_path( $state['token'] );
		$flag = file_exists( $path ) ? 0 : ZipArchive::CREATE;
		if ( true !== $zip->open( $path, $flag | ZipArchive::CREATE ) ) {
			return array( 'error' => __( 'We could not open the package file for writing.', 'zinn-migrate' ) );
		}
		try {
			$state = 'files' === $state['stage']
				? self::step_files( $zip, $state )
				: self::step_database( $zip, $state );
		} finally {
			$zip->close();
		}
		$state['bytes'] = file_exists( $path ) ? (int) filesize( $path ) : 0;
		update_option( self::STATE, $state, false );
		return $state;
	}

	/**
	 * One window of files.
	 *
	 * @param ZipArchive $zip   The open archive.
	 * @param array      $state The job state.
	 * @return array
	 */
	private static function step_files( ZipArchive $zip, array $state ): array {
		$root    = self::root();
		$skipped = 0;
		$added   = 0;
		foreach ( self::iterator( $root ) as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			++$skipped;
			if ( $skipped <= (int) $state['files_done'] ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
			$zip->addFile( $file->getPathname(), 'site/' . $relative );
			++$added;
			if ( $added >= self::FILES_PER_STEP ) {
				$state['files_done'] = (int) $state['files_done'] + $added;
				return $state;
			}
		}
		$state['files_done'] = (int) $state['files_done'] + $added;
		$state['stage']      = 'database';
		return $state;
	}

	/**
	 * One window of database rows, appended to `database.sql` inside the archive.
	 *
	 * ⛔ Written to a temporary file on disk and re-added, rather than held as a string:
	 * `ZipArchive::addFromString` needs the whole member in memory, and the whole member is
	 * the customer's database.
	 *
	 * @param ZipArchive $zip   The open archive.
	 * @param array      $state The job state.
	 * @return array
	 */
	private static function step_database( ZipArchive $zip, array $state ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The exporter must see every table that exists at the moment it runs; there is no core API for listing tables and a cached list would drop one created since.
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		$tables = array_values(
			array_filter( (array) $tables, static fn( $t ) => str_starts_with( (string) $t, $wpdb->prefix ) )
		);
		$sql    = zinn_migrate_base_dir() . '/' . $state['token'] . '/database.sql';
		if ( 0 === (int) $state['table'] && 0 === (int) $state['offset'] ) {
			file_put_contents( $sql, "-- Zinn® Migrate export\nSET NAMES utf8mb4;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$index = (int) $state['table'];
		if ( $index >= count( $tables ) ) {
			$zip->addFile( $sql, 'database.sql' );
			$state['stage'] = 'done';
			$state['done']  = true;
			$state['url']   = self::archive_url( $state['token'] );
			return $state;
		}
		$table  = (string) $tables[ $index ];
		$offset = (int) $state['offset'];
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- `WP_Filesystem`'s API is
		// whole-file, and the whole file here is the customer's database. Appending row
		// windows to a handle is the entire reason this export survives a 128M host.
		$handle = fopen( $sql, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			$state['error'] = __( 'We could not write the database export.', 'zinn-migrate' );
			return $state;
		}
		try {
			if ( 0 === $offset ) {
				$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore WordPress.DB
				fwrite( $handle, "\nDROP TABLE IF EXISTS `{$table}`;\n" . ( $create[1] ?? '' ) . ";\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- `WP_Filesystem`'s API is whole-file, and the whole file here is the customer's database. Appending row windows to a handle is the entire reason this export survives a 128M host.
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Streaming the customer's own rows into their export; caching them would copy the database into the object cache.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, self::ROWS_PER_WINDOW, $offset ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$values = array();
				foreach ( $row as $value ) {
					$values[] = self::sql_literal( $value );
				}
				fwrite( $handle, "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above: whole-file APIs cannot stream a database out in bounded windows.
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( count( (array) $rows ) < self::ROWS_PER_WINDOW ) {
			$state['table']  = $index + 1;
			$state['offset'] = 0;
		} else {
			$state['offset'] = $offset + self::ROWS_PER_WINDOW;
		}
		return $state;
	}

	/**
	 * One column value as a SQL literal for the export file.
	 *
	 * ⛔⛔ `esc_sql()` IS NOT ENOUGH ON ITS OWN, AND IT CORRUPTED EVERY `%` IN EVERY EXPORT
	 * (W43-43, D25870). Since WordPress 4.8.3 `esc_sql()` replaces each `%` with a
	 * per-request placeholder hash, so that the result can be passed to `$wpdb->prepare()`
	 * safely; `prepare()` puts the `%` back. This export never calls `prepare()` on the
	 * value — it writes it to a file — so the hash was written instead. Measured on
	 * production: a WordPress site restored from a package had `/%year%/%monthnum%/`
	 * permalinks reading `/540843d1…year540843d1…/`, every post link was broken, and any post
	 * saying "50% off" would have said "50540843d1… off".
	 *
	 * @param mixed $value A column value as `$wpdb` returned it.
	 * @return string
	 */
	public static function sql_literal( $value ): string {
		global $wpdb;
		if ( null === $value ) {
			return 'NULL';
		}
		return "'" . $wpdb->remove_placeholder_escape( esc_sql( (string) $value ) ) . "'";
	}

	/** Delete every package and forget the job. */
	public static function purge_all(): void {
		$base = zinn_migrate_base_dir();
		if ( is_dir( $base ) ) {
			self::rmdir_recursive( $base );
		}
		delete_option( self::STATE );
	}

	/**
	 * Delete a package older than the TTL.
	 *
	 * ⛔ Also called on every admin page load, because WP-Cron fires only when somebody
	 * visits — and the site this runs on is the one the customer has just left.
	 */
	public static function purge_expired(): void {
		$state = self::state();
		if ( empty( $state['started_at'] ) ) {
			return;
		}
		if ( time() - (int) $state['started_at'] >= ZINN_MIGRATE_TTL_SECONDS ) {
			self::purge_all();
		}
	}

	/**
	 * Remove a directory and everything under it.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private static function rmdir_recursive( string $dir ): void {
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- deleting the package is a SECURITY operation: it is a downloadable copy of the whole site sitting in the customer's own document root. `WP_Filesystem` needs credentials that are absent on an FTP-mode install, and failing to delete is far worse than not using its wrapper. ⭐ Files go through core's `wp_delete_file()`; there is no core wrapper for removing a directory.
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a directory another process is holding must not fatal the purge.
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- same.
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
