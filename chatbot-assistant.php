<?php

/**
 * The plugin bootstrap file
 *
 * @link              https://portafolio-adrianquintanilla.vercel.app/
 * @since             1.0.0
 * @package           Chatbot_Assistant
 *
 * @wordpress-plugin
 * Plugin Name:       Chatbot Assistant
 * Plugin URI:        https://github.com/QuintanillaAdrian/plugintest.git
 * Description:       Asistente de chatbot para WooCommerce via WhatsApp Business.
 * Version:           1.0.3
 * Author:            Quintanilla
 * Author URI:        https://portafolio-adrianquintanilla.vercel.app/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       chatbot-assistant
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Va en el `?ver=` del JS del panel: subirla cada vez que cambie ese archivo, o el navegador sigue
// usando la copia vieja de su caché (pasó con 1.0.0 → 1.0.1).
define( 'CHATBOT_ASSISTANT_VERSION', '1.0.3' );

// Sin hooks de activación/desactivación (2026-10-08): el onboarding arranca solo la primera vez
// que un admin abre wp-admin (Chatbot_Assistant_Provision::maybe_run_pending_provision), que
// también genera la API key. La limpieza al desinstalar está en uninstall.php.

require plugin_dir_path( __FILE__ ) . 'includes/class-chatbot-assistant.php';

function run_chatbot_assistant() {
	$plugin = new Chatbot_Assistant();
	$plugin->run();
}
run_chatbot_assistant();
