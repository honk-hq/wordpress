<?php
/**
 * The settings screen (Settings → Honk), the test button and the delivery log.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens. Standard WordPress admin markup and classes only.
 */
final class Honk_Admin {

	const PAGE = 'honk';

	const CAPABILITY = 'manage_options';

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
		add_action( 'wp_ajax_honk_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'admin_post_honk_clear_log', array( __CLASS__, 'clear_log' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice_not_configured' ) );
		add_action( 'update_option_' . Honk_Settings::OPTION, array( __CLASS__, 'after_save' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( HONK_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'woocommerce_tab' ), 60 );
		add_action( 'woocommerce_settings_honk', array( __CLASS__, 'woocommerce_tab_content' ) );
	}

	/**
	 * URL of the settings screen.
	 *
	 * @param string $tab Tab: settings or log.
	 * @return string
	 */
	public static function url( $tab = 'settings' ) {
		$args = array( 'page' => self::PAGE );
		if ( 'settings' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * Settings → Honk.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page( __( 'Honk', 'honk' ), __( 'Honk', 'honk' ), self::CAPABILITY, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * The settings screen's script and styles.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function assets( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'honk-admin', plugins_url( 'assets/admin.css', HONK_FILE ), array(), HONK_VERSION );
		wp_enqueue_script( 'honk-admin', plugins_url( 'assets/admin.js', HONK_FILE ), array(), HONK_VERSION, true );
		wp_localize_script(
			'honk-admin',
			'honkAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'honk_test' ),
				'sending' => __( 'Sending…', 'honk' ),
				'failed'  => __( 'The test couldn’t run. Reload the page and try again.', 'honk' ),
			)
		);
	}

	/**
	 * Plugins screen: a Settings link.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'honk' ) . '</a>' );
		return $links;
	}

	/**
	 * Plugins screen: a reminder to add the key.
	 *
	 * @return void
	 */
	public static function notice_not_configured() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || Honk_Settings::is_configured() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Honk is almost set up. Add your API key to start getting notifications.', 'honk' ),
			esc_url( self::url() ),
			esc_html__( 'Open Honk settings', 'honk' )
		);
	}

	/**
	 * After the settings are saved: redact the log when personal data was switched off, refresh
	 * the server's capabilities, update schedules.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public static function after_save( $old_value, $value ) {
		Honk_Settings::flush();
		if ( is_array( $old_value ) && is_array( $value ) && ! empty( $old_value['include_pii'] ) && empty( $value['include_pii'] ) ) {
			Honk_Log::redact();
		}
		if ( Honk_Settings::is_configured() ) {
			Honk_Client::server_config( Honk_Settings::server_url(), true );
		}
		Honk_Module_Woocommerce::ensure_summary_schedule();
		Honk_Heartbeat::sync_schedule();
	}

	/**
	 * Suggested text for the site's privacy policy (Settings → Privacy).
	 *
	 * @return void
	 */
	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'This site uses Honk to notify the site owner about activity on the site, such as orders, form submissions and security alerts. These notifications are sent to the Honk service (honk-me.app) or to another Honk server the site owner chose.', 'honk' ) . '</p>'
			. '<p>' . esc_html__( 'By default, these notifications contain no personal data: an order is described only by its number, total and number of items. If the site owner turns on “Include customer names and emails,” notifications can also include the name and email address of a customer, user or commenter, the text of comments and reviews, and what was entered in forms.', 'honk' ) . '</p>'
			. '<p>' . sprintf(
				/* translators: %s: link to the Honk privacy policy */
				esc_html__( 'Honk privacy policy: %s', 'honk' ),
				'<a href="https://honk-me.app/privacy">https://honk-me.app/privacy</a>'
			) . '</p>';
		wp_add_privacy_policy_content( 'Honk', wp_kses_post( $text ) );
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return 'log' === $tab ? 'log' : 'settings';
	}

	/**
	 * Renders Settings → Honk.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$tab = self::current_tab();
		?>
		<div class="wrap honk-settings">
			<h1><?php esc_html_e( 'Honk', 'honk' ); ?></h1>
			<p class="honk-intro"><?php esc_html_e( 'Get this site’s orders, form entries and alerts in Honk, on your iPhone, Apple Watch and the web.', 'honk' ); ?></p>
			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'honk' ); ?>">
				<a href="<?php echo esc_url( self::url() ); ?>" class="nav-tab<?php echo 'settings' === $tab ? ' nav-tab-active' : ''; ?>"<?php echo 'settings' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Settings', 'honk' ); ?></a>
				<a href="<?php echo esc_url( self::url( 'log' ) ); ?>" class="nav-tab<?php echo 'log' === $tab ? ' nav-tab-active' : ''; ?>"<?php echo 'log' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Delivery log', 'honk' ); ?></a>
			</nav>
			<?php
			if ( 'log' === $tab ) {
				self::render_log();
			} else {
				self::render_settings();
			}
			?>
		</div>
		<?php
	}

	/**
	 * The settings form.
	 *
	 * @return void
	 */
	private static function render_settings() {
		$s   = Honk_Settings::all();
		$key = Honk_Settings::api_key();
		$opt = Honk_Settings::OPTION;
		settings_errors( $opt );
		?>
		<form method="post" action="options.php" id="honk-settings-form">
			<?php settings_fields( 'honk' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[_form]" value="1">

			<h2><?php esc_html_e( 'Connection', 'honk' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="honk-server-url"><?php esc_html_e( 'Server URL', 'honk' ); ?></label></th>
					<td>
						<input type="url" id="honk-server-url" class="regular-text code" name="<?php echo esc_attr( $opt ); ?>[server_url]" value="<?php echo esc_attr( $s['server_url'] ); ?>" placeholder="<?php echo esc_attr( Honk_Settings::DEFAULT_SERVER ); ?>">
						<p class="description"><?php esc_html_e( 'Leave this as https://honk-me.app unless you run your own Honk server.', 'honk' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="honk-api-key"><?php esc_html_e( 'API key', 'honk' ); ?></label></th>
					<td>
						<input type="password" id="honk-api-key" class="regular-text code" name="<?php echo esc_attr( $opt ); ?>[api_key]" value="" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr( '' !== $key ? Honk_Settings::mask_key( $key ) : 'honk_…' ); ?>" aria-describedby="honk-api-key-description">
						<p class="description" id="honk-api-key-description">
							<?php
							if ( '' !== $key ) {
								printf(
									/* translators: %s: masked key, e.g. honk_ab12cd34_•••••••• */
									esc_html__( 'Saved key: %s. Leave the field empty to keep it.', 'honk' ),
									'<code>' . esc_html( Honk_Settings::mask_key( $key ) ) . '</code>'
								);
							} else {
								esc_html_e( 'In Honk, open your project, go to Keys and create a new key. Nothing is sent until you save one here.', 'honk' );
							}
							?>
						</p>
						<?php if ( '' !== $key ) : ?>
							<p><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[remove_key]" value="1"> <?php esc_html_e( 'Remove the saved key (stops all notifications)', 'honk' ); ?></label></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test', 'honk' ); ?></th>
					<td>
						<button type="button" class="button" id="honk-test"><?php esc_html_e( 'Send test notification', 'honk' ); ?></button>
						<span class="spinner" id="honk-test-spinner"></span>
						<div id="honk-test-result" class="honk-test-result" aria-live="polite"></div>
						<p class="description"><?php esc_html_e( 'Uses the server URL and key above, so you can try them before saving.', 'honk' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Privacy and language', 'honk' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Personal data', 'honk' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Personal data', 'honk' ); ?></legend>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[include_pii]" value="1" <?php checked( ! empty( $s['include_pii'] ) ); ?>> <?php esc_html_e( 'Include customer names and emails', 'honk' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off: no names or email addresses. An order reads “Order #1234 · €84.00 · 2 items” and a form entry only says which form was used. On: names, email addresses, comments and form fields are included.', 'honk' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="honk-environment"><?php esc_html_e( 'Environment', 'honk' ); ?></label></th>
					<td>
						<select id="honk-environment" name="<?php echo esc_attr( $opt ); ?>[environment]">
							<option value="auto" <?php selected( $s['environment'], 'auto' ); ?>>
								<?php
								/* translators: %s: detected environment, e.g. production */
								echo esc_html( sprintf( __( 'Automatic (%s)', 'honk' ), Honk_Settings::detected_environment() ) );
								?>
							</option>
							<?php foreach ( array( 'production', 'staging', 'development' ) as $env ) : ?>
								<option value="<?php echo esc_attr( $env ); ?>" <?php selected( $s['environment'], $env ); ?>><?php echo esc_html( $env ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Tells Honk whether this is your live site or a test copy. Automatic uses the environment type set in WordPress.', 'honk' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="honk-language"><?php esc_html_e( 'Notification language', 'honk' ); ?></label></th>
					<td>
						<select id="honk-language" name="<?php echo esc_attr( $opt ); ?>[language]">
							<option value="" <?php selected( $s['language'], '' ); ?>><?php esc_html_e( 'Site language', 'honk' ); ?></option>
							<?php foreach ( self::languages() as $locale => $name ) : ?>
								<option value="<?php echo esc_attr( $locale ); ?>" lang="<?php echo esc_attr( str_replace( '_', '-', $locale ) ); ?>" <?php selected( $s['language'], $locale ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Events', 'honk' ); ?></h2>
			<p><?php esc_html_e( 'Choose what you want to hear about and how loud each one should be, from Light honk to Blast. Honk groups repeats, so you only get a push when something needs you.', 'honk' ); ?></p>
			<?php
			foreach ( Honk_Events::sections() as $section => $title ) {
				self::render_section( $section, $title, $s );
			}
			self::render_heartbeat( $s );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Language names, each in its own language.
	 *
	 * @return array<string, string>
	 */
	private static function languages() {
		return array(
			'en_US' => 'English',
			'ro_RO' => 'Română',
			'es_ES' => 'Español',
			'fr_FR' => 'Français',
			'de_DE' => 'Deutsch',
		);
	}

	/**
	 * One section of events (a table per section).
	 *
	 * @param string $section  Section id.
	 * @param string $title    Section title.
	 * @param array  $settings Settings.
	 * @return void
	 */
	private static function render_section( $section, $title, array $settings ) {
		$ids = array();
		foreach ( Honk_Events::DEFAULTS as $id => $row ) {
			if ( $row[0] === $section ) {
				$ids[] = $id;
			}
		}
		echo '<h3>' . esc_html( $title ) . '</h3>';

		if ( 'woocommerce' === $section && ! class_exists( 'WooCommerce' ) ) {
			echo '<p class="description">' . esc_html__( 'WooCommerce isn’t active. Its events show up here once it is.', 'honk' ) . '</p>';
			return;
		}
		if ( 'forms' === $section ) {
			$ids = array_values( array_filter( $ids, array( 'Honk_Events', 'is_available' ) ) );
			if ( empty( $ids ) ) {
				echo '<p class="description">' . esc_html__( 'No supported form plugin is active. Honk works with Contact Form 7, WPForms, Gravity Forms, Elementor Pro Forms, Fluent Forms and Ninja Forms.', 'honk' ) . '</p>';
				return;
			}
			echo '<p class="description">' . esc_html__( 'Found automatically. Each notification is titled with the form’s name. What people entered is included only if you allow personal data above.', 'honk' ) . '</p>';
		}
		if ( 'woocommerce' === $section ) {
			$ids = array_values( array_filter( $ids, array( 'Honk_Events', 'is_available' ) ) );
		}
		?>
		<table class="widefat striped honk-events">
			<thead>
				<tr>
					<th scope="col" class="honk-col-event"><?php esc_html_e( 'Event', 'honk' ); ?></th>
					<th scope="col" class="honk-col-send"><?php esc_html_e( 'Send', 'honk' ); ?></th>
					<th scope="col" class="honk-col-level"><?php esc_html_e( 'Level', 'honk' ); ?></th>
					<th scope="col" class="honk-col-priority"><?php esc_html_e( 'Priority', 'honk' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $ids as $id ) {
					self::render_event_row( $id, $settings );
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * One event row: on/off, Honk-scale level, priority.
	 *
	 * @param string $id       Event id.
	 * @param array  $settings Settings.
	 * @return void
	 */
	private static function render_event_row( $id, array $settings ) {
		$event = Honk_Settings::event( $id );
		$label = Honk_Events::label( $id );
		$name  = Honk_Settings::OPTION . '[events][' . $id . ']';
		$dom   = 'honk-event-' . str_replace( '_', '-', $id );
		?>
		<tr>
			<td class="honk-col-event">
				<label for="<?php echo esc_attr( $dom ); ?>"><strong><?php echo esc_html( $label[0] ); ?></strong></label>
				<?php if ( '' !== $label[1] ) : ?>
					<p class="description"><?php echo esc_html( $label[1] ); ?></p>
				<?php endif; ?>
				<?php if ( 'login_failures_burst' === $id ) : ?>
					<p class="description honk-burst">
						<?php
						printf(
							/* translators: 1: number input (failures), 2: number input (minutes) */
							esc_html__( 'Notify me after %1$s or more failed sign-ins within %2$s minutes.', 'honk' ),
							'<input type="number" min="3" max="1000" step="1" class="small-text" name="' . esc_attr( Honk_Settings::OPTION ) . '[burst_threshold]" value="' . esc_attr( (string) (int) $settings['burst_threshold'] ) . '" aria-label="' . esc_attr__( 'Failed sign-ins', 'honk' ) . '">',
							'<input type="number" min="1" max="120" step="1" class="small-text" name="' . esc_attr( Honk_Settings::OPTION ) . '[burst_minutes]" value="' . esc_attr( (string) (int) $settings['burst_minutes'] ) . '" aria-label="' . esc_attr__( 'Minutes', 'honk' ) . '">'
						);
						?>
					</p>
				<?php endif; ?>
			</td>
			<td class="honk-col-send">
				<input type="checkbox" id="<?php echo esc_attr( $dom ); ?>" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $event['enabled'] ); ?>>
			</td>
			<td class="honk-col-level">
				<select name="<?php echo esc_attr( $name ); ?>[severity]" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: event name */ __( 'Level for %s', 'honk' ), $label[0] ) ); ?>">
					<?php foreach ( Honk_Settings::SEVERITIES as $severity ) : ?>
						<option value="<?php echo esc_attr( $severity ); ?>" <?php selected( $event['severity'], $severity ); ?> title="<?php echo esc_attr( $severity ); ?>"><?php echo esc_html( Honk_Settings::severity_label( $severity ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td class="honk-col-priority">
				<select name="<?php echo esc_attr( $name ); ?>[priority]" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: event name */ __( 'Priority for %s', 'honk' ), $label[0] ) ); ?>">
					<?php foreach ( Honk_Settings::PRIORITIES as $priority ) : ?>
						<option value="<?php echo esc_attr( $priority ); ?>" <?php selected( $event['priority'], $priority ); ?>><?php echo esc_html( Honk_Settings::priority_label( $priority ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * The heartbeat option, or "Coming soon" while the server does not support heartbeats.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	private static function render_heartbeat( array $settings ) {
		$supported = Honk_Settings::is_configured() && Honk_Heartbeat::server_supports( true );
		?>
		<h2><?php esc_html_e( 'Heartbeat', 'honk' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Check in every 5 minutes', 'honk' ); ?></th>
				<td>
					<?php if ( $supported ) : ?>
						<label><input type="checkbox" name="<?php echo esc_attr( Honk_Settings::OPTION ); ?>[heartbeat]" value="1" <?php checked( ! empty( $settings['heartbeat'] ) ); ?>> <?php esc_html_e( 'Tell me when this site stops checking in', 'honk' ); ?></label>
					<?php else : ?>
						<?php if ( ! empty( $settings['heartbeat'] ) ) : ?>
							<input type="hidden" name="<?php echo esc_attr( Honk_Settings::OPTION ); ?>[heartbeat]" value="1">
						<?php endif; ?>
						<p class="description"><strong><?php esc_html_e( 'Coming soon.', 'honk' ); ?></strong> <?php esc_html_e( 'Honk will let you know when this site goes quiet, even if it’s too broken to say so itself. The option appears here once your Honk server supports it.', 'honk' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * The delivery log tab.
	 *
	 * @return void
	 */
	private static function render_log() {
		$rows    = Honk_Log::all();
		$private = ! Honk_Settings::include_pii();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Delivery log cleared.', 'honk' ) . '</p></div>';
		}
		?>
		<p><?php esc_html_e( 'The last 50 notifications this site sent to Honk, newest first. They’re sent in the background and retried if Honk is busy or can’t be reached.', 'honk' ); ?></p>
		<table class="widefat striped honk-log">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'honk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'honk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Title', 'honk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'honk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'honk' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing sent yet.', 'honk' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$label = Honk_Events::exists( (string) $row['event'] ) ? Honk_Events::label( (string) $row['event'] ) : array( (string) $row['event'] );
					$title = ( $private && ! empty( $row['pii'] ) ) ? __( '(hidden: may contain personal data)', 'honk' ) : (string) $row['title'];
					?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) ); ?></td>
						<td><?php echo esc_html( $label[0] ); ?></td>
						<td><?php echo esc_html( $title ); ?></td>
						<td><?php echo esc_html( self::status_label( (string) $row['status'] ) ); ?></td>
						<td><?php echo esc_html( self::log_detail( $row ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $rows ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="honk_clear_log">
				<?php wp_nonce_field( 'honk_clear_log' ); ?>
				<?php submit_button( __( 'Clear log', 'honk' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Translated delivery status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( $status ) {
		switch ( $status ) {
			case 'queued':
				return __( 'Queued', 'honk' );
			case 'sent':
				return __( 'Sent', 'honk' );
			case 'duplicate':
				return __( 'Already sent', 'honk' );
			case 'retry':
				return __( 'Will retry', 'honk' );
			case 'failed':
				return __( 'Failed', 'honk' );
		}
		return $status;
	}

	/**
	 * The details column: HTTP status, error, attempts, message id.
	 *
	 * @param array $row Log row.
	 * @return string
	 */
	private static function log_detail( array $row ) {
		$parts = array();
		if ( ! empty( $row['http'] ) ) {
			$parts[] = 'HTTP ' . (int) $row['http'];
		}
		if ( ! empty( $row['code'] ) && 'accepted' !== $row['code'] ) {
			$parts[] = (string) $row['code'];
		}
		if ( ! empty( $row['detail'] ) ) {
			$parts[] = (string) $row['detail'];
		}
		if ( ! empty( $row['message_id'] ) ) {
			$parts[] = (string) $row['message_id'];
		}
		if ( ! empty( $row['attempts'] ) && (int) $row['attempts'] > 1 ) {
			/* translators: %d: number of attempts */
			$parts[] = sprintf( _n( '%d attempt', '%d attempts', (int) $row['attempts'], 'honk' ), (int) $row['attempts'] );
		}
		if ( 'retry' === $row['status'] && ! empty( $row['next'] ) ) {
			/* translators: %s: time of the next attempt */
			$parts[] = sprintf( __( 'next try at %s', 'honk' ), wp_date( get_option( 'time_format' ), (int) $row['next'] ) );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Clears the delivery log (admin-post.php).
	 *
	 * @return void
	 */
	public static function clear_log() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'honk' ), 403 );
		}
		check_admin_referer( 'honk_clear_log' );
		Honk_Log::clear();
		wp_safe_redirect( add_query_arg( 'cleared', '1', self::url( 'log' ) ) );
		exit;
	}

	/**
	 * "Send test notification": sends one message right away and reports the server's answer.
	 *
	 * @return void
	 */
	public static function ajax_test() {
		check_ajax_referer( 'honk_test', 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'honk' ) ), 403 );
		}
		$server = isset( $_POST['server_url'] ) ? untrailingslashit( esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['server_url'] ) ) ), array( 'https', 'http' ) ) ) : '';
		$key    = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		$server = '' !== $server ? $server : Honk_Settings::server_url();
		$key    = '' !== $key ? $key : Honk_Settings::api_key();

		if ( ! Honk_Settings::is_valid_server_url( $server ) ) {
			wp_send_json_error( array( 'message' => __( 'The server URL must start with https:// (http:// works only for local addresses).', 'honk' ) ) );
		}
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Enter your API key first.', 'honk' ) ) );
		}

		$fields  = Honk_I18n::with_locale(
			function () {
				return array(
					/* translators: %s: site name */
					'title'   => sprintf( __( 'Test from %s', 'honk' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
					'message' => __( 'If you can read this, your site is connected to Honk. Sent from Settings → Honk.', 'honk' ),
				);
			}
		);
		$payload = Honk_Payload::normalize(
			array_merge(
				$fields,
				array(
					'severity'    => 'info',
					'priority'    => 'normal',
					'source'      => Honk_Settings::source(),
					'environment' => Honk_Settings::environment(),
					'channel'     => 'test',
					'group_key'   => 'wp/test',
					'category'    => 'other',
				)
			)
		);
		$result  = Honk_Client::send( $server, $key, Honk_Payload::encode( $payload ), 'wp-' . Honk_Settings::site_id() . '-test-' . str_replace( '-', '', wp_generate_uuid4() ), array( 'timeout' => 10 ) );
		Honk_Client::server_config( $server, true );

		if ( $result['ok'] ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: 1: message id, 2: duration in milliseconds */
						__( 'It works. The test notification should be in your Honk inbox now (ID %1$s, %2$d ms).', 'honk' ),
						$result['id'],
						$result['ms']
					),
				)
			);
		}
		if ( 0 === $result['status'] ) {
			/* translators: 1: server URL, 2: error message */
			$message = sprintf( __( 'Couldn’t reach %1$s: %2$s', 'honk' ), $server, $result['message'] );
		} elseif ( 401 === $result['status'] ) {
			$message = __( 'Honk didn’t accept this key. Check that you copied all of it and that it hasn’t been revoked in Honk.', 'honk' );
		} else {
			/* translators: 1: HTTP status, 2: error code, 3: error message */
			$message = trim( sprintf( __( 'Honk returned an error (%1$d %2$s): %3$s', 'honk' ), $result['status'], $result['code'], $result['message'] ), ' :' );
		}
		wp_send_json_error( array( 'message' => $message ) );
	}

	/**
	 * WooCommerce → Settings: a "Honk" tab that links here.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function woocommerce_tab( $tabs ) {
		$tabs['honk'] = __( 'Honk', 'honk' );
		return $tabs;
	}

	/**
	 * Content of the WooCommerce → Settings → Honk tab.
	 *
	 * @return void
	 */
	public static function woocommerce_tab_content() {
		$GLOBALS['hide_save_button'] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's own flag that hides the Save button on a settings tab.
		?>
		<h2><?php esc_html_e( 'Honk', 'honk' ); ?></h2>
		<p><?php esc_html_e( 'Order, payment, stock, customer and review notifications are set up under Settings → Honk, with the rest of the site’s notifications.', 'honk' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Open Honk settings', 'honk' ); ?></a></p>
		<?php
	}
}
