<?php
/**
 * Settings screen: Settings → DashLight.
 *
 * @package DashLight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and renders the plugin's few settings.
 */
class DashLight_Settings {

	const OPTION = 'dashlight_settings';
	const PAGE   = 'dashlight';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'after_save' ), 10, 2 );
		add_action( 'admin_post_dashlight_check_now', array( __CLASS__, 'handle_check_now' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DASHLIGHT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'recipient'   => '',
			'reminders'   => 1,
			'domain_date' => '',
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Where reminder emails go.
	 *
	 * @return string
	 */
	public static function recipient() {
		$settings = self::get();
		return is_email( $settings['recipient'] ) ? $settings['recipient'] : get_option( 'admin_email' );
	}

	/**
	 * Adds the page under Settings.
	 */
	public static function add_page() {
		add_options_page(
			__( 'DashLight', 'dashlight' ),
			__( 'DashLight', 'dashlight' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'dashlight' ) . '</a>' );
		return $links;
	}

	/**
	 * Registers the setting and its fields.
	 */
	public static function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section( 'dashlight_main', '', '__return_false', self::PAGE );

		add_settings_field( 'dashlight_recipient', __( 'Send reminders to', 'dashlight' ), array( __CLASS__, 'field_recipient' ), self::PAGE, 'dashlight_main', array( 'label_for' => 'dashlight_recipient' ) );
		add_settings_field( 'dashlight_reminders', __( 'Reminder emails', 'dashlight' ), array( __CLASS__, 'field_reminders' ), self::PAGE, 'dashlight_main', array( 'label_for' => 'dashlight_reminders' ) );
		add_settings_field( 'dashlight_domain_date', __( 'Domain renewal date (optional)', 'dashlight' ), array( __CLASS__, 'field_domain_date' ), self::PAGE, 'dashlight_main', array( 'label_for' => 'dashlight_domain_date' ) );
	}

	/**
	 * Cleans submitted values.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = self::defaults();

		$email = isset( $input['recipient'] ) ? sanitize_email( $input['recipient'] ) : '';
		if ( '' !== $email && ! is_email( $email ) ) {
			add_settings_error( self::OPTION, 'dashlight_bad_email', __( "That email address doesn't look right, so reminders will go to the site's admin email.", 'dashlight' ) );
			$email = '';
		}
		$clean['recipient'] = $email;

		$clean['reminders'] = empty( $input['reminders'] ) ? 0 : 1;

		$date = isset( $input['domain_date'] ) ? sanitize_text_field( $input['domain_date'] ) : '';
		if ( '' !== $date ) {
			$parsed = DateTime::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
			if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
				add_settings_error( self::OPTION, 'dashlight_bad_date', __( "That renewal date doesn't look right, so it wasn't saved.", 'dashlight' ) );
				$date = '';
			}
		}
		$clean['domain_date'] = $date;

		return $clean;
	}

	/**
	 * Re-checks the domain after settings change, so a new manual date shows right away.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $new_value New value.
	 */
	public static function after_save( $old_value, $new_value ) {
		$old_date = is_array( $old_value ) && isset( $old_value['domain_date'] ) ? $old_value['domain_date'] : '';
		$new_date = is_array( $new_value ) && isset( $new_value['domain_date'] ) ? $new_value['domain_date'] : '';
		if ( $old_date !== $new_date ) {
			DashLight_Checks::refresh( array( 'domain' ) );
		}
	}

	/**
	 * Runs every check when someone clicks "Check now".
	 */
	public static function handle_check_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'dashlight' ), 403 );
		}
		check_admin_referer( 'dashlight_check_now' );

		DashLight_Checks::refresh();

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * The "Check now" button, as its own small form.
	 *
	 * @param string $button_class Button class.
	 */
	public static function check_now_button( $button_class = 'button' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dashlight-check-now">
			<input type="hidden" name="action" value="dashlight_check_now" />
			<?php wp_nonce_field( 'dashlight_check_now' ); ?>
			<button type="submit" class="<?php echo esc_attr( $button_class ); ?>"><?php esc_html_e( 'Check now', 'dashlight' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Recipient field.
	 */
	public static function field_recipient() {
		$settings = self::get();
		printf(
			'<input type="email" id="dashlight_recipient" name="%1$s[recipient]" value="%2$s" class="regular-text" placeholder="%3$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $settings['recipient'] ),
			esc_attr( get_option( 'admin_email' ) )
		);
		echo '<p class="description">' . esc_html__( "Leave blank to use the site's admin email.", 'dashlight' ) . '</p>';
	}

	/**
	 * Reminders on/off field.
	 */
	public static function field_reminders() {
		$settings = self::get();
		printf(
			'<label><input type="checkbox" id="dashlight_reminders" name="%1$s[reminders]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( 1, (int) $settings['reminders'], false ),
			esc_html__( 'Email me before my domain or security certificate expires', 'dashlight' )
		);
	}

	/**
	 * Manual domain date field.
	 */
	public static function field_domain_date() {
		$settings = self::get();
		printf(
			'<input type="date" id="dashlight_domain_date" name="%1$s[domain_date]" value="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $settings['domain_date'] )
		);
		echo '<p class="description">' . esc_html__( "Only needed if DashLight can't find it on its own. Find this in your domain registrar account.", 'dashlight' ) . '</p>';
	}

	/**
	 * Renders the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap dashlight-settings">
			<h1><?php esc_html_e( 'DashLight', 'dashlight' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
			<hr />
			<p><?php esc_html_e( 'DashLight checks your site once a day. Run the checks again now:', 'dashlight' ); ?></p>
			<?php self::check_now_button( 'button button-secondary' ); ?>
		</div>
		<?php
	}
}
