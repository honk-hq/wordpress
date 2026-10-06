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
		add_options_page( __( 'Honk', 'honk-me' ), __( 'Honk', 'honk-me' ), self::CAPABILITY, self::PAGE, array( __CLASS__, 'render' ) );
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
		if ( 'log' !== self::current_tab() ) {
			// The previews' outlines (sample data, in the notification language) for assets/admin.js.
			wp_add_inline_script( 'honk-admin', 'window.honkPreviews = ' . wp_json_encode( Honk_Preview::specs(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		}
		wp_localize_script(
			'honk-admin',
			'honkAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'honk_test' ),
				'sending' => __( 'Sending…', 'honk-me' ),
				'failed'  => __( 'The test couldn’t run. Reload the page and try again.', 'honk-me' ),
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
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'honk-me' ) . '</a>' );
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
			esc_html__( 'Honk is almost set up. Add your API key to start getting notifications.', 'honk-me' ),
			esc_url( self::url() ),
			esc_html__( 'Open Honk settings', 'honk-me' )
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
		$text = '<p>' . esc_html__( 'This site uses Honk to notify the site owner about activity on the site, such as orders, form submissions and security alerts. These notifications are sent to the Honk service (honk-me.app) or to another Honk server the site owner chose.', 'honk-me' ) . '</p>'
			. '<p>' . esc_html__( 'By default, these notifications contain no personal data: an order is described only by its number, total and number of items. If the site owner turns on “Include customer names and emails,” notifications can also include, depending on what the site owner chose for each event, the name, email address and phone number of a customer, user or commenter, a customer’s billing city and country, the text of comments, reviews and order notes, what was entered in forms, and the IP address of an administrator who signs in from a new device.', 'honk-me' ) . '</p>'
			. '<p>' . sprintf(
				/* translators: %s: link to the Honk privacy policy */
				esc_html__( 'Honk privacy policy: %s', 'honk-me' ),
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
			<h1><?php esc_html_e( 'Honk', 'honk-me' ); ?></h1>
			<p class="honk-intro"><?php esc_html_e( 'Get this site’s orders, form entries and alerts in Honk, on your iPhone, Apple Watch and the web.', 'honk-me' ); ?></p>
			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'honk-me' ); ?>">
				<a href="<?php echo esc_url( self::url() ); ?>" class="nav-tab<?php echo 'settings' === $tab ? ' nav-tab-active' : ''; ?>"<?php echo 'settings' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Settings', 'honk-me' ); ?></a>
				<a href="<?php echo esc_url( self::url( 'log' ) ); ?>" class="nav-tab<?php echo 'log' === $tab ? ' nav-tab-active' : ''; ?>"<?php echo 'log' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Delivery log', 'honk-me' ); ?></a>
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

			<h2><?php esc_html_e( 'Connection', 'honk-me' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="honk-server-url"><?php esc_html_e( 'Server URL', 'honk-me' ); ?></label></th>
					<td>
						<input type="url" id="honk-server-url" class="regular-text code" name="<?php echo esc_attr( $opt ); ?>[server_url]" value="<?php echo esc_attr( $s['server_url'] ); ?>" placeholder="<?php echo esc_attr( Honk_Settings::DEFAULT_SERVER ); ?>">
						<p class="description"><?php esc_html_e( 'Leave this as https://honk-me.app unless you run your own Honk server.', 'honk-me' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="honk-api-key"><?php esc_html_e( 'API key', 'honk-me' ); ?></label></th>
					<td>
						<input type="password" id="honk-api-key" class="regular-text code" name="<?php echo esc_attr( $opt ); ?>[api_key]" value="" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr( '' !== $key ? Honk_Settings::mask_key( $key ) : 'honk_…' ); ?>" aria-describedby="honk-api-key-description">
						<p class="description" id="honk-api-key-description">
							<?php
							if ( '' !== $key ) {
								printf(
									/* translators: %s: masked key, e.g. honk_ab12cd34_•••••••• */
									esc_html__( 'Saved key: %s. Leave the field empty to keep it.', 'honk-me' ),
									'<code>' . esc_html( Honk_Settings::mask_key( $key ) ) . '</code>'
								);
							} else {
								esc_html_e( 'In Honk, open your project, go to Keys and create a new key. Nothing is sent until you save one here.', 'honk-me' );
							}
							?>
						</p>
						<?php if ( '' !== $key ) : ?>
							<p><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[remove_key]" value="1"> <?php esc_html_e( 'Remove the saved key (stops all notifications)', 'honk-me' ); ?></label></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test', 'honk-me' ); ?></th>
					<td>
						<button type="button" class="button" id="honk-test"><?php esc_html_e( 'Send test notification', 'honk-me' ); ?></button>
						<span class="spinner" id="honk-test-spinner"></span>
						<div id="honk-test-result" class="honk-test-result" aria-live="polite"></div>
						<p class="description"><?php esc_html_e( 'Uses the server URL and key above, so you can try them before saving.', 'honk-me' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Privacy and language', 'honk-me' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Personal data', 'honk-me' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Personal data', 'honk-me' ); ?></legend>
							<label><input type="checkbox" id="honk-include-pii" name="<?php echo esc_attr( $opt ); ?>[include_pii]" value="1" <?php checked( ! empty( $s['include_pii'] ) ); ?>> <?php esc_html_e( 'Include customer names and emails', 'honk-me' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off: no names or email addresses. An order reads “Order #1234 · €84.00 · 2 items” and a form entry only says which form was used. On: names, email addresses, comments and form fields are included.', 'honk-me' ); ?></p>
							<p class="description"><?php esc_html_e( 'Under Events, each event’s Details show which personal data it can include, and let you leave out what you don’t need.', 'honk-me' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="honk-environment"><?php esc_html_e( 'Environment', 'honk-me' ); ?></label></th>
					<td>
						<select id="honk-environment" name="<?php echo esc_attr( $opt ); ?>[environment]">
							<option value="auto" <?php selected( $s['environment'], 'auto' ); ?>>
								<?php
								/* translators: %s: detected environment, e.g. production */
								echo esc_html( sprintf( __( 'Automatic (%s)', 'honk-me' ), Honk_Settings::detected_environment() ) );
								?>
							</option>
							<?php foreach ( array( 'production', 'staging', 'development' ) as $env ) : ?>
								<option value="<?php echo esc_attr( $env ); ?>" <?php selected( $s['environment'], $env ); ?>><?php echo esc_html( $env ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Tells Honk whether this is your live site or a test copy. Automatic uses the environment type set in WordPress.', 'honk-me' ); ?></p>
					</td>
				</tr>
				<tr>
					<?php
					$languages = Honk_I18n::languages();
					if ( '' !== $s['language'] && ! isset( $languages[ $s['language'] ] ) ) {
						$languages[ $s['language'] ] = $s['language']; // Saved, but no longer installed.
					}
					?>
					<th scope="row"><label for="honk-language"><?php esc_html_e( 'Notification language', 'honk-me' ); ?></label></th>
					<td>
						<select id="honk-language" name="<?php echo esc_attr( $opt ); ?>[language]">
							<option value="" <?php selected( $s['language'], '' ); ?>><?php esc_html_e( 'Site language', 'honk-me' ); ?></option>
							<?php foreach ( $languages as $locale => $name ) : ?>
								<option value="<?php echo esc_attr( $locale ); ?>" lang="<?php echo esc_attr( str_replace( '_', '-', $locale ) ); ?>" <?php selected( $s['language'], $locale ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Notifications use the WordPress translations of Honk Me. Every language installed on this site is listed here; where a language has no translation yet, notifications are in English.', 'honk-me' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Events', 'honk-me' ); ?></h2>
			<p><?php esc_html_e( 'Choose what you want to hear about and how loud each one should be, from Light honk to Blast. Honk groups repeats, so you only get a push when something needs you.', 'honk-me' ); ?></p>
			<p><?php esc_html_e( 'Open Details under an event to choose what its notification says. The preview next to the choices shows how it will read in Honk.', 'honk-me' ); ?></p>
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
			echo '<p class="description">' . esc_html__( 'WooCommerce isn’t active. Its events show up here once it is.', 'honk-me' ) . '</p>';
			return;
		}
		if ( 'forms' === $section ) {
			$ids = array_values( array_filter( $ids, array( 'Honk_Events', 'is_available' ) ) );
			if ( empty( $ids ) ) {
				echo '<p class="description">' . esc_html__( 'No supported form plugin is active. Honk works with Contact Form 7, WPForms, Gravity Forms, Elementor Pro Forms, Fluent Forms and Ninja Forms.', 'honk-me' ) . '</p>';
				return;
			}
			echo '<p class="description">' . esc_html__( 'Found automatically. Each notification is titled with the form’s name. What people entered is included only if you allow personal data above.', 'honk-me' ) . '</p>';
		}
		if ( 'woocommerce' === $section ) {
			$ids = array_values( array_filter( $ids, array( 'Honk_Events', 'is_available' ) ) );
		}
		?>
		<table class="widefat honk-events">
			<thead>
				<tr>
					<th scope="col" class="honk-col-event"><?php esc_html_e( 'Event', 'honk-me' ); ?></th>
					<th scope="col" class="honk-col-send"><?php esc_html_e( 'Send', 'honk-me' ); ?></th>
					<th scope="col" class="honk-col-level"><?php esc_html_e( 'Level', 'honk-me' ); ?></th>
					<th scope="col" class="honk-col-priority"><?php esc_html_e( 'Priority', 'honk-me' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $ids as $i => $id ) {
					// Striped by event (WordPress's .alternate), so an event and its details share a background.
					self::render_event_row( $id, $settings, 0 === $i % 2 );
					self::render_details_row( $id, $settings, 0 === $i % 2 );
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * One event row: on/off, Honk-scale level, priority, and the button that opens its details.
	 *
	 * @param string $id        Event id.
	 * @param array  $settings  Settings.
	 * @param bool   $alternate Striped row.
	 * @return void
	 */
	private static function render_event_row( $id, array $settings, $alternate ) {
		$event = Honk_Settings::event( $id );
		$label = Honk_Events::label( $id );
		$name  = Honk_Settings::OPTION . '[events][' . $id . ']';
		$dom   = 'honk-event-' . str_replace( '_', '-', $id );
		?>
		<tr class="honk-event-row<?php echo $alternate ? ' alternate' : ''; ?>">
			<td class="honk-col-event">
				<label for="<?php echo esc_attr( $dom ); ?>"><strong><?php echo esc_html( $label[0] ); ?></strong></label>
				<?php if ( '' !== $label[1] ) : ?>
					<p class="description"><?php echo esc_html( $label[1] ); ?></p>
				<?php endif; ?>
				<p class="honk-details-toggle-wrap">
					<button type="button" class="button-link honk-details-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $dom . '-details' ); ?>"><?php esc_html_e( 'Details', 'honk-me' ); ?><span class="screen-reader-text">: <?php echo esc_html( $label[0] ); ?></span><span class="honk-details-indicator" aria-hidden="true"></span></button>
				</p>
				<?php if ( 'login_failures_burst' === $id ) : ?>
					<p class="description honk-burst">
						<?php
						printf(
							/* translators: 1: number input (failures), 2: number input (minutes) */
							esc_html__( 'Notify me after %1$s or more failed sign-ins within %2$s minutes.', 'honk-me' ),
							'<input type="number" min="3" max="1000" step="1" class="small-text" name="' . esc_attr( Honk_Settings::OPTION ) . '[burst_threshold]" value="' . esc_attr( (string) (int) $settings['burst_threshold'] ) . '" aria-label="' . esc_attr__( 'Failed sign-ins', 'honk-me' ) . '">',
							'<input type="number" min="1" max="120" step="1" class="small-text" name="' . esc_attr( Honk_Settings::OPTION ) . '[burst_minutes]" value="' . esc_attr( (string) (int) $settings['burst_minutes'] ) . '" aria-label="' . esc_attr__( 'Minutes', 'honk-me' ) . '">'
						);
						?>
					</p>
				<?php endif; ?>
			</td>
			<td class="honk-col-send">
				<input type="checkbox" id="<?php echo esc_attr( $dom ); ?>" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $event['enabled'] ); ?>>
			</td>
			<td class="honk-col-level">
				<select name="<?php echo esc_attr( $name ); ?>[severity]" data-honk-level="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: event name */ __( 'Level for %s', 'honk-me' ), $label[0] ) ); ?>">
					<?php foreach ( Honk_Settings::SEVERITIES as $severity ) : ?>
						<option value="<?php echo esc_attr( $severity ); ?>" <?php selected( $event['severity'], $severity ); ?> title="<?php echo esc_attr( $severity ); ?>"><?php echo esc_html( Honk_Settings::severity_label( $severity ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td class="honk-col-priority">
				<select name="<?php echo esc_attr( $name ); ?>[priority]" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: event name */ __( 'Priority for %s', 'honk-me' ), $label[0] ) ); ?>">
					<?php foreach ( Honk_Settings::PRIORITIES as $priority ) : ?>
						<option value="<?php echo esc_attr( $priority ); ?>" <?php selected( $event['priority'], $priority ); ?>><?php echo esc_html( Honk_Settings::priority_label( $priority ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * An event's details: what its notification can say, as choices, next to the preview.
	 *
	 * Personal details can only be chosen while "Include customer names and emails" is on. Until
	 * then they're disabled, and their saved choice travels in a hidden field of the same name
	 * (assets/admin.js keeps the two in step when the switch changes).
	 *
	 * @param string $id        Event id.
	 * @param array  $settings  Settings.
	 * @param bool   $alternate Striped row.
	 * @return void
	 */
	private static function render_details_row( $id, array $settings, $alternate ) {
		$dom      = 'honk-event-' . str_replace( '_', '-', $id );
		$label    = Honk_Events::label( $id );
		$facts    = Honk_Details::facts( $id );
		$pii      = ! empty( $settings['include_pii'] );
		$name     = Honk_Settings::OPTION . '[details][' . $id . ']';
		$always   = Honk_Details::always( $id );
		$general  = array();
		$personal = array();
		foreach ( $facts as $fact => $meta ) {
			if ( $meta[0] ) {
				$personal[] = $fact;
			} else {
				$general[] = $fact;
			}
		}
		?>
		<tr id="<?php echo esc_attr( $dom . '-details' ); ?>" class="honk-details-row<?php echo $alternate ? ' alternate' : ''; ?>" data-event="<?php echo esc_attr( $id ); ?>" hidden>
			<td colspan="4">
				<div class="honk-details">
					<fieldset class="honk-facts">
						<legend class="honk-details-heading">
							<?php
							/* translators: %s: event name, e.g. "New order" */
							echo esc_html( sprintf( __( 'What “%s” includes', 'honk-me' ), $label[0] ) );
							?>
						</legend>
						<?php if ( $always ) : ?>
							<p class="description">
								<?php
								/* translators: %s: a list, e.g. "the order number and a link to the order" */
								echo esc_html( sprintf( __( 'Always included: %s.', 'honk-me' ), wp_sprintf( '%l', $always ) ) );
								?>
							</p>
						<?php endif; ?>
						<?php if ( empty( $facts ) ) : ?>
							<p class="description"><?php esc_html_e( 'There’s nothing else to choose for this one.', 'honk-me' ); ?></p>
						<?php endif; ?>
						<?php if ( $general ) : ?>
							<ul class="honk-choices">
								<?php
								foreach ( $general as $fact ) {
									self::render_choice( $name . '[' . $fact . ']', Honk_Details::label( $id, $fact ), Honk_Details::chosen( $id, $fact ), false, array( 'data-fact' => $fact ) );
								}
								?>
							</ul>
						<?php endif; ?>
						<?php if ( $personal ) : ?>
							<fieldset class="honk-facts-personal">
								<legend><?php esc_html_e( 'Personal data', 'honk-me' ); ?></legend>
								<ul class="honk-choices">
									<?php
									foreach ( $personal as $fact ) {
										self::render_choice(
											$name . '[' . $fact . ']',
											Honk_Details::label( $id, $fact ),
											Honk_Details::chosen( $id, $fact ),
											! $pii,
											array(
												'data-fact' => $fact,
												'data-pii' => '1',
												'data-note' => $dom . '-pii-note',
												'aria-describedby' => $pii ? '' : $dom . '-pii-note',
											)
										);
									}
									?>
								</ul>
								<?php self::render_form_fields( $id, $name, $dom, $pii ); ?>
								<p class="description honk-pii-note" id="<?php echo esc_attr( $dom . '-pii-note' ); ?>"<?php echo $pii ? ' hidden' : ''; ?>><?php esc_html_e( 'Personal data is off for this site, so these are left out. To choose them, turn on “Include customer names and emails” above.', 'honk-me' ); ?></p>
							</fieldset>
						<?php endif; ?>
					</fieldset>
					<?php self::render_preview( $id, $dom ); ?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * One choice: a checkbox, and before it a hidden field with the same name that carries the
	 * saved choice while the checkbox is disabled (a disabled checkbox isn't submitted).
	 *
	 * @param string $name    Field name.
	 * @param string $label   Label.
	 * @param bool   $chosen  Saved choice.
	 * @param bool   $blocked Disabled: personal data is off, or the form's answers are left out.
	 * @param array  $attrs   Attributes of the checkbox (data-fact, data-pii, data-requires…).
	 * @return void
	 */
	private static function render_choice( $name, $label, $chosen, $blocked, array $attrs ) {
		?>
		<li>
			<input type="hidden" class="honk-choice-saved" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $blocked && $chosen ? '1' : '0' ); ?>">
			<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1"
			<?php
			foreach ( array_filter( $attrs, 'strlen' ) as $attr => $value ) {
				echo ' ' . esc_attr( $attr ) . '="' . esc_attr( $value ) . '"';
			}
			checked( $chosen && ! $blocked );
			disabled( $blocked );
			?>
			> <?php echo esc_html( $label ); ?></label>
		</li>
		<?php
	}

	/**
	 * Form plugins that list their forms' fields: one checkbox per field, per form. The others say
	 * that it's every field or none.
	 *
	 * @param string $id   Event id.
	 * @param string $name Field name prefix.
	 * @param string $dom  DOM id prefix.
	 * @param bool   $pii  Personal data is on.
	 * @return void
	 */
	private static function render_form_fields( $id, $name, $dom, $pii ) {
		if ( 'forms' !== Honk_Events::section( $id ) ) {
			return;
		}
		$label = Honk_Events::label( $id );
		if ( ! in_array( $id, Honk_Details::FORM_FIELD_EVENTS, true ) ) {
			/* translators: %s: form plugin name, e.g. Fluent Forms */
			echo '<p class="description">' . esc_html( sprintf( __( '%s doesn’t share a form’s list of fields with other plugins, so Honk includes every field or none.', 'honk-me' ), $label[0] ) ) . '</p>';
			return;
		}
		$all   = Honk_Module_Forms::forms( $id );
		$forms = array_filter(
			$all,
			function ( $form ) {
				return ! empty( $form['fields'] );
			}
		);
		if ( empty( $forms ) ) {
			return;
		}
		$values = Honk_Details::chosen( $id, 'values' );
		?>
		<fieldset class="honk-form-fields">
			<legend><?php esc_html_e( 'Fields to include', 'honk-me' ); ?></legend>
			<?php foreach ( $forms as $form_id => $form ) : ?>
				<?php $skip = Honk_Details::skipped_fields( $id, (string) $form_id ); ?>
				<p class="honk-form-name"><?php echo esc_html( '' !== $form['title'] ? $form['title'] : __( '(no title)', 'honk-me' ) ); ?></p>
				<ul class="honk-choices">
					<?php
					foreach ( $form['fields'] as $key => $field ) {
						self::render_choice(
							$name . '[fields][' . Honk_Details::form_key( $form_id ) . '][' . Honk_Details::field_key( $key ) . ']',
							$field['label'],
							! in_array( Honk_Details::field_key( $key ), $skip, true ),
							! $pii || ! $values,
							array(
								'data-fact'        => Honk_Preview::field_fact( (string) $form_id, (string) $key ),
								'data-pii'         => '1',
								'data-requires'    => 'values',
								'data-note'        => $dom . '-pii-note',
								'aria-describedby' => $pii ? '' : $dom . '-pii-note',
							)
						);
					}
					?>
				</ul>
			<?php endforeach; ?>
			<?php if ( count( $all ) >= Honk_Module_Forms::MAX_LISTED_FORMS ) : ?>
				<p class="description">
					<?php
					/* translators: %d: number of forms */
					echo esc_html( sprintf( __( 'Only the first %d forms are listed here. The others include every field.', 'honk-me' ), Honk_Module_Forms::MAX_LISTED_FORMS ) );
					?>
				</p>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * The preview: the event's notification as it will read in Honk, built from sample data with
	 * the saved choices. assets/admin.js redraws it as the choices change.
	 *
	 * @param string $id  Event id.
	 * @param string $dom DOM id prefix.
	 * @return void
	 */
	private static function render_preview( $id, $dom ) {
		$specs    = Honk_Preview::specs();
		$composed = Honk_Details::compose( $id, isset( $specs[ $id ] ) ? $specs[ $id ] : array(), Honk_Preview::states( $id ) );
		$event    = Honk_Settings::event( $id );
		?>
		<div class="honk-preview">
			<h4 class="honk-details-heading" id="<?php echo esc_attr( $dom . '-preview-heading' ); ?>"><?php esc_html_e( 'Preview', 'honk-me' ); ?></h4>
			<div class="honk-preview-card" role="group" aria-labelledby="<?php echo esc_attr( $dom . '-preview-heading' ); ?>" aria-live="polite">
				<p class="honk-preview-meta"><span class="honk-preview-level"><?php echo esc_html( Honk_Settings::severity_label( $event['severity'] ) ); ?></span> · <?php echo esc_html( Honk_Settings::source() ); ?></p>
				<p class="honk-preview-title"><?php echo esc_html( $composed['title'] ); ?></p>
				<p class="honk-preview-message"><?php echo esc_html( '' !== $composed['message'] ? $composed['message'] : $composed['title'] ); ?></p>
			</div>
			<p class="description"><?php esc_html_e( 'With sample data, in the notification language.', 'honk-me' ); ?></p>
		</div>
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
		<h2><?php esc_html_e( 'Heartbeat', 'honk-me' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Check in every 5 minutes', 'honk-me' ); ?></th>
				<td>
					<?php if ( $supported ) : ?>
						<label><input type="checkbox" name="<?php echo esc_attr( Honk_Settings::OPTION ); ?>[heartbeat]" value="1" <?php checked( ! empty( $settings['heartbeat'] ) ); ?>> <?php esc_html_e( 'Tell me when this site stops checking in', 'honk-me' ); ?></label>
					<?php else : ?>
						<?php if ( ! empty( $settings['heartbeat'] ) ) : ?>
							<input type="hidden" name="<?php echo esc_attr( Honk_Settings::OPTION ); ?>[heartbeat]" value="1">
						<?php endif; ?>
						<p class="description"><strong><?php esc_html_e( 'Coming soon.', 'honk-me' ); ?></strong> <?php esc_html_e( 'Honk will let you know when this site goes quiet, even if it’s too broken to say so itself. The option appears here once your Honk server supports it.', 'honk-me' ); ?></p>
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
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Delivery log cleared.', 'honk-me' ) . '</p></div>';
		}
		?>
		<p><?php esc_html_e( 'The last 50 notifications this site sent to Honk, newest first. They’re sent in the background and retried if Honk is busy or can’t be reached.', 'honk-me' ); ?></p>
		<table class="widefat striped honk-log">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'honk-me' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'honk-me' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Title', 'honk-me' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'honk-me' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'honk-me' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing sent yet.', 'honk-me' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$label = Honk_Events::exists( (string) $row['event'] ) ? Honk_Events::label( (string) $row['event'] ) : array( (string) $row['event'] );
					$title = ( $private && ! empty( $row['pii'] ) ) ? __( '(hidden: may contain personal data)', 'honk-me' ) : (string) $row['title'];
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
				<?php submit_button( __( 'Clear log', 'honk-me' ), 'secondary', 'submit', false ); ?>
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
				return __( 'Queued', 'honk-me' );
			case 'sent':
				return __( 'Sent', 'honk-me' );
			case 'duplicate':
				return __( 'Already sent', 'honk-me' );
			case 'retry':
				return __( 'Will retry', 'honk-me' );
			case 'failed':
				return __( 'Failed', 'honk-me' );
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
			$parts[] = sprintf( _n( '%d attempt', '%d attempts', (int) $row['attempts'], 'honk-me' ), (int) $row['attempts'] );
		}
		if ( 'retry' === $row['status'] && ! empty( $row['next'] ) ) {
			/* translators: %s: time of the next attempt */
			$parts[] = sprintf( __( 'next try at %s', 'honk-me' ), wp_date( get_option( 'time_format' ), (int) $row['next'] ) );
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
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'honk-me' ), 403 );
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
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'honk-me' ) ), 403 );
		}
		$server = isset( $_POST['server_url'] ) ? untrailingslashit( esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['server_url'] ) ) ), array( 'https', 'http' ) ) ) : '';
		$key    = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		$server = '' !== $server ? $server : Honk_Settings::server_url();
		$key    = '' !== $key ? $key : Honk_Settings::api_key();

		if ( ! Honk_Settings::is_valid_server_url( $server ) ) {
			wp_send_json_error( array( 'message' => __( 'The server URL must start with https:// (http:// works only for local addresses).', 'honk-me' ) ) );
		}
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Enter your API key first.', 'honk-me' ) ) );
		}

		$fields  = Honk_I18n::with_locale(
			function () {
				return array(
					/* translators: %s: site name */
					'title'   => sprintf( __( 'Test from %s', 'honk-me' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
					'message' => __( 'If you can read this, your site is connected to Honk. Sent from Settings → Honk.', 'honk-me' ),
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
						__( 'It works. The test notification should be in your Honk inbox now (ID %1$s, %2$d ms).', 'honk-me' ),
						$result['id'],
						$result['ms']
					),
				)
			);
		}
		if ( 0 === $result['status'] ) {
			/* translators: 1: server URL, 2: error message */
			$message = sprintf( __( 'Couldn’t reach %1$s: %2$s', 'honk-me' ), $server, $result['message'] );
		} elseif ( 401 === $result['status'] ) {
			$message = __( 'Honk didn’t accept this key. Check that you copied all of it and that it hasn’t been revoked in Honk.', 'honk-me' );
		} else {
			/* translators: 1: HTTP status, 2: error code, 3: error message */
			$message = trim( sprintf( __( 'Honk returned an error (%1$d %2$s): %3$s', 'honk-me' ), $result['status'], $result['code'], $result['message'] ), ' :' );
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
		$tabs['honk'] = __( 'Honk', 'honk-me' );
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
		<h2><?php esc_html_e( 'Honk', 'honk-me' ); ?></h2>
		<p><?php esc_html_e( 'Order, payment, stock, customer and review notifications are set up under Settings → Honk, with the rest of the site’s notifications.', 'honk-me' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Open Honk settings', 'honk-me' ); ?></a></p>
		<?php
	}
}
