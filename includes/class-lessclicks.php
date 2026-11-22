<?php
/**
 * Main plugin class
 */
class Lessclicks {

	/**
	 * Singleton instance
	 */
	private static $instance = null;

	/**
	 * Option name for settings
	 */
	const OPTION_NAME = 'lessclicks_settings';

	/**
	 * Option name used before the plugin was renamed to Lessclicks.
	 */
	const LEGACY_OPTION_NAME = 'dashlytics_settings';

	/**
	 * Singleton pattern
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize all hooks
	 */
	private function init_hooks() {
		// Admin hooks
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_review_notice' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );

		// REST API
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_ajax_lessclicks_review_snooze', array( $this, 'ajax_review_snooze' ) );

		// Plugin activation/deactivation
		register_activation_hook( LESSCLICKS_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( LESSCLICKS_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// Settings link on the Plugins list
		add_filter( 'plugin_action_links_' . LESSCLICKS_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );

		// Review notice dismiss/snooze handler
		add_action( 'admin_init', array( $this, 'snooze_review_notice' ) );
	}

	/**
	 * Load textdomain for translations
	 *
	 * WordPress 4.6+ auto-loads translations from the Text Domain header, so this
	 * method is kept as a no-op hook callback for backward compatibility.
	 */
	public function load_textdomain() {
		// No manual loading required.
	}

	/**
	 * Plugin activation
	 */
	public function activate() {
		// Set default options
		$default_options = array(
			'matomo_url'         => '',
			'site_id'            => 1,
			'token_auth'         => '',
			'chart_type'         => 'line',
			'chart_color'        => '#2271b1',
			'date_range'         => 30,
			'auto_detect_matomo' => true,
		);

		if ( ! get_option( self::OPTION_NAME ) ) {
			add_option( self::OPTION_NAME, $default_options );
		}

		// Add capabilities
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'manage_lessclicks' );
		}

		flush_rewrite_rules();

		// Reset review notice timer (show again in 14 days)
		delete_option( 'lessclicks_review_dismissed' );
		update_option( 'lessclicks_review_next', time() + ( 14 * DAY_IN_SECONDS ) );
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Add settings link to the plugins list
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=lessclicks' ) . '">' .
						__( 'Settings', 'lessclicks-analytics-for-matomo' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Lessclicks Analytics', 'lessclicks-analytics-for-matomo' ),
			__( 'Lessclicks', 'lessclicks-analytics-for-matomo' ),
			'manage_options',
			'lessclicks',
			array( $this, 'render_settings_page' ),
			LESSCLICKS_PLUGIN_URL . 'assets/images/lessclicks-icon.svg',
			80
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Settings page
		if ( 'toplevel_page_lessclicks' === $hook ) {
			wp_enqueue_style(
				'lessclicks-admin',
				LESSCLICKS_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				LESSCLICKS_VERSION
			);

			wp_enqueue_style(
				'lessclicks-settings-bundle',
				LESSCLICKS_PLUGIN_URL . 'app/public/build/bundle.css',
				array( 'lessclicks-admin' ),
				LESSCLICKS_VERSION
			);

			wp_enqueue_script(
				'lessclicks-settings',
				LESSCLICKS_PLUGIN_URL . 'app/public/build/settings.js',
				array(),
				LESSCLICKS_VERSION,
				true
			);

			wp_localize_script(
				'lessclicks-settings',
				'lessclicksAdmin',
				array(
					'restUrl'        => rest_url( 'lessclicks/v1/' ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'pluginUrl'      => LESSCLICKS_PLUGIN_URL,
					'version'        => LESSCLICKS_VERSION,
					'locale'         => get_user_locale(),
					'matomoDetected' => $this->detect_matomo_plugin(),
					'i18n'           => array(
						'tagline'                  => __( 'Matomo widget dashboard for WordPress', 'lessclicks-analytics-for-matomo' ),
						'saveSuccess'              => __( 'Settings saved.', 'lessclicks-analytics-for-matomo' ),
						'saveError'                => __( 'Could not save settings.', 'lessclicks-analytics-for-matomo' ),
						'connectionSuccess'        => __( 'Connection successful.', 'lessclicks-analytics-for-matomo' ),
						'connectionError'          => __( 'Connection failed.', 'lessclicks-analytics-for-matomo' ),
						'tokenGenerated'           => __( 'Token detected automatically.', 'lessclicks-analytics-for-matomo' ),
						'line'                     => __( 'Line chart', 'lessclicks-analytics-for-matomo' ),
						'bar'                      => __( 'Bar chart', 'lessclicks-analytics-for-matomo' ),
						'pie'                      => __( 'Pie chart', 'lessclicks-analytics-for-matomo' ),
						'last7days'                => __( 'Last 7 days', 'lessclicks-analytics-for-matomo' ),
						'last14days'               => __( 'Last 14 days', 'lessclicks-analytics-for-matomo' ),
						'last30days'               => __( 'Last 30 days', 'lessclicks-analytics-for-matomo' ),
						'last60days'               => __( 'Last 60 days', 'lessclicks-analytics-for-matomo' ),
						'last90days'               => __( 'Last 90 days', 'lessclicks-analytics-for-matomo' ),
						'settings'                 => __( 'Settings', 'lessclicks-analytics-for-matomo' ),
						'help'                     => __( 'Help', 'lessclicks-analytics-for-matomo' ),
						'chartSettings'            => __( 'Chart Settings', 'lessclicks-analytics-for-matomo' ),
						'matomoUrl'                => __( 'Matomo URL', 'lessclicks-analytics-for-matomo' ),
						'matomoUrlHint'            => __( 'Your Matomo installation', 'lessclicks-analytics-for-matomo' ),
						'matomoUrlPlaceholder'     => __( 'https://analytics.example.com', 'lessclicks-analytics-for-matomo' ),
						'siteId'                   => __( 'Site ID', 'lessclicks-analytics-for-matomo' ),
						'siteIdHint'               => __( 'Default: 1', 'lessclicks-analytics-for-matomo' ),
						'authToken'                => __( 'Auth token', 'lessclicks-analytics-for-matomo' ),
						'authTokenPlaceholder'     => __( 'Your Matomo API token', 'lessclicks-analytics-for-matomo' ),
						'autoDetected'             => __( 'detected automatically', 'lessclicks-analytics-for-matomo' ),
						'authActive'               => __( 'Authentication active', 'lessclicks-analytics-for-matomo' ),
						'apiAccessToken'           => __( 'API access token', 'lessclicks-analytics-for-matomo' ),
						'hideToken'                => __( 'Hide token', 'lessclicks-analytics-for-matomo' ),
						'showToken'                => __( 'Show token', 'lessclicks-analytics-for-matomo' ),
						'autoDetect'               => __( 'Detect token automatically', 'lessclicks-analytics-for-matomo' ),
						'useMatomoWp'              => __( 'Use Matomo for WordPress', 'lessclicks-analytics-for-matomo' ),
						'chartType'                => __( 'Chart-Type', 'lessclicks-analytics-for-matomo' ),
						'primaryColor'             => __( 'Main color', 'lessclicks-analytics-for-matomo' ),
						'defaultPeriod'            => __( 'Initial Date filter', 'lessclicks-analytics-for-matomo' ),
						'reset'                    => __( 'Reset', 'lessclicks-analytics-for-matomo' ),
						'resetConnectionTest'      => __( 'Reset connection status', 'lessclicks-analytics-for-matomo' ),
						'save'                     => __( 'Save', 'lessclicks-analytics-for-matomo' ),
						'livePreview'              => __( 'Live preview', 'lessclicks-analytics-for-matomo' ),
						'loadingPreview'           => __( 'Loading preview…', 'lessclicks-analytics-for-matomo' ),
						'noPreviewData'            => __( 'No preview data', 'lessclicks-analytics-for-matomo' ),
						'loadingSettings'          => __( 'Loading settings', 'lessclicks-analytics-for-matomo' ),
						'settingsTabs'             => __( 'Settings sections', 'lessclicks-analytics-for-matomo' ),
						'checking'                 => __( 'Checking connection…', 'lessclicks-analytics-for-matomo' ),
						'matomoWp'                 => __( 'Matomo WP', 'lessclicks-analytics-for-matomo' ),
						'connected'                => __( 'Connected', 'lessclicks-analytics-for-matomo' ),
						'notConnected'             => __( 'Not connected', 'lessclicks-analytics-for-matomo' ),
						'configurationRequired'    => __( 'Configuration required', 'lessclicks-analytics-for-matomo' ),
						'autoConnected'            => __( 'WordPress authentication active', 'lessclicks-analytics-for-matomo' ),
						'autoConnectedToken'       => __( 'Connected to Matomo for WordPress automatically (token detected).', 'lessclicks-analytics-for-matomo' ),
						'autoConnectionFailed'     => __( 'Automatic connection failed.', 'lessclicks-analytics-for-matomo' ),
						'noAuthMethod'             => __( 'Could not detect a valid authentication method.', 'lessclicks-analytics-for-matomo' ),
						'connectionErrorManual'    => __( 'Connection failed. Configure Matomo manually.', 'lessclicks-analytics-for-matomo' ),
						'wpAuthUsed'               => __( 'WordPress authentication is used.', 'lessclicks-analytics-for-matomo' ),
						'detectTokenFailed'        => __( 'Token detection failed.', 'lessclicks-analytics-for-matomo' ),
						'mfWNotInstalled'          => __( 'Matomo for WordPress is not installed.', 'lessclicks-analytics-for-matomo' ),
						'previewError'             => __( 'Could not load the preview.', 'lessclicks-analytics-for-matomo' ),
						'visits'                   => __( 'Visits', 'lessclicks-analytics-for-matomo' ),
						'osFamilies'               => __( 'Operating system families', 'lessclicks-analytics-for-matomo' ),
						'quickstart'               => __( 'Quick start', 'lessclicks-analytics-for-matomo' ),
						'connectAutomatically'     => __( 'Connect automatically', 'lessclicks-analytics-for-matomo' ),
						'connectAutomaticallyHelp' => __( 'Matomo for WordPress is detected. Lessclicks connects automatically and adopts the settings.', 'lessclicks-analytics-for-matomo' ),
						'useDashboardWidget'       => __( 'Use the dashboard widget', 'lessclicks-analytics-for-matomo' ),
						'useDashboardWidgetHelp'   => __( 'After connecting, the analytics widget appears on the WordPress dashboard. Adjust the period, chart type and color in the widget.', 'lessclicks-analytics-for-matomo' ),
						'exportReports'            => __( 'Export reports', 'lessclicks-analytics-for-matomo' ),
						'exportReportsHelp'        => __( 'Use the Report button in the widget to export your statistics as a PDF or PNG.', 'lessclicks-analytics-for-matomo' ),
						'installMfW'               => __( 'Install Matomo for WordPress', 'lessclicks-analytics-for-matomo' ),
						'installMfWHelp'           => __( 'For the best integration, install the free Matomo Analytics plugin from the WordPress plugin directory.', 'lessclicks-analytics-for-matomo' ),
						'externalMfW'              => __( 'Alternative: external Matomo', 'lessclicks-analytics-for-matomo' ),
						'externalMfWHelp'          => __( 'If you host Matomo externally, enter the Matomo URL, Site ID and API token under Chart Settings.', 'lessclicks-analytics-for-matomo' ),
						'testConnection'           => __( 'Check the connection', 'lessclicks-analytics-for-matomo' ),
						'testConnectionHelp'       => __( 'Save the settings and check the connection status at the top of this page.', 'lessclicks-analytics-for-matomo' ),
						'widgetFeatures'           => __( 'Widget features', 'lessclicks-analytics-for-matomo' ),
						'statsCards'               => __( 'Stats cards', 'lessclicks-analytics-for-matomo' ),
						'statsCardsDesc'           => __( 'Visitors, page views, bounce rate and session duration at a glance.', 'lessclicks-analytics-for-matomo' ),
						'interactiveCharts'        => __( 'Interactive charts', 'lessclicks-analytics-for-matomo' ),
						'interactiveChartsDesc'    => __( 'Line, bar or pie chart.', 'lessclicks-analytics-for-matomo' ),
						'flexiblePeriod'           => __( 'Flexible period', 'lessclicks-analytics-for-matomo' ),
						'flexiblePeriodDesc'       => __( 'Pick any period with the date picker in the widget.', 'lessclicks-analytics-for-matomo' ),
						'exportReady'              => __( 'PDF & PNG export', 'lessclicks-analytics-for-matomo' ),
						'exportReadyDesc'          => __( 'Download statistics as a PDF report or PNG image.', 'lessclicks-analytics-for-matomo' ),
						'supportTitle'             => __( 'Support Matt Interfaces', 'lessclicks-analytics-for-matomo' ),
						'supportDesc'              => __( 'This plugin is free. A donation or a review helps with further development.', 'lessclicks-analytics-for-matomo' ),
						'pluginWebsite'            => __( 'Plugin website', 'lessclicks-analytics-for-matomo' ),
						'pluginWebsiteAria'        => __( 'Visit the Lessclicks Analytics for Matomo plugin page', 'lessclicks-analytics-for-matomo' ),
						'githubRepository'         => __( 'GitHub repository', 'lessclicks-analytics-for-matomo' ),
						'githubRepositoryAria'     => __( 'Open the Lessclicks GitHub repository', 'lessclicks-analytics-for-matomo' ),
						'matomoApiDocs'            => __( 'Matomo API docs', 'lessclicks-analytics-for-matomo' ),
						'supportDeveloper'         => __( 'Support the developer', 'lessclicks-analytics-for-matomo' ),
					),
				)
			);
		}

		// Load review notice dismiss helper on every admin page
		wp_enqueue_script(
			'lessclicks-admin',
			LESSCLICKS_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			LESSCLICKS_VERSION,
			true
		);

		wp_localize_script(
			'lessclicks-admin',
			'lessclicksAdminData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lessclicks_admin_nonce' ),
			)
		);

		// Dashboard
		if ( 'index.php' === $hook ) {
			wp_enqueue_style(
				'lessclicks-widget',
				LESSCLICKS_PLUGIN_URL . 'assets/css/widget.css',
				array(),
				LESSCLICKS_VERSION
			);

			wp_enqueue_script(
				'lessclicks-widget',
				LESSCLICKS_PLUGIN_URL . 'app/public/build/dashboardwidget.js',
				array(),
				LESSCLICKS_VERSION,
				true
			);

			// Fetch site logo
			$custom_logo_id = get_theme_mod( 'custom_logo' );
			$site_logo      = $custom_logo_id ? wp_get_attachment_image_url( $custom_logo_id, 'medium' ) : '';

			// Fetch favicon (site icon from Customizer)
			$site_icon_id = get_option( 'site_icon' );
			$site_favicon = $site_icon_id ? wp_get_attachment_image_url( $site_icon_id, 'full' ) : '';

			wp_localize_script(
				'lessclicks-widget',
				'lessclicksWidget',
				array(
					'restUrl'     => rest_url( 'lessclicks/v1/' ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'version'     => LESSCLICKS_VERSION,
					'settings'    => $this->get_settings(),
					'siteTitle'   => get_bloginfo( 'name' ),
					'siteUrl'     => home_url(),
					'siteLogo'    => $site_logo,
					'siteFavicon' => $site_favicon,
					'pluginUrl'   => LESSCLICKS_PLUGIN_URL,
					'i18n'        => array(
						'visits'           => __( 'Besuche', 'lessclicks-analytics-for-matomo' ),
						'pageviews'        => __( 'Seitenaufrufe', 'lessclicks-analytics-for-matomo' ),
						'visitors'         => __( 'Besucher', 'lessclicks-analytics-for-matomo' ),
						'bounceRate'       => __( 'Absprungrate', 'lessclicks-analytics-for-matomo' ),
						'avgTime'          => __( 'Ø Verweildauer', 'lessclicks-analytics-for-matomo' ),
						'loading'          => __( 'Lade Daten...', 'lessclicks-analytics-for-matomo' ),
						'noData'           => __( 'Keine Daten verfügbar', 'lessclicks-analytics-for-matomo' ),
						'error'            => __( 'Fehler beim Laden', 'lessclicks-analytics-for-matomo' ),
						'configure'        => __( 'Bitte konfigurieren Sie das Plugin', 'lessclicks-analytics-for-matomo' ),
						'osFamilies'       => __( 'OS-Familien', 'lessclicks-analytics-for-matomo' ),
						'line'             => __( 'Liniendiagramm', 'lessclicks-analytics-for-matomo' ),
						'bar'              => __( 'Balkendiagramm', 'lessclicks-analytics-for-matomo' ),
						'pie'              => __( 'Kreisdiagramm', 'lessclicks-analytics-for-matomo' ),
						'from'             => __( 'Von', 'lessclicks-analytics-for-matomo' ),
						'to'               => __( 'Bis', 'lessclicks-analytics-for-matomo' ),
						'changeColor'      => __( 'Farbe ändern', 'lessclicks-analytics-for-matomo' ),
						'refresh'          => __( 'Aktualisieren', 'lessclicks-analytics-for-matomo' ),
						'ariaRefresh'      => __( 'Statistiken aktualisieren', 'lessclicks-analytics-for-matomo' ),
						'minimize'         => __( 'Minimieren', 'lessclicks-analytics-for-matomo' ),
						'ariaMinimize'     => __( 'Ansicht minimieren', 'lessclicks-analytics-for-matomo' ),
						'expand'           => __( 'Erweitern', 'lessclicks-analytics-for-matomo' ),
						'retry'            => __( 'Erneut versuchen', 'lessclicks-analytics-for-matomo' ),
						'report'           => __( 'Report', 'lessclicks-analytics-for-matomo' ),
						'pdfReport'        => __( 'PDF Report', 'lessclicks-analytics-for-matomo' ),
						'fullReport'       => __( 'Vollständiger Bericht', 'lessclicks-analytics-for-matomo' ),
						'pngImage'         => __( 'PNG Bild', 'lessclicks-analytics-for-matomo' ),
						'chartOnly'        => __( 'Nur das Diagramm', 'lessclicks-analytics-for-matomo' ),
						'statistics'       => __( 'BESUCHERSTATISTIK', 'lessclicks-analytics-for-matomo' ),
						'period'           => __( 'Analysezeitraum', 'lessclicks-analytics-for-matomo' ),
						'avgPerDay'        => __( 'Ø Besuche/Tag', 'lessclicks-analytics-for-matomo' ),
						'actionsPerVisit'  => __( 'Aktionen/Besuch', 'lessclicks-analytics-for-matomo' ),
						'bestDay'          => __( 'Bester Tag', 'lessclicks-analytics-for-matomo' ),
						'days'             => __( 'Tage', 'lessclicks-analytics-for-matomo' ),
						'overview'         => __( 'ÜBERSICHT', 'lessclicks-analytics-for-matomo' ),
						'detailAnalysis'   => __( 'DETAILANALYSE', 'lessclicks-analytics-for-matomo' ),
						'analyticsLabel'   => __( 'ANALYTICS', 'lessclicks-analytics-for-matomo' ),
						'reportLabel'      => __( 'REPORT', 'lessclicks-analytics-for-matomo' ),
						'generatedOn'      => __( 'Generiert am', 'lessclicks-analytics-for-matomo' ),
						'poweredBy'        => __( 'Powered by Lessclicks', 'lessclicks-analytics-for-matomo' ),
						'poweredByCompany' => __( 'Powered by', 'lessclicks-analytics-for-matomo' ),
						'pdfError'         => __( 'Fehler beim Erstellen des PDF-Reports', 'lessclicks-analytics-for-matomo' ),
						'pngError'         => __( 'Fehler beim Erstellen des PNG-Bildes', 'lessclicks-analytics-for-matomo' ),
						'chartPreparing'   => __( 'Chart wird aufbereitet...', 'lessclicks-analytics-for-matomo' ),
					),
				)
			);
		}
	}

	/**
	 * Check whether the Matomo plugin is installed
	 */
	private function detect_matomo_plugin() {
		// Load plugin functions if not yet available
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check for the Matomo for WordPress plugin
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'matomo/matomo.php' ) ) {
			return array(
				'installed'    => true,
				'type'         => 'matomo-for-wordpress',
				'restEndpoint' => home_url( '/?rest_route=/matomo/v1/api/' ),
			);
		}

