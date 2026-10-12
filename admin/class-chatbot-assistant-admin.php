<?php

/**
 * Panel de administración del plugin: el menú "Asistente Chatbot" y el wizard de onboarding.
 *
 * @package    Chatbot_Assistant
 * @subpackage Chatbot_Assistant/admin
 * @author     Quintanilla <adrianq1299@gmail.com>
 */
class Chatbot_Assistant_Admin {

	/**
	 * Slug de la página del panel (también es el slug del plugin).
	 */
	const PAGE_SLUG = 'chatbot-assistant';

	/**
	 * @var string Slug del plugin, usado como handle del script.
	 */
	private $plugin_name;

	/**
	 * @var string Versión del plugin, para invalidar la caché del script.
	 */
	private $version;

	/**
	 * @var string Hook de la página del panel que devuelve add_menu_page (para cargar el script solo ahí).
	 */
	private $page_hook = '';

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Registra el menú de nivel superior del panel.
	 */
	public function register_menu() {
		$this->page_hook = add_menu_page(
			'Asistente Chatbot',
			'Asistente Chatbot',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'display_admin_page' ),
			'dashicons-format-chat'
		);
	}

	public function display_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Resincronizamos con el Backend antes de leer el estado local, para no
		// depender únicamente de los webhooks entrantes.
		$store = get_option( 'chatbot_provision' );
		if ( ! empty( $store['tenant_id'] ) ) {
			Chatbot_Assistant_Provision::sync_status_from_backend( $store['tenant_id'] );
		}
		// Leemos el estado local (ya resincronizado) antes de cargar la vista.
		$store = get_option( 'chatbot_provision' );

		// La parte técnica (estado crudo, bitácora, comandos de WP-CLI) es para quien da soporte, no
		// para el dueño de la tienda: solo con CHATBOT_DEBUG en wp-config.php. Aun así, las
		// credenciales (api_key_hash, consumerKey, state_token) salen como ***.
		$debug       = self::debug_enabled();
		$store_debug = $debug ? Chatbot_Assistant_Backend_Client::mask_secrets( (array) $store ) : array();
		$audit       = $debug ? Chatbot_Assistant_Backend_Client::mask_secrets( Chatbot_Assistant_Provision::get_audit_log() ) : array();

		require_once plugin_dir_path( __FILE__ ) . 'partials/chatbot-assistant-admin-display.php';
	}

	/**
	 * Modo soporte del panel: `define( 'CHATBOT_DEBUG', true );` en wp-config.php. Es una constante y
	 * no un usuario porque en la tienda de un cliente el administrador es el cliente.
	 */
	public static function debug_enabled() {
		return defined( 'CHATBOT_DEBUG' ) && CHATBOT_DEBUG;
	}

	/**
	 * Carga el script del wizard solo en la página del panel, con la URL de las rutas REST del
	 * plugin y el nonce. `rest_url()` respeta instalaciones en subcarpeta y enlaces "Simple".
	 *
	 * @param string $hook_suffix Pantalla actual de wp-admin.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/chatbot-assistant-admin.js', array(), $this->version, true );

		wp_localize_script( $this->plugin_name, 'chatbotAdmin', array(
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'restUrl' => rest_url( 'chatbot/v1/' ),
		) );
	}

}
