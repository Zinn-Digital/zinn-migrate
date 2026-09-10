<?php
/**
 * Uninstall: remove the package and the schedule, and nothing else.
 *
 * ⛔⛔ **THE PACKAGE MUST GO, AND THIS IS THE LAST CHANCE TO REMOVE IT.** It is a downloadable
 * copy of the customer's whole site — files and database — sitting inside their own document
 * root behind an unguessable URL. A plugin that left one behind on uninstall would leave a
 * permanent, silent exposure on a site we no longer have any relationship with, and nothing
 * would ever report it.
 *
 * ⛔ There is nothing else to clean up, and that is by design: this plugin stores no settings,
 * no credentials and no pairing. The single option below is the in-progress job.
 *
 * ⭐ The work sits inside a prefixed FUNCTION rather than at file scope, which is what every
 * sibling plugin does and what the WordPress standard requires: at file scope each `$var` is a
 * global, and a global named `$items` on a site running four plugins is a collision waiting for
 * somebody else's uninstall to happen in the same request.
 *
 * @package ZinnMigrate
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete every trace this plugin leaves on a site.
 *
 * ⛔ `WP_Filesystem` is not used and that is deliberate rather than an oversight: its API is
 * whole-file and it requires credentials that are not available during uninstall on an FTP-mode
 * install, so reaching for it here is how an uninstall silently leaves the archive behind. The
 * same argument the Connector's backup path makes for its own direct calls.
 *
 * @return void
 */
function zinn_migrate_uninstall(): void {
	wp_clear_scheduled_hook( 'zinn_migrate_expire' );
	delete_option( 'zinn_migrate_state' );

	$uploads = wp_upload_dir();
	$base    = trailingslashit( $uploads['basedir'] ) . 'zinn-migrate';
	if ( ! is_dir( $base ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- see the docblock: `WP_Filesystem` needs credentials an uninstall may not have, and leaving the archive behind is the worse outcome by a distance. ⭐ Files go through core's `wp_delete_file()`; there is no core wrapper for removing a directory.
	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a directory another process is holding must not fatal the uninstall.
		} else {
			wp_delete_file( $item->getPathname() );
		}
	}
	@rmdir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- same.
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

zinn_migrate_uninstall();
