<?php
/**
 * Plugin Name:       Zinn® Migrate
 * Plugin URI:        https://zinndigital.com/wordpress-plugins/zinn-migrate
 * Description:       Packages this WordPress site — files and database — into one archive that Zinn Digital® can pull in, for hosts we cannot reach any other way. Install it on the site you are LEAVING, press one button, and paste the link into your Zinn® migration.
 * Version:           1.0.4
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Neil Lock — CEO, Zinn Digital® Ltd
 * Author URI:        https://zinndigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zinn-migrate
 * Domain Path:       /languages
 * Update URI:        https://zinndigital.com
 *
 * @package ZinnMigrate
 *
 * ⚖️ **Owner, 2026-09-09:** *"Do we also not have some migration plugin or something for WP
 * sites?"*. Measured that day: no, and **deliberately not** — every one of the five migration
 * source adapters is agentless, which is a feature rather than an omission. `discovery.py`'s
 * own customer-facing sentence is *"we never install anything on your existing site — no
 * migration plugin, no backup plugin, nothing"*, and that is what lets us migrate a customer
 * off a host that bans exactly those.
 *
 * ⭐⭐ **So this plugin is NOT a second way to do what the adapters already do, and building it
 * as one would be a straight downgrade.** It exists for the population the adapters cannot
 * serve at all, which the discovery probe already identifies by name: a host where **every
 * way in is closed** — no cPanel/Plesk/DirectAdmin port, no SSH, no FTP — or where the
 * customer has a WordPress login and no hosting credentials of any kind. Today those
 * customers reach `outcome: unreachable` or a `routes` list with nothing open, and their only
 * remaining path is the paid managed migration. This is the self-serve one.
 *
 * ⛔⛔ **IT OPENS NO ROUTE AND ACCEPTS NO INBOUND CALL.** The Connector's header argues this
 * at length and it binds harder here, because the artefact is the whole site *and its
 * database*: an endpoint that could be asked for that is the single worst thing to put on a
 * stranger's WordPress. Nothing here registers a REST route, nothing listens, and Zinn® cannot
 * ring this site. The customer presses a button in `wp-admin` and is handed a URL; **they**
 * carry it to Zinn®.
 *
 * ⛔ **It plugs into a path that already exists and is already supported.**
 * `ImportSource.ARCHIVE_URL` is in `SUPPORTED_SOURCES` and its own comment names this
 * artefact — *"a Duplicator package, a cPanel backup, an archive our connector plugin
 * generated"*. So there is no new engine door, no new enum value and no new workflow: this
 * produces the input a shipped, tested path already takes. A plugin that needed a new engine
 * surface to be useful would be the §2.23 shape — a layer with no consumer.
 *
 * ── ⛔ WHAT THIS DELIBERATELY CANNOT DO ────────────────────────────────────────
 *
 * **It cannot restore, and it cannot migrate a site that is already broken.** This is PHP
 * running inside the site it is packaging, so it needs that site to boot. A site that is
 * down needs SSH or the managed migration, and pretending otherwise would be worse than
 * saying nothing, because somebody would plan around it.
 *
 * **It does not dump a database it cannot read.** `mysqldump` is not available on most shared
 * hosting and `shell_exec` is commonly disabled, so the dump is written by WordPress's own
 * `$wpdb` — row by row, bounded, resumable. That is slower than `mysqldump` and it is the
 * only method that works on the hosts this plugin exists for.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const ZINN_MIGRATE_VERSION = '1.0.4';

/** ⛔ `__FILE__`, never a guessed path: a plugin may be symlinked into `plugins/`. */
const ZINN_MIGRATE_FILE = __FILE__;

/**
 * Where a package is written.
 *
 * ⛔⛔ **Under `uploads/`, and the directory name carries 32 hex characters of randomness.**
 * The URL a customer pastes into Zinn® is a **capability**: anyone who holds it can download
 * the site and its database, so its only protection is that it cannot be guessed and cannot
 * be listed. `wp-content/uploads/zinn-migrate/` alone would be a directory a crawler finds.
 *
 * ⭐ Randomness is not the *only* control — `Zinn_Migrate_Package` also writes an
 * `index.php`, an `index.html` and a `.htaccess` that denies listing, because a server with
 * `Options +Indexes` turns an unguessable directory into a guessable one the moment its
 * parent is fetched. Belt and braces, and the braces are the one that works on nginx.
 */
function zinn_migrate_base_dir(): string {
	$uploads = wp_upload_dir();
	return trailingslashit( $uploads['basedir'] ) . 'zinn-migrate';
}

/** The public counterpart of {@see zinn_migrate_base_dir()}. */
function zinn_migrate_base_url(): string {
	$uploads = wp_upload_dir();
	return trailingslashit( $uploads['baseurl'] ) . 'zinn-migrate';
}

/**
 * How long a package is allowed to exist before it is deleted, in seconds.
 *
 * ⚖️ 24 hours, and it is a **security** setting rather than a housekeeping one: the package
 * is a downloadable copy of the customer's entire site sitting inside their own document
 * root, and every hour it survives past the migration is an hour it can be found. A customer
 * who needs longer presses the button again; a customer who forgets is protected.
 *
 * ⛔ Enforced by a scheduled event AND on every admin page load, because WP-Cron only fires
 * when somebody visits the site — and the site this runs on is one the customer is leaving,
 * which is precisely the site nobody visits any more.
 */
const ZINN_MIGRATE_TTL_SECONDS = 86400;

require_once __DIR__ . '/includes/class-zinn-migrate-package.php';
require_once __DIR__ . '/includes/class-zinn-migrate-admin.php';
require_once __DIR__ . '/includes/class-zinn-migrate-promo.php';
require_once __DIR__ . '/includes/class-zinn-migrate-updater.php'; // Generated by wp/bin/build-updater.php.

/**
 * Load the text domain.
 *
 * ⛔ On `init`, not at file scope: WordPress 6.7 warns when a translation is requested before
 * `init`, and the notice lands in the customer's own debug log with our plugin's name on it.
 */
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'zinn-migrate', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

// ⛔ A STRING class name, not `Zinn_Migrate_Promo::class`. The promo class is generated into
// every plugin from one template and is deliberately global; `::class` here would resolve
// against whatever namespace a future bootstrap sits in, and the panel would silently not
// register — which is §2.38 in a customer's own wp-admin, with an absence as its only symptom.
add_action( 'plugins_loaded', array( 'Zinn_Migrate_Promo', 'register' ) );
add_action( 'plugins_loaded', array( Zinn_Migrate_Admin::class, 'boot' ) );
add_action( 'plugins_loaded', array( Zinn_Migrate_Package::class, 'boot' ) );
// ⛔ The updater attaches unconditionally. This plugin holds no pairing and has nothing to be
// "connected" to, and a security release still has to reach an installed copy — nothing on a
// site asks for an update unless something on the site registers this filter (W010, D19745).
add_action(
	'plugins_loaded',
	static function (): void {
		( new Zinn_Migrate_Updater( ZINN_MIGRATE_FILE, ZINN_MIGRATE_VERSION ) )->register();
	}
);

/**
 * ⛔ Deactivation removes the package, the schedule and nothing else.
 *
 * A customer who deactivates this plugin has finished migrating, and leaving a downloadable
 * copy of their site behind because they forgot to press *Delete* is the failure mode this
 * whole file is arranged to avoid.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		Zinn_Migrate_Package::purge_all();
		wp_clear_scheduled_hook( 'zinn_migrate_expire' );
	}
);
