<?php

/**
 * Limpieza al desinstalar el plugin (wp-admin → Plugins → Borrar).
 *
 * Borra lo que el plugin dejó en WordPress (2026-10-08; antes este archivo era la plantilla vacía
 * del boilerplate y todo quedaba en la base):
 * - La clave de la API de WooCommerce que creó el onboarding. Es de lectura/escritura: dejarla viva
 *   sería una credencial activa de un plugin que ya no está.
 * - Sus opciones (`chatbot_provision`, la bitácora y la API key ante el Backend).
 * - Restos de versiones anteriores: la tabla `{prefix}chatbot_tenants` y las opciones
 *   `chatbot_tenant_id`/`chatbot_version`, que creaba el activador y nada leía.
 *
 * - Desde la 1.0.4 (M8), primero da de baja la tienda en el Backend: suelta sus canales (el número de
 *   WhatsApp queda libre) y borra sus datos. Antes no lo hacía, y al reinstalar el plugin se
 *   registraba una tienda nueva mientras la vieja retenía el número (Socagru, tres veces).
 *
 * Borrar el plugin es dar de baja la tienda: para actualizarlo se usa `git pull` + `composer install`.
 *
 * @package    Chatbot_Assistant
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// WordPress no carga el plugin al desinstalarlo: se traen solo las clases que hacen falta acá.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-chatbot-assistant-provision.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-backend-client.php';

$chatbot_store = get_option( 'chatbot_provision', array() );

// La baja va ANTES de borrar las opciones: el pedido se autentica con la API key guardada en
// `chatbot_api_key_plain`. Si falla, queda en el log de PHP y la desinstalación sigue igual.
if ( ! empty( $chatbot_store['tenant_id'] ) ) {
	Chatbot_Assistant_Backend_Client::delete_tenant( $chatbot_store['tenant_id'] );
}
Chatbot_Assistant_Provision::delete_wc_key( $chatbot_store['consumerKey'] ?? '' );

foreach ( array( 'chatbot_provision', 'chatbot_provision_audit', 'chatbot_api_key_plain', 'chatbot_tenant_id', 'chatbot_version' ) as $chatbot_option ) {
	delete_option( $chatbot_option );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}chatbot_tenants" );
