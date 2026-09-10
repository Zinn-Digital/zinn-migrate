<?php
/**
 * The one screen: build a package, watch it, copy the link, delete it.
 *
 * ⛔⛔ **THE WHOLE UI IS ONE PAGE AND THAT IS A DECISION.** A customer reaches this plugin at
 * the worst moment of a migration — they are leaving a host, something has already not worked,
 * and they have been told by three support articles to try three different things. Every extra
 * screen, setting or option here is a place for that to go wrong. There is one button, one
 * progress line, one link and one delete.
 *
 * ⛔ **No settings page, no options, no API key.** This plugin does not talk to Zinn® at all —
 * it writes a file and shows a URL. There is nothing to configure and nothing to pair, which
 * is also why it can be installed on a site that has never heard of us.
 *
 * ⭐ **The stepper runs in the browser** (`admin-post` → redirect → repeat), not in one
 * request. See `Zinn_Migrate_Package`: the hosts this plugin exists for kill a request at 30
 * seconds, and a progress bar that depends on surviving longer than the host allows is a
 * progress bar that stops at 40% on exactly the sites that need it.
 *
 * @package ZinnMigrate
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** The admin screen. */
final class Zinn_Migrate_Admin {

	private const SLUG  = 'zinn-migrate';
	private const NONCE = 'zinn_migrate_action';

