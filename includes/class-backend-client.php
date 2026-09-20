<?php
/**
 * Shopia Backend API Client
 *
 * Wrapper para comunicarse con la API del Backend según la especificación.
 * Implementa los Pasos 1-5 del onboarding.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Chatbot_Assistant_Backend_Client {

	/**
	 * URL base del Backend, leída de la variable de entorno CHATBOT_BACKEND_URL.
	 * Sin barra final (los endpoints ya empiezan con '/'). Sin valor por defecto
	 * a propósito: si falta configurar la variable, se prefiere que las llamadas
	 * al Backend fallen explícitamente en vez de apuntar a una URL adivinada.
	 *
	 * CHATBOT_BACKEND_URL se define SIN el sufijo '/api' (ej. la URL base del
	 * API Gateway: https://xxx.execute-api.us-east-2.amazonaws.com/prod) — el
	 * '/api' se agrega acá una sola vez, para no tener que recordar incluirlo
	 * cada vez que la URL cambie (ej. redeploy, nuevo stage). Si alguien deja
	 * el '/api' puesto en la variable de entorno igual funciona, se normaliza.
	 *
	 * @return string|null Null si la variable de entorno no está configurada.
	 */
	public static function get_backend_url() {
		$url = getenv( 'CHATBOT_BACKEND_URL' );
		if ( false === $url || '' === trim( $url ) ) {
			return null;
		}
		$url = rtrim( trim( $url ), '/' );
		if ( '/api' === substr( $url, -4 ) ) {
			$url = substr( $url, 0, -4 );
		}
		return $url . '/api';
	}

	/**
	 * Send HTTP request to Backend API
	 *
	 * @param string $endpoint   Path relativo (ej: /tenants, /tenants/{id}/wc/validate)
	 * @param string $method     GET, POST, PATCH, DELETE
	 * @param array  $data       Request body (opcional)
	 * @param string $api_key    Bearer token
	 * @return array|WP_Error    wp_remote_* response
	 */
	public static function request( $endpoint, $method = 'GET', $data = array(), $api_key = '' ) {
		$backend_url = self::get_backend_url();
		if ( null === $backend_url ) {
			error_log( '[Chatbot Assistant] Falta configurar la variable de entorno CHATBOT_BACKEND_URL — no se puede contactar al Backend.' );
			return new WP_Error( 'chatbot_missing_backend_url', 'CHATBOT_BACKEND_URL no está configurada.' );
		}

		$headers = array(
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);

		// Si no se pasa api_key, intentar leerla del plugin
		if ( empty( $api_key ) ) {
			$api_key = get_option( 'chatbot_api_key_plain', '' );
		}

		// Agregar Authorization header si tenemos API Key
		if ( ! empty( $api_key ) ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$url  = $backend_url . $endpoint;
		$args = array(
			'headers'   => $headers,
			'timeout'   => 20,
			'sslverify' => true,
			'method'    => $method,
		);

		if ( ! empty( $data ) && in_array( $method, array( 'POST', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		self::debug_log( "-> REQUEST {$method} {$url}", self::mask_secrets( $data ) );

		// Elegir el método correcto de wp_remote_*
		if ( 'POST' === $method ) {
			$response = wp_remote_post( $url, $args );
		} elseif ( 'PATCH' === $method ) {
			$response = wp_remote_request( $url, $args );
		} elseif ( 'DELETE' === $method ) {
			$response = wp_remote_request( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		self::debug_log( "<- RESPONSE {$method} {$url}", $response );

		return $response;
	}

	/**
	 * Escribe en el log de errores de PHP el objeto crudo de request/response
	 * hacia el Backend, para diagnosticar fallos de conexion (timeouts, URL
	 * incorrecta, etc). Enmascara claves sensibles antes de loggear.
	 */
	private static function debug_log( $label, $data ) {
		error_log( '[Chatbot Assistant] ' . $label . ' ' . print_r( $data, true ) );
	}

	/**
	 * Enmascara valores de claves sensibles (secret, token, api_key,
	 * authorization) en un array, recursivamente, antes de loggearlo.
	 */
	private static function mask_secrets( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$masked = array();
		foreach ( $value as $key => $val ) {
			if ( is_string( $key ) && preg_match( '/secret|token|authorization|api_key/i', $key ) ) {
				$masked[ $key ] = '***';
			} else {
				$masked[ $key ] = self::mask_secrets( $val );
			}
		}
		return $masked;
	}

	/**
	 * Parse response body and code
	 */
	public static function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
				'code'    => 500,
				'body'    => null,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'success' => $code >= 200 && $code < 300,
			'code'    => $code,
			'body'    => $body,
		);
	}

	/**
	 * PASO 1: Register tenant in Backend
	 */
	public static function register_tenant( $api_key ) {
		$api_key_hash = hash( 'sha256', $api_key );
		$payload      = array(
			'api_key_hash'   => $api_key_hash,
			'site_url'       => get_home_url(),
			'store_name'     => get_bloginfo( 'name' ),
			'wp_version'     => get_bloginfo( 'version' ),
			'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'plugin_version' => '1.0.0',
		);

		$response = self::request( '/tenants', 'POST', $payload, $api_key );
		$parsed   = self::parse_response( $response );

		if ( ! $parsed['success'] || empty( $parsed['body']['tenant_id'] ) ) {
			return array(
				'success' => false,
				'error'   => $parsed['body']['message'] ?? 'Registro de tenant fallido',
				'code'    => $parsed['code'],
			);
		}

		return array(
			'success'    => true,
			'tenant_id'  => $parsed['body']['tenant_id'],
			'state_token' => $parsed['body']['state_token'] ?? '',
			'status'     => $parsed['body']['status'] ?? 'pending',
		);
	}

	/**
	 * PASO 2: Validate WooCommerce connection
	 */
	public static function validate_wc( $tenant_id, $consumer_key, $consumer_secret, $wc_api_url ) {
		$payload = array(
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
			'wc_api_url'      => $wc_api_url,
		);

		$response = self::request( "/tenants/{$tenant_id}/wc/validate", 'POST', $payload );
		$parsed   = self::parse_response( $response );

		if ( ! $parsed['success'] || empty( $parsed['body']['wc_verified'] ) ) {
			return array(
				'success' => false,
				'error'   => $parsed['body']['message'] ?? 'Validacion de WC fallida',
				'code'    => $parsed['code'],
			);
		}

		return array(
			'success'         => true,
			'wc_verified'     => $parsed['body']['wc_verified'],
			'mcp_registered'  => $parsed['body']['mcp_registered'] ?? false,
			'product_count'   => $parsed['body']['product_count'] ?? 0,
		);
	}

	/**
	 * PASO 3A: Get WhatsApp signup URL
	 */
	public static function get_wa_signup_url( $tenant_id ) {
		$response = self::request( "/tenants/{$tenant_id}/wa/signup-url", 'GET' );
		$parsed   = self::parse_response( $response );

		if ( ! $parsed['success'] || empty( $parsed['body']['signup_url'] ) ) {
			return array(
				'success' => false,
				'error'   => $parsed['body']['message'] ?? 'No se pudo obtener URL de signup',
				'code'    => $parsed['code'],
			);
		}

		return array(
			'success'     => true,
			'signup_url'  => $parsed['body']['signup_url'],
			'state_token' => $parsed['body']['state_token'],
			'expires_in'  => $parsed['body']['expires_in'],
		);
	}

	/**
	 * PASO 3B: Complete WhatsApp setup
	 */
	public static function complete_wa_setup( $tenant_id, $oauth_code, $state_token ) {
		$payload = array(
			'oauth_code'  => $oauth_code,
			'state_token' => $state_token,
		);

		$response = self::request( "/tenants/{$tenant_id}/wa/signup", 'POST', $payload );
		$parsed   = self::parse_response( $response );

		if ( ! $parsed['success'] || empty( $parsed['body']['wa_verified'] ) ) {
			return array(
				'success' => false,
				'error'   => $parsed['body']['message'] ?? 'Setup de WhatsApp fallido',
				'code'    => $parsed['code'],
			);
		}

		return array(
			'success'             => true,
			'wa_verified'         => $parsed['body']['wa_verified'],
			'phone_number'        => $parsed['body']['phone_number'] ?? '',
			'webhook_registered'  => $parsed['body']['webhook_registered'] ?? false,
		);
	}

	/**
	 * PASO 3 (canal alternativo): Connect Telegram bot.
	 * A diferencia de WhatsApp, no hay OAuth — el tenant ya tiene el
	 * bot_token que le dio @BotFather y lo manda directo. El Backend valida
	 * el token contra la API de Telegram y registra el webhook.
	 */
	public static function connect_telegram( $tenant_id, $bot_token ) {
		$response = self::request( "/tenants/{$tenant_id}/telegram/connect", 'POST', array( 'bot_token' => $bot_token ) );
		$parsed   = self::parse_response( $response );

		if ( ! $parsed['success'] || empty( $parsed['body']['telegram_connected'] ) ) {
			return array(
				'success' => false,
				'error'   => $parsed['body']['message'] ?? 'No se pudo conectar el bot de Telegram',
				'code'    => $parsed['code'],
			);
		}

		return array(
			'success'      => true,
			'bot_username' => $parsed['body']['bot_username'] ?? '',
		);
	}

	/**
	 * PASO 4: Save bot config
	 *
	 * @param string $tenant_id
	 * @param array  $config  Claves soportadas: bot_name, welcome_message, system_prompt, language
	 */
	public static function save_config( $tenant_id, $config = array() ) {
		$response = self::request( "/tenants/{$tenant_id}/config", 'PATCH', $config );
		$parsed   = self::parse_response( $response );

		if ( ! $parsed['success'] ) {
			return array(
				'success' => false,
				'error'   => $parsed['body']['message'] ?? 'No se pudo guardar la configuracion',
				'code'    => $parsed['code'],
			);
		}

		return array(
			'success' => true,
		);
	}

	/**
	 * PASO 5: Activate bot
	 */
	public static function activate_bot( $tenant_id ) {
		$response = self::request( "/tenants/{$tenant_id}/activate", 'POST' );
		$parsed   = self::parse_response( $response );

		if ( ! $parsed['success'] ) {
			return array(
				'success' => false,
				'error'   => $parsed['body']['message'] ?? 'No se pudo activar el bot',
				'code'    => $parsed['code'],
				'missing' => $parsed['body']['missing'] ?? array(),
			);
		}

		return array(
			'success' => true,
			'status'  => $parsed['body']['status'] ?? 'active',
			'bot_ready' => $parsed['body']['bot_ready'] ?? false,
		);
	}

	/**
	 * Get tenant status.
	 * Devuelve el resultado parseado completo (success/code/body) en vez de
	 * colapsar cualquier fallo a null, para que el llamador pueda distinguir
	 * "el Backend no responde" de "el Backend respondió que este tenant/API Key
	 * ya no es válido" (ej. 401 INVALID_API_KEY tras borrar el tenant en Postgres).
	 */
	public static function get_status( $tenant_id ) {
		$response = self::request( "/tenants/{$tenant_id}/status", 'GET' );
		return self::parse_response( $response );
	}
}
