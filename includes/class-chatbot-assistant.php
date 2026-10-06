<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://https://portafolio-adrianquintanilla.vercel.app/
 * @since      1.0.0
 *
 * @package    Chatbot_Assistant
 * @subpackage Chatbot_Assistant/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Chatbot_Assistant
 * @subpackage Chatbot_Assistant/includes
 * @author     Quintanilla <adrianq1299@gmail.com>
 */
class Chatbot_Assistant {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Chatbot_Assistant_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		// Inicialización mínima: versión + slug del plugin.
		if ( defined( 'CHATBOT_ASSISTANT_VERSION' ) ) {
			$this->version = CHATBOT_ASSISTANT_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'chatbot-assistant';

		// Orden importante: primero cargamos clases, luego locale y por último hooks.
		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Chatbot_Assistant_Loader. Orchestrates the hooks of the plugin.
	 * - Chatbot_Assistant_i18n. Defines internationalization functionality.
	 * - Chatbot_Assistant_Admin. Defines all hooks for the admin area.
	 * - Chatbot_Assistant_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-chatbot-assistant-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-chatbot-assistant-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-chatbot-assistant-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-chatbot-assistant-public.php';

		// Librería hashids (Composer) — arma/desarma los links cortos de
		// checkout (`/ir/{token}`, ver Chatbot_Assistant_Public::
		// maybe_expand_short_checkout_link). `file_exists` por las dudas de
		// que alguien copie el plugin sin correr antes `composer install`
		// — sin el autoload, esos links cortos simplemente no se expanden
		// (el resto del plugin sigue funcionando normal).
		$hashids_autoload = plugin_dir_path( dirname( __FILE__ ) ) . 'vendor/autoload.php';
		if ( file_exists( $hashids_autoload ) ) {
			require_once $hashids_autoload;
		}

		// Provisioning and Backend client
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-backend-client.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-chatbot-assistant-provision.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-chatbot-assistant-cli.php';

		// Avisos de pedido hacia el Backend (2026-10-05) — ver la clase para el porqué.
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-chatbot-order-events.php';

		// El loader es quien finalmente registra add_action/add_filter en WordPress.
		$this->loader = new Chatbot_Assistant_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Chatbot_Assistant_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Chatbot_Assistant_i18n();

		// Cargamos traducciones cuando WordPress ya terminó de cargar plugins.
		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Chatbot_Assistant_Admin( $this->get_plugin_name(), $this->get_version() );

		// Todo lo del panel admin (assets, menú y acción AJAX de reenvío).
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'register_menu' );
		$this->loader->add_action( 'wp_ajax_chatbot_resend_provision', $plugin_admin, 'ajax_resend_provision' );
		// AJAX handler for updating keys from admin UI
		$this->loader->add_action( 'wp_ajax_chatbot_update_keys', $plugin_admin, 'ajax_update_keys' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Chatbot_Assistant_Public( $this->get_plugin_name(), $this->get_version() );

		// Hooks del front y del flujo automático de provisioning.
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
		$this->loader->add_action( 'init', 'Chatbot_Assistant_Provision', 'maybe_run_pending_provision' );

		// Desarma el link corto de checkout (`/ir/{token}`, ver
		// services/sales.ts en chatbot-backend) y redirige al link largo
		// real — prioridad 1, la más temprana de todas, porque tiene que
		// correr ANTES de que exista cualquier `$_GET['add-to-cart']` que
		// mirar (acá no hay ninguno todavía, el token viene en la URL, no
		// en query params).
		$this->loader->add_action( 'wp_loaded', $plugin_public, 'maybe_expand_short_checkout_link', 1 );

		// Desarma el link corto de LOGIN (`/entrar/{token}`, ver
		// services/sales.ts en chatbot-backend, Fix #3 de "me mostró
		// pedidos que no son mios", 2026-09-10) y loguea de verdad al
		// cliente antes de redirigirlo al link de compra — prioridad 1,
		// misma que la de arriba (rutas mutuamente excluyentes, el orden
		// entre las dos no importa).
		$this->loader->add_action( 'wp_loaded', $plugin_public, 'maybe_handle_login_link', 1 );

		// Rechaza (expiración + firma HMAC) un link de checkout viejo del bot
		// antes de que llegue a los dos hooks de abajo o al add-to-cart
		// nativo de WooCommerce — prioridad 4, antes de la 5 de esos dos.
		$this->loader->add_action( 'wp_loaded', $plugin_public, 'maybe_reject_expired_checkout_link', 4 );

		// Vacía el carrito antes del add-to-cart nativo de WooCommerce cuando
		// el link del bot trae `limpiar_carrito` (ver services/sales.ts en
		// chatbot-backend) — prioridad 5, antes de la 20 que usa WooCommerce.
		$this->loader->add_action( 'wp_loaded', $plugin_public, 'maybe_clear_cart_before_add', 5 );

		// Fija (no suma) la cantidad de un producto puntual antes del
		// add-to-cart nativo, cuando el link del bot trae `reemplazar_item`
		// (siempre lo trae — ver services/sales.ts) — evita que abrir el
		// mismo link dos veces duplique la cantidad pedida.
		$this->loader->add_action( 'wp_loaded', $plugin_public, 'maybe_replace_product_qty_before_add', 5 );

		// Compra en un solo link (2026-10-03): `/carrito/{token}` agrega TODOS los
		// productos de la compra en una sola petición (ver maybe_add_cart_from_link).
		$this->loader->add_action( 'wp_loaded', $plugin_public, 'maybe_add_cart_from_link', 5 );

		// Prellena el campo de teléfono del checkout con el que el bot ya
		// tiene cacheado para esta conversación (`billing_phone` en la URL
		// — ver services/sales.ts en chatbot-backend, Fix #2 de "me mostró
		// pedidos que no son mios", 2026-09-10) — prioridad 6, no depende
		// del orden con los hooks de arriba (no toca el carrito).
		$this->loader->add_action( 'wp_loaded', $plugin_public, 'maybe_prefill_billing_phone', 6 );

		// Apenas existe un pedido, se le avisa al backend para que deje de considerar
		// pendiente el carrito que venía rastreando de esa conversación (2026-10-05).
		//
		// Se enganchan TRES momentos porque ninguno solo alcanza, y la clase se asegura de
		// avisar una sola vez por pedido (ver NOTIFIED_META), así que sobran sin costo:
		//
		//   1. `woocommerce_store_api_checkout_order_processed` — el checkout de BLOQUES
		//      (Store API), que es el que trae WooCommerce por defecto desde hace varias
		//      versiones y el que usa esta tienda. OJO: pasa el OBJETO del pedido, no el id
		//      (los otros dos pasan el id) — `wc_get_order()` acepta los dos, ver
		//      WC_Order_Factory::get_order_id.
		//   2. `woocommerce_checkout_order_processed` — el checkout CLÁSICO (shortcode).
		//      Lo dispara únicamente class-wc-checkout.php; el de bloques NO lo dispara.
		//   3. `woocommerce_payment_complete` — un pedido que se paga más tarde o por otra vía.
		//
		// Por qué los tres, aprendido de un fallo real (2026-10-06, pedido #91): al principio
		// se engancharon solo el 2 y el 3, razonando que entre "checkout terminado" y "pago
		// confirmado" quedaba todo cubierto. No quedaba: esta tienda usa el checkout de
		// bloques (así que el 2 nunca se dispara) y el cliente pagó contra entrega, que no
		// llama `payment_complete()` sino `update_status()` directo (así que el 3 tampoco).
		// Resultado: una compra real completada sin que el backend se enterara nunca. La
		// combinación "checkout de bloques + contra entrega" no es un caso raro — es el
		// camino por defecto de WooCommerce.
		$this->loader->add_action( 'woocommerce_store_api_checkout_order_processed', 'Chatbot_Order_Events', 'notify_order_created' );
		$this->loader->add_action( 'woocommerce_checkout_order_processed', 'Chatbot_Order_Events', 'notify_order_created' );
		$this->loader->add_action( 'woocommerce_payment_complete', 'Chatbot_Order_Events', 'notify_order_created' );

		// register REST routes for provisioning
		$this->loader->add_action( 'rest_api_init', 'Chatbot_Assistant_Provision', 'register_routes' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		// Este método dispara el registro real de todos los hooks acumulados.
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Chatbot_Assistant_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