	/**
	 * Wire the screen and the three write handlers.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_zinn_migrate_start', array( self::class, 'handle_start' ) );
		add_action( 'admin_post_zinn_migrate_step', array( self::class, 'handle_step' ) );
		add_action( 'admin_post_zinn_migrate_delete', array( self::class, 'handle_delete' ) );
		// ⛔ WP-Cron fires only when somebody visits, and this is the site they have left.
		add_action( 'admin_init', array( Zinn_Migrate_Package::class, 'purge_expired' ) );
	}

	/**
	 * Add the screen under Tools.
	 *
	 * ⭐ Tools, not a top-level menu: this is a one-off job a customer does once and then
	 * removes the plugin. A top-level item for it would be advertising.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_management_page(
			__( 'Move this site to Zinn®', 'zinn-migrate' ),
			__( 'Move to Zinn®', 'zinn-migrate' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/** ⛔ Capability AND nonce on every write. Neither alone is a control. */
	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'zinn-migrate' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
	}

	/**
	 * Redirect back to the screen, carrying an optional message.
	 *
	 * @param array $args Query arguments to add.
	 * @return void
	 */
	private static function back( array $args = array() ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Begin a package. ⛔ Guarded on capability AND nonce; see {@see self::guard()}.
	 *
	 * @return void
	 */
	public static function handle_start(): void {
		self::guard();
		$state = Zinn_Migrate_Package::start();
		self::back( isset( $state['error'] ) ? array( 'zm_error' => rawurlencode( $state['error'] ) ) : array() );
	}

	/**
	 * Do one bounded step of the package.
	 *
	 * @return void
	 */
	public static function handle_step(): void {
		self::guard();
		$state = Zinn_Migrate_Package::step();
		self::back( isset( $state['error'] ) ? array( 'zm_error' => rawurlencode( $state['error'] ) ) : array() );
	}

	/**
	 * Delete the package and forget the job.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		self::guard();
		Zinn_Migrate_Package::purge_all();
		self::back( array( 'zm_deleted' => '1' ) );
	}

	/**
	 * One nonce-protected POST button.
	 *
	 * @param string $action    The `admin-post` action name.
	 * @param string $label     Already-translated label.
	 * @param string $css_class The button's CSS class.
	 * @return void
	 */
	private static function button( string $action, string $label, string $css_class = 'button' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<button type="submit" class="<?php echo esc_attr( $css_class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state    = Zinn_Migrate_Package::state();
		$headroom = Zinn_Migrate_Package::headroom();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display-only flag.
		$error = isset( $_GET['zm_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['zm_error'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Move this site to Zinn®', 'zinn-migrate' ); ?></h1>

			<p>
				<?php
				esc_html_e(
					'This packages everything on this site — its files and its database — into one archive, and gives you a private link to it. Paste that link into your migration on Zinn® and we will pull the site across. Nothing here is sent anywhere until you choose to share the link.',
					'zinn-migrate'
				);
				?>
			</p>

			<?php
			// ⛔⛤ Said BEFORE the button, not after it. A customer who has already pressed
				// Build is not reading a note about which method to prefer.
			?>
			<div class="notice notice-info inline">
				<p>
					<?php
					esc_html_e(
						'You may not need this. If your old host gives you FTP, SSH, cPanel, Plesk or DirectAdmin, Zinn® can fetch the site directly with those details and you can skip this plugin entirely — it is faster and there is nothing left behind afterwards. Use this when none of those are available to you.',
						'zinn-migrate'
					);
					?>
				</p>
			</div>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Room on this server', 'zinn-migrate' ); ?></h2>
			<p>
				<?php
				// ⛔⛔ Three sentences, never two. "You have room", "you do not", and "this host
				// will not tell us" are different facts, and rendering the third as either of
				// the first two is exactly the reassuring-ambiguity failure (§2.44). A package
				// written onto a full disk is a white screen on a LIVE site, not a failed export.
				$needed = size_format( (int) $headroom['needed'], 1 );
				if ( null === $headroom['free'] ) {
					printf(
						/* translators: %s: an approximate size, e.g. "1.4 GB". */
						esc_html__( 'This server will not tell us how much disk space is free, so we cannot check for you. The package needs roughly %s. If you are not sure there is that much room, ask your host before you build it — a server that runs out of space mid-way takes the site down.', 'zinn-migrate' ),
						esc_html( $needed )
					);
				} else {
					printf(
						/* translators: 1: space required, 2: space free. */
						esc_html__( 'The package needs roughly %1$s and this server has %2$s free.', 'zinn-migrate' ),
						esc_html( $needed ),
						esc_html( size_format( (int) $headroom['free'], 1 ) )
					);
				}
				?>
			</p>

			<?php if ( empty( $state['token'] ) ) : ?>
				<p><?php self::button( 'zinn_migrate_start', __( 'Build the package', 'zinn-migrate' ), 'button button-primary' ); ?></p>
			<?php elseif ( empty( $state['done'] ) ) : ?>
				<h2><?php esc_html_e( 'Building…', 'zinn-migrate' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: what is being packaged now, 2: files packaged so far, 3: size so far. */
						esc_html__( 'Now packaging: %1$s. %2$s files so far, %3$s written.', 'zinn-migrate' ),
						esc_html( 'files' === ( $state['stage'] ?? '' ) ? __( 'your files', 'zinn-migrate' ) : __( 'your database', 'zinn-migrate' ) ),
						esc_html( number_format_i18n( (int) ( $state['files_done'] ?? 0 ) ) ),
						esc_html( size_format( (int) ( $state['bytes'] ?? 0 ), 1 ) )
					);
					?>
				</p>
				<p>
					<?php
					// ⭐ A button rather than a spinner, and it is not laziness: every step is
					// one bounded request, so a customer on a host that kills long requests can
					// always take the next one. The auto-submit below does it for them when
					// JavaScript is available; without it the migration still finishes.
					self::button( 'zinn_migrate_step', __( 'Continue', 'zinn-migrate' ), 'button button-primary' );
					self::button( 'zinn_migrate_delete', __( 'Cancel and delete', 'zinn-migrate' ) );
					?>
				</p>
				<script>
					// ⛔ No dependency, no bundle, no enqueue: three lines that press Continue.
					setTimeout( function () {
						var form = document.querySelector( 'form input[value="zinn_migrate_step"]' );
						if ( form && form.form ) { form.form.submit(); }
					}, 800 );
				</script>
			<?php else : ?>
				<h2><?php esc_html_e( 'Your package is ready', 'zinn-migrate' ); ?></h2>
				<p><?php esc_html_e( 'Copy this link and paste it into your migration on Zinn®:', 'zinn-migrate' ); ?></p>
				<p>
					<input
						type="text"
						readonly
						class="large-text code"
						onfocus="this.select()"
						value="<?php echo esc_url( (string) ( $state['url'] ?? '' ) ); ?>" />
				</p>
				<p>
					<?php
					printf(
						/* translators: %s: the package size. */
						esc_html__( 'Size: %s.', 'zinn-migrate' ),
						esc_html( size_format( (int) ( $state['bytes'] ?? 0 ), 1 ) )
					);
					?>
				</p>
				<?php
				// ⛔⛔ The security sentence, and it is not boilerplate: this link IS the
					// site. Anyone who has it can download the files and the database.
				?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						esc_html_e(
							'Treat this link like a password. Anyone who has it can download your whole site, including your database. We delete the package automatically 24 hours after it was built — delete it yourself as soon as your migration has finished.',
							'zinn-migrate'
						);
						?>
					</p>
				</div>
				<p>
					<?php
					self::button( 'zinn_migrate_delete', __( 'Delete the package now', 'zinn-migrate' ), 'button button-primary' );
					self::button( 'zinn_migrate_start', __( 'Build it again', 'zinn-migrate' ) );
					?>
				</p>
			<?php endif; ?>

			<?php
			// ⚖️ Owner, 2026-09-01: every plugin promotes our hosting, the marketplace and Zinn®
			// Hub® inside the customer's own dashboard. Rendered at the FOOT of the plugin's own
			// screen and nowhere else — a bare `admin_footer` with no screen test is an advert on
			// every page of wp-admin, which is the clearest WordPress.org rejection there is.
			Zinn_Migrate_Promo::render_panel();
			?>
		</div>
		<?php
	}
}