		// Check for WP-Matomo integration
		if ( class_exists( 'WpMatomo' ) ) {
			return array(
				'installed'    => true,
				'type'         => 'wp-matomo',
				'restEndpoint' => home_url( '/?rest_route=/matomo/v1/api/' ),
			);
		}

		return array(
			'installed'    => false,
			'type'         => null,
			'restEndpoint' => null,
		);
	}

	/**
	 * Settings abrufen
	 */
	public function get_settings() {
		$defaults = array(
			'matomo_url'         => '',
			'site_id'            => 1,
			'token_auth'         => '',
			'chart_type'         => 'line',
			'chart_color'        => '#2271b1',
			'date_range'         => 30,
			'auto_detect_matomo' => true,
		);

		$settings = get_option( self::OPTION_NAME );

		// Carry over settings saved under the previous plugin name.
		if ( false === $settings ) {
			$legacy = get_option( self::LEGACY_OPTION_NAME );
			if ( false !== $legacy ) {
				$settings = $legacy;
				update_option( self::OPTION_NAME, $legacy );
			}
		}

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * REST API Routen registrieren
	 */
	public function register_rest_routes() {
		// Settings Route
		register_rest_route(
			'lessclicks/v1',
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_save_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// Analytics Data Route
		register_rest_route(
			'lessclicks/v1',
			'/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_get_analytics' ),
				'permission_callback' => array( $this, 'check_view_permission' ),
				'args'                => array(
					'period' => array(
						'default'           => 'day',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'date'   => array(
						'default'           => 'last30',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Device/OS data for pie charts
		register_rest_route(
			'lessclicks/v1',
			'/devices',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_get_devices' ),
				'permission_callback' => array( $this, 'check_view_permission' ),
				'args'                => array(
					'date' => array(
						'default'           => 'last30',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Connection Test Route
		register_rest_route(
			'lessclicks/v1',
			'/test-connection',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_test_connection' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Auto-detect token route
		register_rest_route(
			'lessclicks/v1',
			'/detect-token',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_detect_token' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Permission check for admins
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission check for dashboard view.
	 *
	 * Analytics data is only exposed to users who can manage the plugin.
	 */
	public function check_view_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * REST: Settings abrufen
	 */
	public function rest_get_settings( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$settings = $this->get_settings();

		// Return masked token (for admin display)
		$settings['token_auth_set'] = ! empty( $settings['token_auth'] );
		// Token is preserved for masked display

		return rest_ensure_response( $settings );
	}

	/**
	 * REST: Settings speichern
	 */
	public function rest_save_settings( $request ) {
		$params   = $request->get_json_params();
		$settings = $this->get_settings();

		// Sanitize and validate
		if ( isset( $params['matomo_url'] ) ) {
			$settings['matomo_url'] = esc_url_raw( $params['matomo_url'] );
		}

		if ( isset( $params['site_id'] ) ) {
			$settings['site_id'] = absint( $params['site_id'] );
		}

		if ( isset( $params['token_auth'] ) ) {
			$settings['token_auth'] = sanitize_text_field( $params['token_auth'] );
		}

		if ( isset( $params['chart_type'] ) ) {
			$allowed_types = array( 'line', 'bar', 'pie', 'doughnut' );
			if ( in_array( $params['chart_type'], $allowed_types, true ) ) {
				$settings['chart_type'] = $params['chart_type'];
			}
		}

		if ( isset( $params['chart_color'] ) ) {
			$settings['chart_color'] = sanitize_hex_color( $params['chart_color'] );
		}

		if ( isset( $params['date_range'] ) ) {
			$settings['date_range'] = absint( $params['date_range'] );
		}

		if ( isset( $params['auto_detect_matomo'] ) ) {
			$settings['auto_detect_matomo'] = (bool) $params['auto_detect_matomo'];
		}

		update_option( self::OPTION_NAME, $settings );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Settings saved.', 'lessclicks-analytics-for-matomo' ),
			)
		);
	}

	/**
	 * REST: Analytics Daten abrufen
	 */
	public function rest_get_analytics( $request ) {
		$settings = $this->get_settings();

		if ( empty( $settings['matomo_url'] ) && empty( $settings['token_auth'] ) ) {
			// Try using Matomo for WordPress
			$matomo = $this->detect_matomo_plugin();
			if ( $matomo['installed'] ) {
				return $this->get_matomo_wp_analytics( $request );
			}

			return new WP_Error(
				'not_configured',
				__( 'Lessclicks is not configured yet.', 'lessclicks-analytics-for-matomo' ),
				array( 'status' => 400 )
			);
		}

		$period = $request->get_param( 'period' );
		$date   = $request->get_param( 'date' );

		// Baue Matomo API URL
		$api_url = trailingslashit( $settings['matomo_url'] ) . 'index.php';

		// For comma-separated periods (e.g. "2025-11-06,2025-12-06") use range
		$use_range = strpos( $date, ',' ) !== false;

		$query_args = array(
			'module'     => 'API',
			'method'     => 'VisitsSummary.get',
			'idSite'     => $settings['site_id'],
			'period'     => $use_range ? 'day' : $period,
			'date'       => $use_range ? str_replace( ',', ',', $date ) : $date,
			'format'     => 'JSON',
			'token_auth' => $settings['token_auth'],
		);

		$response = wp_remote_get(
			add_query_arg( $query_args, $api_url ),
			array(
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'api_error',
				$response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['result'] ) && 'error' === $data['result'] ) {
			return new WP_Error(
				'matomo_error',
				$data['message'],
				array( 'status' => 400 )
			);
		}

		// When we have daily data, format it for the chart
		if ( $use_range && is_array( $data ) && ! isset( $data['nb_visits'] ) ) {
			// Matomo returns an object with date as key
			// Convert to array with date field
			$formatted = array();
			foreach ( $data as $date_key => $day_data ) {
				if ( is_array( $day_data ) ) {
					$day_data['date'] = $date_key;
					$formatted[]      = $day_data;
				}
			}
			return rest_ensure_response( $formatted );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Analytics von Matomo for WordPress Plugin holen
	 */
	private function get_matomo_wp_analytics( $request ) {
		$date = $request->get_param( 'date' );

		// For comma-separated periods (e.g. "2025-11-06,2025-12-06") use range
		$use_range = strpos( $date, ',' ) !== false;

		// Use the Matomo API directly
		if ( class_exists( '\WpMatomo\Site' ) && class_exists( '\WpMatomo\Bootstrap' ) ) {
			try {
				$site    = new \WpMatomo\Site();
				$site_id = $site->get_current_matomo_site_id();

				if ( empty( $site_id ) ) {
					return new WP_Error(
						'no_site_id',
						__( 'No Matomo Site ID found.', 'lessclicks-analytics-for-matomo' ),
						array( 'status' => 400 )
					);
				}

				// Bootstrap Matomo
				\WpMatomo\Bootstrap::do_bootstrap();

				// API parameters for direct Matomo API
				$api_params = array(
					'idSite' => $site_id,
					'period' => $use_range ? 'day' : 'range',
					'date'   => $date,
				);

				// Matomo API direkt aufrufen
				$data = \Piwik\API\Request::processRequest( 'VisitsSummary.get', $api_params );

				// Convert DataTable to array
				if ( $data instanceof \Piwik\DataTable\Map ) {
					// Multi-period data (day by day)
					$result = array();
					foreach ( $data->getDataTables() as $label => $table ) {
						$row = $table->getFirstRow();
						if ( $row ) {
							$day_data         = $row->getColumns();
							$day_data['date'] = $label;
							$result[]         = $day_data;
						} else {
							// Leerer Tag
							$result[] = array(
								'date'             => $label,
								'nb_visits'        => 0,
								'nb_uniq_visitors' => 0,
								'nb_pageviews'     => 0,
								'nb_actions'       => 0,
								'bounce_rate'      => 0,
								'avg_time_on_site' => 0,
							);
						}
					}
					return rest_ensure_response( $result );

				} elseif ( $data instanceof \Piwik\DataTable ) {
					// Einzelne Periode
					$row = $data->getFirstRow();
					if ( $row ) {
						return rest_ensure_response( $row->getColumns() );
					}
					return rest_ensure_response(
						array(
							'nb_visits'        => 0,
							'nb_uniq_visitors' => 0,
							'nb_pageviews'     => 0,
							'nb_actions'       => 0,
							'bounce_rate'      => 0,
							'avg_time_on_site' => 0,
						)
					);
				}

				// If already an array
				if ( is_array( $data ) ) {
					return rest_ensure_response( $data );
				}

				return rest_ensure_response( $data );

			} catch ( \Exception $e ) {
				return new WP_Error(
					'matomo_api_error',
					'Matomo API Fehler: ' . $e->getMessage(),
					array( 'status' => 500 )
				);
			}
		}

		// Matomo not available
		return new WP_Error(
			'matomo_not_available',
			__( 'Matomo for WordPress is not configured correctly.', 'lessclicks-analytics-for-matomo' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * REST: fetch device/OS data (for pie charts)
	 */
	public function rest_get_devices( $request ) {
		$settings = $this->get_settings();

		if ( empty( $settings['matomo_url'] ) && empty( $settings['token_auth'] ) ) {
			$matomo = $this->detect_matomo_plugin();
			if ( $matomo['installed'] ) {
				return $this->get_matomo_wp_devices( $request );
			}

			return new WP_Error(
				'not_configured',
				__( 'Lessclicks is not configured yet.', 'lessclicks-analytics-for-matomo' ),
				array( 'status' => 400 )
			);
		}

		return $this->get_remote_devices( $settings, $request );
	}

	/**
	 * Fetch device/OS data from external Matomo
	 */
	private function get_remote_devices( $settings, $request ) {
		$date    = $request->get_param( 'date' );
		$api_url = trailingslashit( $settings['matomo_url'] ) . 'index.php';

		$query_args = array(
			'module'     => 'API',
			'method'     => 'DevicesDetection.getOsFamilies',
			'idSite'     => $settings['site_id'],
			'period'     => ( strpos( $date, ',' ) !== false ) ? 'range' : 'day',
			'date'       => $date,
			'format'     => 'JSON',
			'token_auth' => $settings['token_auth'],
		);

		$response = wp_remote_get(
			add_query_arg( $query_args, $api_url ),
			array(
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'api_error',
				$response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['result'] ) && 'error' === $data['result'] ) {
			return new WP_Error(
				'matomo_error',
				$data['message'],
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $this->format_device_data( $data ) );
	}

	/**
	 * Fetch device/OS data from Matomo for WordPress
	 */
	private function get_matomo_wp_devices( $request ) {
		$date = $request->get_param( 'date' );

		if ( class_exists( '\WpMatomo\Site' ) && class_exists( '\WpMatomo\Bootstrap' ) ) {
			try {
				$site    = new \WpMatomo\Site();
				$site_id = $site->get_current_matomo_site_id();

				if ( empty( $site_id ) ) {
					return new WP_Error(
						'no_site_id',
						__( 'No Matomo Site ID found.', 'lessclicks-analytics-for-matomo' ),
						array( 'status' => 400 )
					);
				}

				\WpMatomo\Bootstrap::do_bootstrap();

				$api_params = array(
					'idSite' => $site_id,
					'period' => ( strpos( $date, ',' ) !== false ) ? 'range' : 'day',
					'date'   => $date,
				);

				$data = \Piwik\API\Request::processRequest( 'DevicesDetection.getOsFamilies', $api_params );
				return rest_ensure_response( $this->format_device_data( $data ) );

			} catch ( \Exception $e ) {
				return new WP_Error(
					'matomo_api_error',
					'Matomo API Fehler: ' . $e->getMessage(),
					array( 'status' => 500 )
				);
			}
		}

		return new WP_Error(
			'matomo_not_available',
			__( 'Matomo for WordPress is not configured correctly.', 'lessclicks-analytics-for-matomo' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Convert device data table into a simple label/value array
	 */
	private function format_device_data( $data ) {
		$result = array();

		if ( $data instanceof \Piwik\DataTable\Map ) {
			foreach ( $data->getDataTables() as $table ) {
				$result = array_merge( $result, $this->format_device_data_table( $table ) );
			}
			return $result;
		}

		if ( $data instanceof \Piwik\DataTable ) {
			return $this->format_device_data_table( $data );
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$label = isset( $row['label'] ) ? $row['label'] : '';
				$value = isset( $row['nb_visits'] ) ? $row['nb_visits'] : ( isset( $row['nb_uniq_visitors'] ) ? $row['nb_uniq_visitors'] : 0 );
				if ( $label ) {
					$result[] = array(
						'label' => $label,
						'value' => (int) $value,
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Einzelne Matomo DataTable in Label/Value-Array umwandeln
	 */
	private function format_device_data_table( $table ) {
		$result = array();
		if ( ! $table instanceof \Piwik\DataTable ) {
			return $result;
		}

		foreach ( $table->getRows() as $row ) {
			$columns = $row->getColumns();
			$label   = isset( $columns['label'] ) ? $columns['label'] : '';
			$value   = isset( $columns['nb_visits'] ) ? $columns['nb_visits'] : ( isset( $columns['nb_uniq_visitors'] ) ? $columns['nb_uniq_visitors'] : 0 );
			if ( $label ) {
				$result[] = array(
					'label' => $label,
					'value' => (int) $value,
				);
			}
		}

		return $result;
	}


	/**
	 * REST: Verbindung testen
	 */
	public function rest_test_connection( $request ) {
		$params     = $request->get_json_params();
		$matomo_url = isset( $params['matomo_url'] ) ? esc_url_raw( $params['matomo_url'] ) : '';
		$token_auth = isset( $params['token_auth'] ) ? sanitize_text_field( $params['token_auth'] ) : '';
		$site_id    = isset( $params['site_id'] ) ? absint( $params['site_id'] ) : 1;
		$auto_wp    = isset( $params['auto_detect_matomo'] ) ? (bool) $params['auto_detect_matomo'] : false;

		// Matomo for WordPress automatisch erkennen.
		if ( $auto_wp ) {
			$matomo = $this->detect_matomo_plugin();
			if ( $matomo['installed'] ) {
				try {
					// Try a single short internal API call to confirm runtime.
					$wp_check = $this->get_matomo_wp_analytics(
						(object) array(
							'get_param' => function ( $key ) use ( $site_id ) {
								$map = array(
									'date'   => 'today',
									'period' => 'day',
								);
								return isset( $map[ $key ] ) ? $map[ $key ] : $site_id;
							},
						)
					);

					if ( ! is_wp_error( $wp_check ) ) {
						return rest_ensure_response(
							array(
								'success' => true,
								'message' => __( 'Connected to Matomo for WordPress.', 'lessclicks-analytics-for-matomo' ),
							)
						);
					}
				} catch ( \Exception $e ) {
					return new WP_Error(
						'matomo_wp_test_failed',
						$e->getMessage(),
						array( 'status' => 500 )
					);
				}
			}
		}

		if ( empty( $matomo_url ) || empty( $token_auth ) ) {
			return new WP_Error(
				'missing_params',
				__( 'URL and token are required.', 'lessclicks-analytics-for-matomo' ),
				array( 'status' => 400 )
			);
		}

		// Test the connection.
		$api_url    = trailingslashit( $matomo_url ) . 'index.php';
		$query_args = array(
			'module'     => 'API',
			'method'     => 'API.getMatomoVersion',
			'format'     => 'JSON',
			'token_auth' => $token_auth,
		);

		$response = wp_remote_get(
			add_query_arg( $query_args, $api_url ),
			array(
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $response->get_error_message(),
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['result'] ) && 'error' === $data['result'] ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $data['message'],
				)
			);
		}

		if ( isset( $data['value'] ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					/* translators: %s: Matomo version number */
					'message' => sprintf( __( 'Connected to Matomo %s', 'lessclicks-analytics-for-matomo' ), $data['value'] ),
					'version' => $data['value'],
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => false,
				'message' => __( 'Unknown response from Matomo.', 'lessclicks-analytics-for-matomo' ),
			)
		);
	}

	/**
	 * REST: auto-detect token (for Matomo for WordPress)
	 */
	public function rest_detect_token( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		// Load plugin functions if not yet available
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check whether Matomo for WordPress is active
		if ( ! function_exists( 'is_plugin_active' ) || ! is_plugin_active( 'matomo/matomo.php' ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Matomo for WordPress is not installed.', 'lessclicks-analytics-for-matomo' ),
				)
			);
		}

		// Try to get token from Matomo settings
		$matomo_settings = get_option( 'matomo-global-settings' );

		if ( $matomo_settings && isset( $matomo_settings['token_auth'] ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'token'   => $matomo_settings['token_auth'],
					'message' => __( 'Token detected automatically.', 'lessclicks-analytics-for-matomo' ),
				)
			);
		}

		// Alternative: generate a new token for the current user
		if ( class_exists( 'WpMatomo\User\Sync' ) ) {
			try {
				$user_sync = new \WpMatomo\User\Sync();
				// Token can be generated here
				return rest_ensure_response(
					array(
						'success'     => true,
						'message'     => __( 'Use the Matomo REST API without a token.', 'lessclicks-analytics-for-matomo' ),
						'use_wp_auth' => true,
					)
				);
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		return rest_ensure_response(
			array(
				'success' => false,
				'message' => __( 'The token could not be detected automatically.', 'lessclicks-analytics-for-matomo' ),
			)
		);
	}

	/**
	 * Add dashboard widget
	 */
	public function add_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'lessclicks_widget',
			__( 'Lessclicks - Website statistics', 'lessclicks-analytics-for-matomo' ),
			array( $this, 'render_dashboard_widget' ),
			null,
			null,
			'normal',
			'high'
		);
	}

	/**
	 * Dashboard Widget rendern
	 */
	public function render_dashboard_widget() {
		$settings = $this->get_settings();
		$matomo   = $this->detect_matomo_plugin();

		if ( empty( $settings['matomo_url'] ) && empty( $settings['token_auth'] ) && ! $matomo['installed'] ) {
			echo '<div class="lessclicks-setup-notice">';
			echo '<p>' . esc_html__( 'Welcome to Lessclicks. Please configure the plugin.', 'lessclicks-analytics-for-matomo' ) . '</p>';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=lessclicks' ) ) . '" class="button button-primary">';
			echo esc_html__( 'Set up now', 'lessclicks-analytics-for-matomo' );
			echo '</a>';
			echo '</div>';
			return;
		}

		echo '<div id="lessclicks-widget"></div>';
	}

	/**
	 * Settings Seite rendern
	 */
	public function render_settings_page() {
		?>
		<div class="wrap lessclicks-wrap">
			<div id="lessclicks-settings"></div>
		</div>
		<?php
	}

	/**
	 * Returns localized review/onboarding texts
	 */
	private function get_promo_texts() {
		$locale = get_locale();
		$is_de  = ( strpos( $locale, 'de_' ) === 0 );

		if ( $is_de ) {
			return array(
				'notice_title'  => 'Gefällt Ihnen Lessclicks?',
				'notice_text'   => 'Dieses Plugin ist zu 100 % kostenlos. Eine Spende oder positive Bewertung hilft uns enorm, es weiterzuentwickeln.',
				'btn_donate'    => '☕ Spenden',
				'btn_rate'      => '⭐ Bewerten',
				'notice_remind' => 'In 14 Tagen erinnern',
				'notice_hide'   => 'Nicht mehr anzeigen',
			);
		}

		return array(
			'notice_title'  => 'Enjoying Lessclicks?',
			'notice_text'   => 'This plugin is 100% free. A donation or positive review helps us keep improving it.',
			'btn_donate'    => '☕ Donate',
			'btn_rate'      => '⭐ Rate it',
			'notice_remind' => 'Remind me in 14 days',
			'notice_hide'   => 'Don\'t show again',
		);
	}

	/**
	 * Show review/donation notice every 14 days
	 */
	public function maybe_show_review_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Do not show on the plugin settings page
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_lessclicks' === $screen->id ) {
			return;
		}

		$dismissed = get_option( 'lessclicks_review_dismissed', false );
		if ( $dismissed ) {
			return;
		}

		$next = get_option( 'lessclicks_review_next', 0 );
		if ( time() < (int) $next ) {
			return;
		}

		$texts       = $this->get_promo_texts();
		$donate      = 'https://matt-interfaces.ch/zahlen';
		$rate        = 'https://wordpress.org/support/plugin/lessclicks-analytics-for-matomo/reviews/#new-post';
		$snooze_url  = wp_nonce_url( add_query_arg( 'lessclicks_review_action', 'snooze' ), 'lessclicks_review_action' );
		$dismiss_url = wp_nonce_url( add_query_arg( 'lessclicks_review_action', 'dismiss' ), 'lessclicks_review_action' );
		?>
		<div class="notice notice-info is-dismissible lessclicks-review-notice">
			<p class="lessclicks-review-notice__title"><strong><?php echo esc_html( $texts['notice_title'] ); ?></strong></p>
			<p><?php echo esc_html( $texts['notice_text'] ); ?></p>
			<p>
				<a href="<?php echo esc_url( $donate ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary"><?php echo esc_html( $texts['btn_donate'] ); ?></a>
				<a href="<?php echo esc_url( $rate ); ?>" target="_blank" rel="noopener noreferrer" class="button"><?php echo esc_html( $texts['btn_rate'] ); ?></a>
				<a href="<?php echo esc_url( $snooze_url ); ?>" class="button button-link"><?php echo esc_html( $texts['notice_remind'] ); ?></a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-link"><?php echo esc_html( $texts['notice_hide'] ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX: Review Notice per WordPress-X-Button snoozen
	 */
	public function ajax_review_snooze() {
		check_ajax_referer( 'lessclicks_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		update_option( 'lessclicks_review_next', time() + ( 14 * DAY_IN_SECONDS ) );
		wp_send_json_success();
	}

	/**
	 * Snooze or permanently close review notice
	 */
	public function snooze_review_notice() {
		if ( ! isset( $_GET['lessclicks_review_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'lessclicks_review_action' );

		$action = sanitize_text_field( wp_unslash( $_GET['lessclicks_review_action'] ) );

		// "Don't show again" clicked -> permanently remove.
		// "Remind me in 14 days" is handled as a link; the X icon uses AJAX.
		if ( 'dismiss' === $action ) {
			update_option( 'lessclicks_review_dismissed', true );
		}

		wp_safe_redirect( remove_query_arg( array( 'lessclicks_review_action', '_wpnonce' ) ) );
		exit;
	}
}
