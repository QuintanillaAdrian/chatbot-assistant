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
 * No da de baja la tienda en el Backend: eso es del lado del Backend.
 *
 * @package    Chatbot_Assistant
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// WordPress no carga el plugin al desinstalarlo: se trae solo la clase que sabe borrar la clave.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-chatbot-assistant-provision.php';

$chatbot_store = get_option( 'chatbot_provision', array() );
Chatbot_Assistant_Provision::delete_wc_key( $chatbot_store['consumerKey'] ?? '' );

foreach ( array( 'chatbot_provision', 'chatbot_provision_audit', 'chatbot_api_key_plain', 'chatbot_tenant_id', 'chatbot_version' ) as $chatbot_option ) {
	delete_option( $chatbot_option );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}chatbot_tenants" );
