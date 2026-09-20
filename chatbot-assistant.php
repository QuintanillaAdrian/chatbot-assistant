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
 * Version:           1.0.0
 * Author:            Quintanilla
 * Author URI:        https://portafolio-adrianquintanilla.vercel.app/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       chatbot-assistant
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'CHATBOT_ASSISTANT_VERSION', '1.0.0' );

function activate_chatbot_assistant() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-chatbot-assistant-activator.php';
	Chatbot_Assistant_Activator::activate();
}

function deactivate_chatbot_assistant() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-chatbot-assistant-deactivator.php';
	Chatbot_Assistant_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_chatbot_assistant' );
register_deactivation_hook( __FILE__, 'deactivate_chatbot_assistant' );

require plugin_dir_path( __FILE__ ) . 'includes/class-chatbot-assistant.php';

function run_chatbot_assistant() {
	$plugin = new Chatbot_Assistant();
	$plugin->run();
}
run_chatbot_assistant();
