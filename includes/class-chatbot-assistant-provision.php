<?php
/**
 * Orquestador del onboarding y endpoints REST del plugin.
 *
 * Gestiona el flujo completo de onboarding (Pasos 1-5):
 * - Paso 1: Registra tenant en el Backend
 * - Paso 2: Valida y envia credenciales de WooCommerce
 * - Paso 3: Canal — WhatsApp (conexión directa u OAuth de Meta) o Telegram
 * - Paso 4: Personaliza el bot (nombre, mensaje, system_prompt)
 * - Paso 5: Activa el tenant
 *
 * Los pasos 1 y 2 corren solos (maybe_run_pending_provision); del 3 al 5 los dispara el wizard.
 *
 * Toda comunicacion con servicios externos ocurre via Backend Node.js
 * usando Chatbot_Assistant_Backend_Client. El plugin nunca llama al MCP directamente.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Chatbot_Assistant_Provision {

    const OPTION_KEY = 'chatbot_provision';
    const AUDIT_OPTION_KEY = 'chatbot_provision_audit';
    const AUDIT_LIMIT = 10;
    const WC_RETRY_BASE_SECONDS = 30;
    const WC_RETRY_MAX_SECONDS = 300;

    /**
     * Registra las rutas REST del plugin (chatbot/v1): las del wizard (solo admin) y las que llama
     * el Backend (con su Bearer). Los pasos 1 y 2 no tienen ruta: corren en el mismo proceso, desde
     * maybe_run_pending_provision. Hasta el 2026-10-08 existía POST /tenant/register, anónima y sin
     * uso, que devolvía la API key (S6 en PLAN_DE_CORRECCIONES.md del Backend): se quitó.
     */
    public static function register_routes() {
        // PASO 3B: Meta OAuth callback
        register_rest_route( 'chatbot/v1', '/webhook/meta-oauth', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'handle_meta_oauth_callback' ),
            'permission_callback' => array( __CLASS__, 'provision_permission_check' ),
        ) );

        // Backend webhook: notificaciones de estado
        register_rest_route( 'chatbot/v1', '/webhook/backend', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'handle_backend_webhook' ),
            'permission_callback' => array( __CLASS__, 'backend_webhook_permission_check' ),
        ) );

        // PASO 3A: Get WhatsApp signup URL from Backend
        register_rest_route( 'chatbot/v1', '/wa/signup-url', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'handle_wa_signup_url' ),
            'permission_callback' => array( __CLASS__, 'provision_permission_check' ),
        ) );

        // PASO 3 sin OAuth: conexión directa de WhatsApp (convive con signup-url)
        register_rest_route( 'chatbot/v1', '/wa/connect-direct', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'handle_wa_connect_direct' ),
            'permission_callback' => array( __CLASS__, 'provision_permission_check' ),
        ) );

        // PASO 3 (canal alternativo): Connect Telegram bot
        register_rest_route( 'chatbot/v1', '/telegram/connect', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'handle_telegram_connect' ),
            'permission_callback' => array( __CLASS__, 'provision_permission_check' ),
        ) );

        // PASO 4: Save bot configuration
        register_rest_route( 'chatbot/v1', '/config', array(
            'methods' => 'PATCH',
            'callback' => array( __CLASS__, 'handle_save_config' ),
            'permission_callback' => array( __CLASS__, 'provision_permission_check' ),
        ) );

        // PASO 5: Activate bot
        register_rest_route( 'chatbot/v1', '/activate', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'handle_activate' ),
            'permission_callback' => array( __CLASS__, 'provision_permission_check' ),
        ) );

        // Lookup de cliente por teléfono — lo llama el Backend (nunca el MCP
        // directo, ver services/plugin.ts en chatbot-backend), mismo
        // mecanismo de auth que /webhook/backend.
        register_rest_route( 'chatbot/v1', '/customers/lookup-by-phone', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'handle_lookup_customer_by_phone' ),
            'permission_callback' => array( __CLASS__, 'backend_webhook_permission_check' ),
        ) );

        // Lookup de PEDIDOS por teléfono de facturación (2026-09-10) —
        // distinto del de arriba (que busca una CUENTA registrada, vía
        // WP_User_Query sobre billing_phone del USUARIO). Este busca sobre
        // el pedido mismo, sin importar si tiene cuenta o fue como
        // invitado (customer_id=0) — necesario porque verEstadoPedidos/
        // editarProductosPedido/actualizarDatosContactoPedido/cancelarPedido
        // filtraban SOLO por customer_id, así que un pedido de invitado
        // quedaba invisible para siempre, sin importar qué tan bien
        // identificado esté el cliente en el chat. Ver comentario largo en
        // handle_lookup_orders_by_phone.
        register_rest_route( 'chatbot/v1', '/orders/lookup-by-phone', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'handle_lookup_orders_by_phone' ),
            'permission_callback' => array( __CLASS__, 'backend_webhook_permission_check' ),
        ) );
    }

    /**
     * Valida que el llamante sea un admin de WordPress autenticado (nonce REST).
     * Todos los endpoints del wizard se usan desde el panel admin.
     */
    public static function provision_permission_check( $request ) {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        return new WP_Error( 'rest_forbidden', 'Unauthorized', array( 'status' => 403 ) );
    }

    /**
     * Secreto compartido con el Backend para firmar los links del bot y para que el Backend se
     * identifique ante esta tienda (S7 del PLAN_DE_CORRECCIONES.md del Backend, 2026-10-11).
     *
     * Se DERIVA de la clave de la tienda con la misma receta que deriveLinkSecret en
     * services/crypto.ts del Backend (HMAC-SHA256 con la clave como llave y una etiqueta fija):
     * los dos lados llegan al mismo secreto sin mandarlo nunca por la red. El Backend lo guarda
     * cifrado la primera vez que esta tienda le hace un pedido autenticado. Hasta S7 era el
     * `api_key_hash`, que el Backend guarda en claro: quien leyera esa tabla podía fabricar links
     * de acceso a las cuentas de los clientes.
     *
     * @return string Secreto en hex, o '' si la tienda todavía no tiene clave.
     */
    public static function link_secret() {
        $api_key = get_option( 'chatbot_api_key_plain', '' );
        return '' === $api_key ? '' : hash_hmac( 'sha256', 'chatbot-link-secret-v1', $api_key );
    }

    /**
     * Permission check for Backend webhook notifications.
     * El Backend envía Authorization: Bearer <secreto de links> (ver link_secret()).
     */
    public static function backend_webhook_permission_check( $request ) {
        $link_secret = self::link_secret();
        if ( '' === $link_secret ) {
            return new WP_Error( 'rest_forbidden', 'Tenant not configured', array( 'status' => 403 ) );
        }

        $auth_header = (string) $request->get_header( 'authorization' );
        $token       = str_replace( 'Bearer ', '', $auth_header );

        if ( hash_equals( $link_secret, $token ) ) {
            return true;
        }

        return new WP_Error( 'rest_forbidden', 'Invalid token', array( 'status' => 403 ) );
    }

    /**
     * PASO 3B: Handle Meta OAuth callback.
     * Recibe oauth_code y state_token desde Meta redirect.
     */
    public static function handle_meta_oauth_callback( $request ) {
        $params      = $request->get_json_params();
        $oauth_code  = sanitize_text_field( $params['oauth_code'] ?? '' );
        $state_token = sanitize_text_field( $params['state_token'] ?? '' );

        if ( empty( $oauth_code ) || empty( $state_token ) ) {
            return new WP_REST_Response( array(
                'error' => 'Missing oauth_code or state_token',
            ), 400 );
        }

        $store = get_option( self::OPTION_KEY, array() );
        if ( empty( $store['tenant_id'] ) ) {
            return new WP_REST_Response( array(
                'error' => 'Tenant not registered',
            ), 400 );
        }

        // Completar setup de WhatsApp en Backend
        $result = Chatbot_Assistant_Backend_Client::complete_wa_setup(
            $store['tenant_id'],
            $oauth_code,
            $state_token
        );

        if ( ! $result['success'] ) {
            return new WP_REST_Response( array(
                'error'   => $result['error'],
                'code'    => $result['code'],
            ), $result['code'] );
        }

        // Actualizar estado local
        $store['phone_number']  = $result['phone_number'];
        $store['wa_verified']   = true;
        $store['current_step']  = self::advanced_step( $store, 3 );
        update_option( self::OPTION_KEY, $store, false );

        self::log_event( 'info', 'WhatsApp setup completed', array( 'phone' => $result['phone_number'] ) );

        return new WP_REST_Response( array(
            'success'     => true,
            'wa_verified' => $result['wa_verified'],
            'phone'       => $result['phone_number'],
        ), 200 );
    }

    /**
     * Backend webhook: recibe notificaciones de cambios de estado.
     * Ejemplos: tenant.activated, tenant.suspended, wc.credentials_invalid, etc.
     */
    public static function handle_backend_webhook( $request ) {
        $params = $request->get_json_params();
        $event  = sanitize_text_field( $params['event'] ?? '' );
        $data   = $params['data'] ?? array();

        if ( empty( $event ) ) {
            return new WP_REST_Response( array( 'error' => 'Missing event' ), 400 );
        }

        $store = get_option( self::OPTION_KEY, array() );

        switch ( $event ) {
            case 'tenant.activated':
                $store['status']       = 'active';
                $store['current_step'] = 5;
                update_option( self::OPTION_KEY, $store, false );
                self::log_event( 'success', 'Tenant activated via webhook' );
                break;

            case 'tenant.suspended':
                $store['status'] = 'suspended';
                update_option( self::OPTION_KEY, $store, false );
                self::log_event( 'warning', 'Tenant suspended via webhook' );
                break;

            case 'wc.credentials_invalid':
                $store['wc_verified'] = false;
                update_option( self::OPTION_KEY, $store, false );
                self::log_event( 'error', 'WooCommerce credentials invalid (webhook)' );
                break;

            case 'wa.token_revoked':
                $store['wa_verified'] = false;
                update_option( self::OPTION_KEY, $store, false );
                self::log_event( 'error', 'WhatsApp token revoked (webhook)' );
                break;

            case 'plan.limit_warning':
                $limit = $data['limit'] ?? 'unknown';
                self::log_event( 'warning', 'Plan limit approaching: ' . $limit );
                break;

            case 'plan.limit_reached':
                $store['status'] = 'suspended';
                update_option( self::OPTION_KEY, $store, false );
                self::log_event( 'error', 'Plan limit reached' );
                break;

            default:
                self::log_event( 'info', 'Webhook event received: ' . $event, $data );
        }

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * PASO 1 + PASO 2: Orchestrate tenant registration and WC validation.
     * Called automatically on the `init` hook (only in wp-admin) if conditions
     * are met, or explicitly with $force=true from `wp chatbot provision-auto`.
     */
    public static function maybe_run_pending_provision( $force = false ) {
        if ( ! function_exists( 'get_home_url' ) ) {
            return;
        }

        // Evitar llamadas salientes al Backend en cada visita del front-end o en
        // cada comando de WP-CLI suelto: el disparo automático (hook `init`) solo
        // corre en wp-admin. `provision-auto` se salta este check con $force=true
        // porque es una invocación explícita y deliberada.
        if ( ! $force && ! is_admin() ) {
            return;
        }

        $store = get_option( self::OPTION_KEY, array() );

        // Si todavía no hay API key, generarla. Es la credencial de la tienda ante el Backend
        // (Bearer en todos los requests, también después del onboarding).
        if ( empty( get_option( 'chatbot_api_key_plain', '' ) ) ) {
            $api_key = wp_generate_uuid4();
            update_option( 'chatbot_api_key_plain', $api_key, false );
        }

        // Si ya está registrado y validado, no hacer nada — salvo que sea una
        // invocación forzada (wp chatbot provision-auto), que corre sin que
        // nadie abra la pantalla de admin y por lo tanto nunca pasa por
        // sync_status_from_backend(). Ahí sí confirmamos contra el Backend:
        // si la fila ya no existe (ej. se borró en Postgres durante pruebas),
        // sync_status_from_backend() limpia el option y cae a re-registrar
        // en vez de quedar con un tenant_id fantasma para siempre.
        if ( ! empty( $store['tenant_id'] ) && ! empty( $store['wc_verified'] ) ) {
            if ( ! $force ) {
                return;
            }
            self::sync_status_from_backend( $store['tenant_id'] );
            $store = get_option( self::OPTION_KEY, array() );
            if ( ! empty( $store['tenant_id'] ) && ! empty( $store['wc_verified'] ) ) {
                return;
            }
        }

        // PASO 1: Registrar tenant en Backend
        if ( empty( $store['tenant_id'] ) ) {
            $api_key = get_option( 'chatbot_api_key_plain', '' );
            $result  = Chatbot_Assistant_Backend_Client::register_tenant( $api_key );

            if ( ! $result['success'] ) {
                self::log_event( 'error', 'Paso 1 fallido: ' . $result['error'], array( 'code' => $result['code'] ) );
                return;
            }

            // Guardar tenant_id en BD local (sin api_key_plain — se mantiene en opción temporal)
            $store['tenant_id']    = $result['tenant_id'];
            $store['state_token']  = $result['state_token'];
            $store['status']       = $result['status'];
            $store['current_step'] = 1;
            update_option( self::OPTION_KEY, $store, false );
            self::log_event( 'success', 'Paso 1 completado: tenant registrado', array( 'tenant_id' => $result['tenant_id'] ) );
            // OPTIMIZACIÓN: Sin return intermedio - continúa con Paso 2 en el MISMO request
        }

        // PASO 2: Validar WooCommerce
        if ( ! empty( $store['tenant_id'] ) && empty( $store['wc_verified'] ) ) {

            // Evitar ráfagas: respetar backoff exponencial desde el último intento fallido.
            $attempts     = isset( $store['wc_attempt_count'] ) ? (int) $store['wc_attempt_count'] : 0;
            $last_attempt = isset( $store['wc_last_attempt'] ) ? (int) $store['wc_last_attempt'] : 0;
            if ( $last_attempt > 0 ) {
                $backoff   = min( self::WC_RETRY_BASE_SECONDS * ( 2 ** $attempts ), self::WC_RETRY_MAX_SECONDS );
                $remaining = ( $last_attempt + $backoff ) - time();
                if ( $remaining > 0 ) {
                    self::log_event( 'info', "Paso 2 en cooldown, próximo intento en {$remaining}s", array( 'attempts' => $attempts ) );
                    return; // Todavía en cooldown, no reintentar.
                }
            }

            // Limpiar la clave del intento anterior (si quedó huérfana) antes de generar una nueva.
            if ( ! empty( $store['consumerKey'] ) ) {
                self::delete_wc_key( $store['consumerKey'] );
            }

            // Generar WC keys localmente
            $wc_keys = self::generate_wc_keys();
            if ( ! $wc_keys ) {
                $store['wc_last_attempt']  = time();
                $store['wc_attempt_count'] = $attempts + 1;
                update_option( self::OPTION_KEY, $store, false );
                self::log_event( 'error', 'Paso 2 fallido: no se generaron WC keys' );
                return;
            }

            // Enviar al Backend para validar
            $result = Chatbot_Assistant_Backend_Client::validate_wc(
                $store['tenant_id'],
                $wc_keys['key'],
                $wc_keys['secret'],
                Chatbot_Assistant_Backend_Client::get_store_url() . '/wp-json/wc/v3'
            );

            $store['wc_last_attempt'] = time();

            if ( ! $result['success'] ) {
                // Guardamos la consumer key para poder limpiarla en el siguiente intento.
                $store['consumerKey']     = $wc_keys['key'];
                $store['wc_attempt_count'] = $attempts + 1;
                update_option( self::OPTION_KEY, $store, false );
                self::log_event( 'error', 'Paso 2 fallido: ' . $result['error'], array( 'code' => $result['code'] ) );
                return;
            }

            // Solo la consumer key (para poder borrar la clave al reintentar o al desinstalar). El
            // secreto no se copia: WooCommerce ya lo guarda en su tabla y el Backend, cifrado. Hasta
            // el 2026-10-08 se guardaba además una copia cifrada que nada leía nunca.
            $store['consumerKey']      = $wc_keys['key'];
            $store['wc_verified']      = true;
            $store['current_step']     = self::advanced_step( $store, 2 );
            $store['wc_attempt_count'] = 0;
            update_option( self::OPTION_KEY, $store, false );
            // No borramos chatbot_api_key_plain: el Backend espera el Bearer en texto
            // plano en TODOS los requests posteriores (Pasos 3-5, reconexiones, status).
            self::log_event( 'success', 'Paso 2 completado: WooCommerce validado', array( 'mcp_registered' => $result['mcp_registered'] ) );
        }
    }



    /**
     * Resincroniza el estado local con la fuente de verdad del Backend.
     * Se llama al abrir el panel admin del plugin para no depender únicamente
     * de los webhooks entrantes (que pueden no llegar si el Backend no
     * alcanza la URL local del sitio).
     */
    public static function sync_status_from_backend( $tenant_id ) {
        if ( empty( $tenant_id ) ) {
            return;
        }

        $store = get_option( self::OPTION_KEY, array() );

        // Se captura ANTES de que el resto de la función pise last_sync_ok,
        // para poder distinguir "se recuperó la conexión" de "seguía OK y
        // no cambió nada" — ambos casos llegan al mismo last_sync_ok=true,
        // pero solo el primero amerita una entrada en la bitácora.
        $was_failing = isset( $store['last_sync_ok'] ) && false === $store['last_sync_ok'];

        $result = Chatbot_Assistant_Backend_Client::get_status( $tenant_id );

        if ( ! $result['success'] ) {
            // Un 401 INVALID_API_KEY no es un fallo de red — es el Backend
            // diciendo explícitamente que ya no reconoce este tenant/API Key
            // (ej. se borró la fila en Postgres). Insistir con el mismo
            // tenant_id nunca se va a resolver solo, así que limpiamos el
            // estado local para que el próximo request lo registre de cero,
            // en vez de quedar atascado para siempre en un estado fantasma.
            $backend_error = $result['body']['error'] ?? '';
            if ( 401 === (int) $result['code'] && 'INVALID_API_KEY' === $backend_error ) {
                delete_option( self::OPTION_KEY );
                self::log_event( 'warning', 'Tenant no reconocido por el Backend (INVALID_API_KEY) — estado local limpiado, se re-registrará automáticamente' );
                return;
            }

            // Cualquier otro fallo (Backend caído, timeout, etc.): se conserva
            // todo el estado local existente — solo se marca que el intento
            // más reciente falló, para que la vista pueda avisar en vez de
            // mostrar el timestamp viejo como si fuera confiable.
            $store['last_sync_ok'] = false;
            update_option( self::OPTION_KEY, $store, false );
            self::log_event( 'warning', 'Sync con Backend fallido: no se pudo consultar /status (se conserva el estado local)' );
            return;
        }

        $status = $result['body'];

        // Compara contra el estado previo para loguear solo lo que realmente
        // cambió — evita llenar la bitácora con un "sync OK, nada cambió" en
        // cada recarga del panel.
        $changes = array();

        if ( isset( $status['status'] ) && $status['status'] !== ( $store['status'] ?? null ) ) {
            $changes[] = sprintf( 'status: %s → %s', $store['status'] ?? '—', $status['status'] );
            $store['status'] = $status['status'];
        }
        if ( isset( $status['current_step'] ) ) {
            $new_step = (int) $status['current_step'];
            if ( $new_step !== (int) ( $store['current_step'] ?? 0 ) ) {
                $changes[] = sprintf( 'current_step: %s → %d', $store['current_step'] ?? '—', $new_step );
            }
            $store['current_step'] = $new_step;
        }
        if ( isset( $status['bot_name'] ) ) {
            $store['bot_name'] = $status['bot_name'];
        }
        if ( isset( $status['phone_number'] ) ) {
            $store['phone_number'] = $status['phone_number'];
        }
        if ( isset( $status['wc_status'] ) ) {
            $new_wc = ( 'ok' === $status['wc_status'] );
            if ( $new_wc !== ! empty( $store['wc_verified'] ) ) {
                $changes[] = 'wc_verified: ' . ( ! empty( $store['wc_verified'] ) ? 'true' : 'false' ) . ' → ' . ( $new_wc ? 'true' : 'false' );
            }
            $store['wc_verified'] = $new_wc;
        }
        if ( isset( $status['wa_status'] ) ) {
            $new_wa = ( 'ok' === $status['wa_status'] );
            if ( $new_wa !== ! empty( $store['wa_verified'] ) ) {
                $changes[] = 'wa_verified: ' . ( ! empty( $store['wa_verified'] ) ? 'true' : 'false' ) . ' → ' . ( $new_wa ? 'true' : 'false' );
            }
            $store['wa_verified'] = $new_wa;
        }
        if ( isset( $status['telegram_status'] ) ) {
            $new_telegram = ( 'ok' === $status['telegram_status'] );
            if ( $new_telegram !== ! empty( $store['telegram_verified'] ) ) {
                $changes[] = 'telegram_verified: ' . ( ! empty( $store['telegram_verified'] ) ? 'true' : 'false' ) . ' → ' . ( $new_telegram ? 'true' : 'false' );
            }
            $store['telegram_verified'] = $new_telegram;
        }
        if ( isset( $status['telegram_bot_username'] ) ) {
            $store['telegram_bot_username'] = $status['telegram_bot_username'];
        }
        if ( isset( $status['telegram_owner_confirmed'] ) ) {
            $new_confirmed = ! empty( $status['telegram_owner_confirmed'] );
            if ( $new_confirmed !== ! empty( $store['telegram_owner_confirmed'] ) ) {
                $changes[] = 'telegram_owner_confirmed: ' . ( ! empty( $store['telegram_owner_confirmed'] ) ? 'true' : 'false' ) . ' → ' . ( $new_confirmed ? 'true' : 'false' );
            }
            $store['telegram_owner_confirmed'] = $new_confirmed;
        }
        if ( isset( $status['plan'] ) ) {
            $store['plan'] = $status['plan'];
        }
        if ( isset( $status['plan_limit'] ) ) {
            $store['plan_limit'] = $status['plan_limit'];
        }
        if ( isset( $status['conversations_this_month'] ) ) {
            $store['conversations_this_month'] = $status['conversations_this_month'];
        }

        $store['last_sync_ok']     = true;
        $store['status_synced_at'] = current_time( 'mysql' );

        update_option( self::OPTION_KEY, $store, false );

        if ( $was_failing ) {
            // La reconexión en sí es la novedad, aunque el estado no haya
            // cambiado nada mientras el Backend estuvo caído — si no se
            // loguea acá, la última entrada visible sigue siendo el warning
            // de la falla anterior y parece que el log "no se actualiza".
            $message = 'Conexión con el Backend restablecida';
            if ( ! empty( $changes ) ) {
                $message .= ' — ' . implode( ', ', $changes );
            }
            self::log_event( 'success', $message );
        } elseif ( ! empty( $changes ) ) {
            self::log_event( 'success', 'Estado sincronizado con el Backend: ' . implode( ', ', $changes ) );
        }
    }

    /**
     * PASO 3A: Get WhatsApp signup URL from Backend.
     * Obtiene la URL de Meta OAuth para que el usuario autorice WhatsApp.
     */
    public static function handle_wa_signup_url( $request ) {
        $store = get_option( self::OPTION_KEY, array() );
        if ( empty( $store['tenant_id'] ) ) {
            return new WP_REST_Response( array(
                'error' => 'Tenant not registered. Complete Paso 1 first.',
            ), 400 );
        }

        // Llamar a Backend para obtener URL Meta
        $result = Chatbot_Assistant_Backend_Client::get_wa_signup_url( $store['tenant_id'] );

        if ( ! $result['success'] ) {
            return new WP_REST_Response( array(
                'error'   => $result['error'],
                'code'    => $result['code'],
            ), $result['code'] );
        }

        self::log_event( 'info', 'WhatsApp signup URL requested' );

        return new WP_REST_Response( array(
            'success'           => true,
            'signup_url'        => $result['signup_url'],
            'redirect_uri'      => rest_url( 'chatbot/v1/webhook/meta-oauth' ),
        ), 200 );
    }

    /**
     * PASO 3 sin OAuth: conexión directa de WhatsApp (2026-10-08).
     * El admin pega los tres datos del panel de Meta; el Backend los verifica contra Meta, rechaza un
     * número que ya es de otra tienda, suscribe el webhook y guarda el token cifrado. Acá no se
     * guarda el token: solo el resultado (número y wa_verified), igual que el flujo OAuth.
     */
    public static function handle_wa_connect_direct( $request ) {
        $params          = $request->get_json_params();
        $phone_number_id = sanitize_text_field( $params['phone_number_id'] ?? '' );
        $waba_id         = sanitize_text_field( $params['waba_id'] ?? '' );
        $access_token    = sanitize_text_field( $params['access_token'] ?? '' );

        if ( empty( $phone_number_id ) || empty( $waba_id ) || empty( $access_token ) ) {
            return new WP_REST_Response( array(
                'error' => 'Faltan datos: Phone number ID, WhatsApp Business Account ID y token de acceso',
            ), 400 );
        }

        $store = get_option( self::OPTION_KEY, array() );
        if ( empty( $store['tenant_id'] ) ) {
            return new WP_REST_Response( array(
                'error' => 'Tenant not registered',
            ), 400 );
        }

        $result = Chatbot_Assistant_Backend_Client::connect_wa_direct( $store['tenant_id'], $phone_number_id, $waba_id, $access_token );

        if ( ! $result['success'] ) {
            self::log_event( 'error', 'WhatsApp (conexión directa) fallido: ' . $result['error'], array( 'code' => $result['code'] ) );
            return new WP_REST_Response( array(
                'error'   => $result['error'],
                'code'    => $result['code'],
            ), $result['code'] ? $result['code'] : 500 );
        }

        // Mismo criterio que Telegram y que el OAuth: el paso 3 se da por completo con un canal.
        $store['phone_number'] = $result['phone_number'];
        $store['wa_verified']  = true;
        $store['current_step'] = self::advanced_step( $store, 3 );
        update_option( self::OPTION_KEY, $store, false );

        self::log_event( 'success', 'WhatsApp conectado (conexión directa)', array( 'phone' => $result['phone_number'] ) );

        return new WP_REST_Response( array(
            'success' => true,
            'phone'   => $result['phone_number'],
        ), 200 );
    }

    /**
     * PASO 3 (canal alternativo): Handle Telegram bot connection.
     * A diferencia de WhatsApp no hay redirect/callback — el Backend valida
     * el token y registra el webhook en un solo round-trip síncrono.
     */
    public static function handle_telegram_connect( $request ) {
        $params    = $request->get_json_params();
        $bot_token = sanitize_text_field( $params['bot_token'] ?? '' );

        if ( empty( $bot_token ) ) {
            return new WP_REST_Response( array(
                'error' => 'Missing bot_token',
            ), 400 );
        }

        $store = get_option( self::OPTION_KEY, array() );
        if ( empty( $store['tenant_id'] ) ) {
            return new WP_REST_Response( array(
                'error' => 'Tenant not registered',
            ), 400 );
        }

        $result = Chatbot_Assistant_Backend_Client::connect_telegram( $store['tenant_id'], $bot_token );

        if ( ! $result['success'] ) {
            return new WP_REST_Response( array(
                'error'   => $result['error'],
                'code'    => $result['code'],
            ), $result['code'] );
        }

        // Igual que WhatsApp: current_step avanza a 3 en cuanto hay UN canal
        // conectado, sea cual sea — el Paso 3 ("Elegí tus canales") se da
        // por completo con cualquiera de los dos, no con ambos.
        $store['telegram_verified']    = true;
        $store['telegram_bot_username'] = $result['bot_username'];
        $store['current_step']         = self::advanced_step( $store, 3 );
        update_option( self::OPTION_KEY, $store, false );

        self::log_event( 'success', 'Telegram conectado', array( 'bot_username' => $result['bot_username'] ) );

        return new WP_REST_Response( array(
            'success'      => true,
            'bot_username' => $result['bot_username'],
        ), 200 );
    }

    /**
     * PASO 4: Save bot configuration (nombre, mensaje bienvenida).
     * Desde la 1.0.5 no hay instrucciones propias (system_prompt): todas las tiendas usan el mismo prompt.
     */
    public static function handle_save_config( $request ) {
        $params = $request->get_json_params();

        $bot_name          = sanitize_text_field( $params['bot_name'] ?? '' );
        $welcome_message   = sanitize_text_field( $params['welcome_message'] ?? '' );

        $store = get_option( self::OPTION_KEY, array() );
        if ( empty( $store['tenant_id'] ) ) {
            return new WP_REST_Response( array(
                'error' => 'Tenant not registered',
            ), 400 );
        }

        // Validar que al menos un campo está presente
        if ( empty( $bot_name ) && empty( $welcome_message ) ) {
            return new WP_REST_Response( array(
                'error' => 'At least one field is required',
            ), 400 );
        }

        $config = array();
        if ( ! empty( $bot_name ) ) {
            $config['bot_name'] = $bot_name;
        }
        if ( ! empty( $welcome_message ) ) {
            $config['welcome_message'] = $welcome_message;
        }

        // Enviar al Backend
        $result = Chatbot_Assistant_Backend_Client::save_config( $store['tenant_id'], $config );

        if ( ! $result['success'] ) {
            return new WP_REST_Response( array(
                'error'   => $result['error'],
                'code'    => $result['code'],
            ), $result['code'] );
        }

        // Guardar localmente
        $store['bot_name']        = $bot_name;
        $store['welcome_message'] = $welcome_message;
        unset( $store['system_prompt'] ); // lo que guardaron versiones anteriores a la 1.0.5
        $store['current_step']    = self::advanced_step( $store, 4 );
        update_option( self::OPTION_KEY, $store, false );

        self::log_event( 'success', 'Paso 4: Bot configuration saved', array(
            'bot_name' => $bot_name,
        ) );

        return new WP_REST_Response( array(
            'success'          => true,
            'current_step'     => $store['current_step'],
        ), 200 );
    }

    /**
     * PASO 5: Activate bot.
     * Verifica que Paso 2 (WC) y Paso 3 (WhatsApp) estén completados.
     */
    public static function handle_activate( $request ) {
        $store = get_option( self::OPTION_KEY, array() );

        if ( empty( $store['tenant_id'] ) ) {
            return new WP_REST_Response( array(
                'error' => 'Tenant not registered',
            ), 400 );
        }

        // WooCommerce sigue siendo obligatorio; el canal de mensajería ya no
        // exige WhatsApp específicamente — alcanza con al menos uno conectado
        // (mismo criterio que el Backend en POST /activate).
        if ( empty( $store['wc_verified'] ) ) {
            return new WP_REST_Response( array(
                'error' => 'WooCommerce not verified. Complete Paso 2 first.',
            ), 400 );
        }

        if ( empty( $store['wa_verified'] ) && empty( $store['telegram_verified'] ) ) {
            return new WP_REST_Response( array(
                'error' => 'No hay ningún canal conectado. Completa el Paso 3 (WhatsApp o Telegram) primero.',
            ), 400 );
        }

        // Llamar a Backend para activar
        $result = Chatbot_Assistant_Backend_Client::activate_bot( $store['tenant_id'] );

        if ( ! $result['success'] ) {
            return new WP_REST_Response( array(
                'error'   => $result['error'],
                'code'    => $result['code'],
            ), $result['code'] );
        }

        // Actualizar estado local
        $store['status']       = 'active';
        $store['current_step'] = 5;
        update_option( self::OPTION_KEY, $store, false );

        self::log_event( 'success', 'Paso 5 completado: Bot activated', array() );

        return new WP_REST_Response( array(
            'success'       => true,
            'status'        => 'active',
            'current_step'  => 5,
        ), 200 );
    }

    /**
     * Busca un cliente de WooCommerce por billing_phone (meta de usuario).
     * No existe filtro por teléfono en la REST API nativa de WooCommerce
     * (billing.phone no está indexado) — por eso esta consulta se hace acá,
     * con acceso directo a WP_User_Query, en vez de en el MCP.
     *
     * Match exacto contra el valor tal cual está guardado en billing_phone.
     * Pendiente si hace falta normalizar formato (+506, espacios, guiones)
     * una vez que se vea en la práctica qué tan seguido falla por eso.
     */
    public static function handle_lookup_customer_by_phone( $request ) {
        $phone = sanitize_text_field( $request->get_param( 'phone' ) );

        if ( empty( $phone ) ) {
            return new WP_REST_Response( array( 'error' => 'Missing phone' ), 400 );
        }

        $users = get_users( array(
            'meta_key'   => 'billing_phone',
            'meta_value' => $phone,
            'number'     => 1,
            'fields'     => array( 'ID', 'user_email', 'display_name' ),
        ) );

        if ( empty( $users ) ) {
            return new WP_REST_Response( array( 'found' => false ), 200 );
        }

        $user = $users[0];

        return new WP_REST_Response( array(
            'found'        => true,
            'customer_id'  => $user->ID,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
        ), 200 );
    }

    /**
     * Busca PEDIDOS reales cuyo teléfono de facturación coincida — a
     * diferencia de handle_lookup_customer_by_phone (que busca una CUENTA),
     * esto encuentra un pedido sin importar si tiene cuenta o fue como
     * invitado (customer_id=0). Solo devuelve los ids — el Backend ya sabe
     * traer el detalle completo de cada uno vía la tool getOrder del MCP
     * (mismo formato/moneda/line_items que ya usa para todo lo demás), así
     * que acá no se duplica esa lógica.
     *
     * Soporta las dos formas de guardar pedidos de WooCommerce: HPOS (tablas
     * propias, wp_wc_order_addresses) y la forma clásica (post + postmeta) —
     * se detecta con OrderUtil, la misma API que usa el propio WooCommerce
     * para saber cuál está activa en esta tienda.
     */
    public static function handle_lookup_orders_by_phone( $request ) {
        global $wpdb;

        $phone = sanitize_text_field( $request->get_param( 'phone' ) );
        if ( empty( $phone ) ) {
            return new WP_REST_Response( array( 'error' => 'Missing phone' ), 400 );
        }

        $limit = 20; // de sobra — el Backend arma su propio top-N real después de mezclar con lo de la cuenta.

        $uses_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ( $uses_hpos ) {
            $orders_table    = $wpdb->prefix . 'wc_orders';
            $addresses_table = $wpdb->prefix . 'wc_order_addresses';
            $order_ids       = $wpdb->get_col( $wpdb->prepare(
                "SELECT o.id FROM {$orders_table} o
                 INNER JOIN {$addresses_table} a ON a.order_id = o.id AND a.address_type = 'billing'
                 WHERE a.phone = %s AND o.type = 'shop_order'
                 ORDER BY o.date_created_gmt DESC
                 LIMIT %d",
                $phone,
                $limit
            ) );
        } else {
            // Forma clásica (sin HPOS) — el teléfono vive en postmeta.
            $order_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_billing_phone'
                 WHERE pm.meta_value = %s AND p.post_type = 'shop_order'
                 ORDER BY p.post_date DESC
                 LIMIT %d",
                $phone,
                $limit
            ) );
        }

        return new WP_REST_Response( array(
            'order_ids' => array_map( 'intval', $order_ids ),
        ), 200 );
    }

    /**
     * Crea un par de credenciales WooCommerce directamente en la tabla `woocommerce_api_keys`
     * (no dependemos del controlador REST de WooCommerce).
     */
    public static function generate_wc_keys() {
        global $wpdb;

        $user = get_user_by( 'login', 'admin' );
        if ( ! $user ) {
            $users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
            if ( ! empty( $users ) ) {
                $user = $users[0];
            }
        }
        if ( ! $user ) {
            self::log_event( 'error', 'No admin user for key generation', array() );
            return false;
        }

        // Generación de claves compatible con WooCommerce:
        // - consumer_key: 'ck_' . wc_rand_hash()
        // - consumer_secret: 'cs_' . wc_rand_hash()
        // - consumer_key almacenado en BD como wc_api_hash( $consumer_key )
        try {
            if ( function_exists( 'wc_rand_hash' ) ) {
                $consumer_key = 'ck_' . wc_rand_hash();
                $consumer_secret = 'cs_' . wc_rand_hash();
            } else {
                // Fallback si WC no ha definido helpers aún.
                $consumer_key = 'ck_' . bin2hex( random_bytes( 20 ) );
                $consumer_secret = 'cs_' . bin2hex( random_bytes( 20 ) );
            }

            $db_consumer_key = self::wc_db_consumer_key( $consumer_key );

            $truncated_key = substr( $consumer_key, -7 );

            $data = array(
                'user_id'         => (int) $user->ID,
                'description'     => 'provisioning',
                'permissions'     => 'read_write',
                'consumer_key'    => $db_consumer_key,
                'consumer_secret' => $consumer_secret,
                'truncated_key'   => $truncated_key,
            );

            $formats = array( '%d', '%s', '%s', '%s', '%s', '%s' );

            $wpdb->insert( $wpdb->prefix . 'woocommerce_api_keys', $data, $formats );

            if ( 0 === (int) $wpdb->insert_id ) {
                self::log_event( 'error', 'Direct key insert failed', array() );
                return false;
            }

            self::log_event( 'success', 'Direct keys inserted', array() );

            return array(
                'key'    => $consumer_key,
                'secret' => $consumer_secret,
            );
        } catch ( Exception $e ) {
            self::log_event( 'error', 'Direct key generation exception', array() );
            return false;
        }
    }

    /**
     * La consumer key tal como WooCommerce la guarda en `woocommerce_api_keys` (hasheada).
     */
    private static function wc_db_consumer_key( $consumer_key ) {
        return function_exists( 'wc_api_hash' )
            ? wc_api_hash( $consumer_key )
            : hash_hmac( 'sha256', $consumer_key, 'wc-api' );
    }

    /**
     * El paso del onboarding solo avanza: reconectar un canal, revalidar WooCommerce o guardar la
     * configuración con el bot ya activo no lo baja. Hasta el 2026-10-10 varios pasos se guardaban
     * con un número fijo y el panel quedaba en 4 con el bot activo hasta recargarlo (M4 en
     * PLAN_DE_CORRECCIONES.md del Backend).
     */
    private static function advanced_step( $store, $step ) {
        return max( (int) ( $store['current_step'] ?? 0 ), $step );
    }

    /**
     * Elimina de `woocommerce_api_keys` la fila correspondiente a una consumer key.
     * Se usa para limpiar claves huérfanas de intentos de provisioning fallidos
     * antes de generar un par nuevo, y al desinstalar el plugin (uninstall.php).
     */
    public static function delete_wc_key( $consumer_key ) {
        global $wpdb;

        if ( empty( $consumer_key ) ) {
            return;
        }

        $wpdb->delete(
            $wpdb->prefix . 'woocommerce_api_keys',
            array( 'consumer_key' => self::wc_db_consumer_key( $consumer_key ) ),
            array( '%s' )
        );
    }

    /**
     * Registra un evento en la bitácora de auditoría local.
     * Mantiene solo las últimas AUDIT_LIMIT entradas, ordenadas de más reciente a más antigua.
     */
    public static function log_event( $level, $message, $context = array() ) {
        $audit = get_option( self::AUDIT_OPTION_KEY, array() );
        if ( ! is_array( $audit ) ) {
            $audit = array();
        }
        array_unshift( $audit, array(
            'level'      => sanitize_text_field( $level ),
            'message'    => sanitize_text_field( $message ),
            'context'    => $context,
            'created_at' => current_time( 'mysql' ),
        ) );
        $audit = array_slice( $audit, 0, self::AUDIT_LIMIT );
        update_option( self::AUDIT_OPTION_KEY, $audit, false );
    }

    /**
     * Vacía la bitácora de auditoría.
     */
    public static function clear_audit_log() {
        update_option( self::AUDIT_OPTION_KEY, array(), false );
    }

    /**
     * Devuelve las entradas de la bitácora para mostrar en el panel admin.
     */
    public static function get_audit_log() {
        $audit = get_option( self::AUDIT_OPTION_KEY, array() );
        return is_array( $audit ) ? $audit : array();
    }
}
