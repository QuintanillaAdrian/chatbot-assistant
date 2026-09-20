<?php
/**
 * Define the internationalization functionality.
 *
 * @package    Chatbot_Assistant
 * @subpackage Chatbot_Assistant/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Chatbot_Assistant_i18n {

	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'chatbot-assistant',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);
	}
}
