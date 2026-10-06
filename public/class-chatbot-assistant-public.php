<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://https://portafolio-adrianquintanilla.vercel.app/
 * @since      1.0.0
 *
 * @package    Chatbot_Assistant
 * @subpackage Chatbot_Assistant/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Chatbot_Assistant
 * @subpackage Chatbot_Assistant/public
 * @author     Quintanilla <adrianq1299@gmail.com>
 */
class Chatbot_Assistant_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Chatbot_Assistant_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Chatbot_Assistant_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/chatbot-assistant-public.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Chatbot_Assistant_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Chatbot_Assistant_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/chatbot-assistant-public.js', array( 'jquery' ), $this->version, false );

	}

	/**
	 * Vacía el carrito de WooCommerce cuando el link de checkout que armó
	 * el bot lo pide explícitamente (parámetro `limpiar_carrito` en la URL
	 * — ver services/sales.ts en chatbot-backend). Esto pasa cuando el
	 * cliente tenía un carrito viejo sin terminar de pagar y eligió
	 * empezar de nuevo en vez de seguir con ese: sin este hook, WooCommerce
	 * solo AGREGA el producto nuevo al carrito existente, nunca lo
	 * reemplaza.
	 *
	 * Se engancha al mismo hook que usa WooCommerce para su propio
	 * add-to-cart por URL (`wp_loaded`), pero con prioridad 5 — antes de
	 * la 20 que usa WC_Form_Handler::add_to_cart_action() — para vaciar el
	 * carrito ANTES de que se agregue el producto nuevo, no después.
	 *
	 * Cuando la compra tiene varios productos, el bot manda VARIOS links y
	 * todos llevan `limpiar_carrito` (ver generateReply.ts,
	 * clearCartAnywhereThisTurn) — a propósito, porque no hay forma de saber
	 * en qué orden el cliente va a hacer clic en cada uno. Sin este chequeo
	 * de sesión, el segundo link que se abre volvería a vaciar el carrito y
	 * borraría lo que el primero ya había agregado.
	 *
	 * `batch` (ver services/sales.ts) identifica de qué TURNO de la charla
	 * sale el link — todos los links hermanos de una misma compra comparten
	 * el mismo batch, así que solo el primero que se clickea vacía de
	 * verdad. Antes esto se resolvía con una ventana fija de 10 minutos
	 * ("ya vacié hace poco, no vuelvo a vaciar") en vez de un batch exacto
	 * — y se vio un caso real donde eso rompía el caso contrario: el
	 * cliente pedía "vaciá el carrito" de nuevo en un mensaje posterior
	 * (un batch distinto, con toda la intención de vaciar) y el hook lo
	 * ignoraba en silencio porque ya había vaciado por otro motivo minutos
	 * antes — el carrito quedaba igual, sin ningún aviso de error. Con el
	 * batch exacto, cualquier pedido de vaciar en un turno nuevo se cumple
	 * siempre, sin importar cuánto tiempo pasó.
	 *
	 * @since 1.0.0
	 */
	public function maybe_clear_cart_before_add() {

		if ( ! isset( $_GET['limpiar_carrito'] ) || ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		$batch            = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
		$last_clear_batch = WC()->session->get( 'chatbot_last_clear_batch' );

		// Mismo batch que el último vaciado real = es un link HERMANO de la
		// misma compra, no un pedido nuevo — no lo volvemos a vaciar. Sin
		// `batch` (link viejo armado antes de este cambio, o manual) se
		// vacía siempre, mismo criterio "mejor de más que de menos" que
		// tenía el chequeo anterior por defecto.
		if ( $batch && $last_clear_batch === $batch ) {
			return;
		}

		WC()->cart->empty_cart();
		WC()->session->set( 'chatbot_last_clear_batch', $batch );

	}

	/**
	 * Reemplaza (no suma) la cantidad de un producto puntual en el carrito
	 * cuando el link de checkout del bot lo pide (parámetro `reemplazar_item`
	 * en la URL — ver services/sales.ts en chatbot-backend, lo agrega
	 * siempre). `add-to-cart` por URL es nativamente acumulativo: WooCommerce
	 * SUMA la cantidad a lo que ya haya en el carrito para ese producto, en
	 * vez de fijarla — así que abrir el mismo link dos veces (doble clic,
	 * F5, reenviarlo desde el chat) duplicaba la cantidad pedida. Este hook
	 * saca cualquier línea existente de ESE producto puntual antes de que se
	 * agregue de nuevo, sin tocar el resto del carrito (para no romper una
	 * compra de varios productos distintos armada en la misma conversación).
	 *
	 * Mismo hook/prioridad que maybe_clear_cart_before_add — antes de la 20
	 * que usa WC_Form_Handler::add_to_cart_action().
	 *
	 * Con variaciones (2026-08-22, `variation_id` en la URL — ver
	 * services/sales.ts): si el link trae una variación puntual, solo se
	 * reemplaza la línea de ESA MISMA variación, no cualquier línea del
	 * producto padre — así comprar dos talles distintos del mismo producto
	 * en la misma charla no se pisan entre sí (cada talle queda en su propia
	 * línea del carrito). Sin variación (producto simple), mismo criterio de
	 * siempre: por product_id.
	 *
	 * @since 1.0.0
	 */
	public function maybe_replace_product_qty_before_add() {

		if ( ! isset( $_GET['reemplazar_item'], $_GET['add-to-cart'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$product_id   = absint( $_GET['add-to-cart'] );
		$variation_id = isset( $_GET['variation_id'] ) ? absint( $_GET['variation_id'] ) : 0;
		if ( ! $product_id ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$same_product = (int) $cart_item['product_id'] === $product_id;
			$matches      = $variation_id
				? $same_product && (int) $cart_item['variation_id'] === $variation_id
				: $same_product;

			if ( $matches ) {
				WC()->cart->remove_cart_item( $cart_item_key );
			}
		}

	}

	/**
	 * Prellena el campo de teléfono del checkout con el número que el bot
	 * ya tiene cacheado para esta conversación (`billing_phone` en la URL
	 * — ver comentario largo junto a `checkoutUrl` en services/sales.ts,
	 * chatbot-backend, Fix #2 de "me mostró pedidos que no son mios",
	 * 2026-09-10). Sin esto, el cliente podía escribir a mano en el
	 * checkout un teléfono DISTINTO al que el bot ya conocía — la fuente
	 * real de mismatches que hacía que "¿cuáles son mis pedidos?" mostrara
	 * el historial de otra conversación (ver Pieza 1, lookupOrderIdsByPhone
	 * en services/plugin.ts).
	 *
	 * `WC()->customer` (el objeto de sesión, no la cuenta de WordPress) es
	 * la fuente compartida que leen TANTO el checkout clásico como el
	 * bloque moderno de checkout (React/Store API) — esta tienda usa el
	 * bloque (confirmado con curl real, ver IMPLEMENTATION_STATUS.md), así
	 * que `WC_Checkout::get_value()` (el método del checkout clásico) ni
	 * se llega a usar acá. Lo que sí se confirmó real (curl contra
	 * `/wp-json/wc/store/v1/cart` con la misma sesión, después de este
	 * hook) es que el bloque hidrata su formulario pidiendo ESE endpoint
	 * al cargar, y `billing_address.phone` en esa respuesta viene
	 * directo de `WC()->customer->get_billing_phone()` — por eso alcanza
	 * con setear la propiedad ACÁ, antes de que el bloque haga esa
	 * petición, sin tocar ningún shortcode/plantilla ni el bloque mismo.
	 * Si algún tenant futuro usa el checkout clásico en vez del bloque,
	 * el mismo `WC()->customer->set_billing_phone()` también lo cubre —
	 * `WC_Checkout::get_value()` cae a esa misma propiedad cuando no hay
	 * `$_POST` (class-wc-checkout.php), así que no hace falta ninguna
	 * rama aparte para ese caso.
	 *
	 * El cliente puede editar el campo libremente antes de pagar — esto
	 * solo evita que tenga que escribirlo de cero, nunca lo bloquea ni lo
	 * valida.
	 *
	 * Se engancha a `wp_loaded` con prioridad 6 — después de
	 * maybe_replace_product_qty_before_add (5, que necesita correr antes
	 * del add-to-cart nativo de WooCommerce en la prioridad 20) pero el
	 * orden relativo entre esta función y esas no importa en realidad:
	 * esto no toca el carrito, solo el objeto `WC()->customer`.
	 *
	 * @since 1.4.0
	 */
	public function maybe_prefill_billing_phone() {

		if ( ! isset( $_GET['billing_phone'] ) || '' === $_GET['billing_phone'] || ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}

		$phone = sanitize_text_field( wp_unslash( $_GET['billing_phone'] ) );
		WC()->customer->set_billing_phone( $phone );

		// Además de precargar el campo, se GUARDA el número en la sesión
		// (2026-10-05). Son dos cosas distintas y por eso no alcanza con lo
		// de arriba: el campo del checkout es editable, y el aviso de pedido
		// al backend (Chatbot_Order_Events) usa el teléfono para encontrar la
		// conversación de WhatsApp — si el cliente lo reescribe (lo más común:
		// poner el número local sin el código de país), el aviso llega con un
		// número que no corresponde a ninguna conversación y el carrito
		// pendiente queda colgado para siempre, como si nunca hubiera
		// comprado.
		//
		// Este número NO es un dato que el cliente escribió: viene dentro del
		// token firmado del link (ver buildCartLoginToken en services/sales.ts
		// del backend), así que es la identidad real de la conversación. Se
		// guarda acá, en el único momento en que se lo conoce con certeza, y
		// Chatbot_Order_Events lo copia al pedido cuando el pedido existe.
		if ( WC()->session ) {
			WC()->session->set( Chatbot_Order_Events::SESSION_PHONE_KEY, $phone );
		}

	}

	/**
	 * Desarma el link corto de LOGIN (`/entrar/{token}`, ver comentario
	 * largo junto a `buildLoginToken` en services/sales.ts, chatbot-backend
	 * — Fix #3 de "me mostró pedidos que no son mios", pieza 3 de 3,
	 * 2026-09-10). A diferencia de `/ir/{token}` (que agrega un producto al
	 * carrito), este NO toca el carrito — loguea de verdad al navegador
	 * como el cliente de WooCommerce que el bot ya resolvió/creó este
	 * mismo turno (`wcCustomerId` en iniciarCompra), y recién ahí redirige
	 * a donde diga `?next=` (siempre un link de compra `/ir/...` de este
	 * mismo sistema, nunca una URL arbitraria — ver validación de
	 * `$safe_next` abajo).
	 *
	 * Por qué esto resuelve la causa de fondo (no solo el síntoma que ya
	 * cubren las Piezas 1 y 2): el checkout híbrido nunca loguea de verdad
	 * al cliente, así que CADA compra real quedaba con `customer_id: 0`
	 * (invitado) en WooCommerce, sin importar qué tan bien identificado
	 * estuviera en el chat. Con la sesión ya logueada ANTES de llegar al
	 * checkout, WooCommerce asigna el `customer_id` real al pedido — el
	 * mismo mecanismo con el que cualquier cliente logueado compra hoy en
	 * cualquier tienda WooCommerce, no algo nuevo que este plugin invente.
	 *
	 * `wc_customer_id` de WooCommerce ES el `wp_users.ID` de WordPress
	 * (confirmado contra la base real de esta tienda, Docker: el cliente
	 * de prueba `wc_customer_id=2` es exactamente `wp_users.ID=2`,
	 * WooCommerce nunca tiene tabla propia de clientes) — por eso alcanza
	 * con `wp_set_auth_cookie( $wc_customer_id )`, sin ningún mapeo
	 * intermedio.
	 *
	 * Mismo mecanismo HMAC+hashids que los otros dos tokens de este
	 * archivo, firma sobre `"{$wc_customer_id}:{$expires_at}"` — ver
	 * comentario de buildLoginToken en sales.ts para el resto (por qué
	 * hashids no es la seguridad real).
	 *
	 * **Un solo uso**: si el link se filtrara (reenviado, interceptado),
	 * la ventana de riesgo real es que un tercero se loguee como ese
	 * cliente — además del TTL corto (10 min, ver LOGIN_LINK_TTL_SECONDS
	 * en sales.ts), un transient propio (`chatbot_login_used_...`, mismo
	 * TTL) marca el token como ya usado tras el primer login exitoso; un
	 * segundo intento con el MISMO token sigue redirigiendo a `next` (no
	 * se le corta la compra al cliente legítimo por hacer doble clic) pero
	 * YA NO vuelve a loguear a nadie.
	 *
	 * Chequeo de rol `customer` antes de loguear: defensa en profundidad,
	 * no la protección real (la firma HMAC ya impide que alguien sin
	 * `api_key_hash` fabrique un token con OTRO wc_customer_id — solo
	 * protege contra el caso raro de que ese id apunte a una cuenta que ya
	 * no es de cliente, ej. un admin, sin tener que confiar en que nunca
	 * pase).
	 *
	 * Se engancha a `wp_loaded` con prioridad 1, igual que
	 * maybe_expand_short_checkout_link — rutas mutuamente excluyentes
	 * (`/entrar/` vs `/ir/`), el orden entre ellas no importa.
	 *
	 * @since 1.4.0
	 */
	public function maybe_handle_login_link() {

		if ( ! class_exists( '\Hashids\Hashids' ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( ! preg_match( '#/entrar/([A-Za-z0-9]+)#', $request_uri, $matches ) ) {
			return;
		}
		$token = $matches[1];

		$store        = get_option( Chatbot_Assistant_Provision::OPTION_KEY, array() );
		$api_key_hash = $store['api_key_hash'] ?? '';

		if ( empty( $api_key_hash ) ) {
			// Tenant sin provisionar todavía — no hay con qué validar, se
			// deja pasar en vez de romper el flujo (mismo criterio que los
			// otros dos manejadores de token de este archivo).
			return;
		}

		$hashids = new \Hashids\Hashids( $api_key_hash, 6 );
		$decoded = $hashids->decode( $token );

		// Fix (2026-10-03, hallazgo colateral): el token ahora trae un nonce como
		// cuarto número (ver buildLoginToken en services/sales.ts), así que dos
		// links del mismo segundo ya no son idénticos. Se aceptan los dos
		// formatos: 4 números (nuevo, el nonce entra en la firma) y 3 números
		// (tokens emitidos antes de este cambio, firma sin nonce). Cualquier
		// otra longitud se rechaza, incluidas las de /ir/ (6 y 7).
		// Token único de compra (2026-10-04): login + carrito juntos, sin `?next=`.
		// Ver parse_cart_login_token. Se prueba PRIMERO por ser el formato nuevo; si no
		// tiene esa forma, se cae a los dos formatos de login de siempre.
		//
		// OJO, decisión deliberada: acá NO se agregan los productos al carrito. Eso sigue
		// pasando en el pedido SIGUIENTE (`/carrito/`). WooCommerce arma la sesión del
		// carrito al principio de cada request, con la identidad que había ANTES de este
		// login — agregar los productos acá los escribiría en la sesión de invitado que
		// este mismo handler está por borrar, y el cliente llegaría al checkout con el
		// carrito vacío. Lo que se unificó es el TOKEN, no los dos pedidos.
		$cart_login = $this->parse_cart_login_token( $decoded );
		if ( $cart_login ) {
			$wc_customer_id      = $cart_login['wc_customer_id'];
			$expires_at          = $cart_login['expires_at'];
			$signature_truncated = $cart_login['signature_truncated'];
			$signed_message      = $cart_login['signed_message'];
		} elseif ( 4 === count( $decoded ) ) {
			list( $wc_customer_id, $expires_at, $signature_truncated, $nonce ) = $decoded;
			$signed_message = "{$wc_customer_id}:{$expires_at}:{$nonce}";
		} elseif ( 3 === count( $decoded ) ) {
			list( $wc_customer_id, $expires_at, $signature_truncated ) = $decoded;
			$signed_message = "{$wc_customer_id}:{$expires_at}";
		} else {
			$this->reject_checkout_link( 'Este link de acceso no es válido.' );
			return;
		}

		$expected_signature           = hash_hmac( 'sha256', $signed_message, $api_key_hash );
		$expected_signature_truncated = hexdec( substr( $expected_signature, 0, 10 ) );
		$valid_signature              = hash_equals( (string) $expected_signature_truncated, (string) $signature_truncated );
		$expired                      = time() > $expires_at;

		if ( ! $valid_signature || $expired ) {
			$this->reject_checkout_link( 'Este link de acceso ya venció.' );
			return;
		}

		// `next` tiene que ser un link de compra PROPIO (`/ir/...`) — nunca
		// una URL de otro dominio. home_url() de por sí ya antepone el
		// dominio real de esta tienda sin importar qué venga en $path (ver
		// get_home_url() en WordPress core), pero esta validación evita
		// además cualquier destino sin sentido si `next` viene vacío,
		// corrupto, o manipulado — se cae a `/checkout/` en ese caso.
		$next_param = isset( $_GET['next'] ) ? sanitize_text_field( wp_unslash( $_GET['next'] ) ) : '';
		// Compra en un solo link (2026-10-03): además de `/ir/` (un producto), el
		// destino puede ser `/carrito/` (todos los productos de la compra).
		$safe_next  = preg_match( '#^/(?:ir|carrito)/[A-Za-z0-9]+(?:\?[A-Za-z0-9%._=&+-]*)?$#', $next_param ) ? $next_param : '/checkout/';

		// Fix (2026-10-04, medido en Docker con dos navegadores): antes había acá un
		// transient `chatbot_login_used_{md5(token)}` que marcaba el token como usado
		// por 10 minutos. Como vive en el SERVIDOR y no en el navegador, el segundo
		// dispositivo que abría el MISMO link se saltaba todo este bloque: no se
		// logueaba, y los productos terminaban en un carrito de invitado. O sea, el
		// pedido salía con customer_id = 0, justo lo que este login existe para evitar
		// (Fix #3). Ahora cada apertura loguea.
		//
		// Lo que ese marcador protegía —no repetir el borrado del carrito— lo cubre el
		// transient de limpieza de más abajo, que ya es idempotente por lote. La firma
		// y el vencimiento de 10 minutos siguen siendo la protección real del link.
		$user = get_user_by( 'id', (int) $wc_customer_id );
		if ( $user && in_array( 'customer', (array) $user->roles, true ) ) {
			wp_clear_auth_cookie();
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, false );

			// Fallo real en vivo (2026-09-10, "de la nada se me cuela un
			// producto que no tiene nada que ver"): confirmado contra la
			// base real (Docker) que WooCommerce guarda el carrito de
			// una cuenta ya logueada en DOS lugares distintos, ninguno
			// de los dos ligado a la conversación actual del bot:
			//
			// 1. `_woocommerce_persistent_cart_{blog_id}` (wp_usermeta)
			//    — un espejo por cuenta que WooCommerce usa para
			//    reconstruir el carrito si esa cuenta se loguea en un
			//    dispositivo/sesión SIN carrito activo.
			// 2. `wp_woocommerce_sessions`, con `session_key` = el ID
			//    del cliente (ej. "2") — ESTA es la fuente real y
			//    autoritativa que WooCommerce lee en cada request de un
			//    cliente logueado (`WC_Session_Handler::get_customer_id()`
			//    usa el user ID tal cual como clave), con expiración
			//    larga (días), no minutos. Confirmado real: borrar SOLO
			//    la tabla 1 no alcanzaba — el carrito viejo seguía
			//    apareciendo porque esta fila sobrevivía intacta.
			//
			// Cualquiera de las dos, sola, alcanza para que un producto
			// abandonado hace días (nunca pagado) vuelva solo al carrito
			// la próxima vez que esa cuenta hace login de verdad — esto
			// NUNCA pasaba antes de esta pieza (login automático) porque
			// el checkout siempre era de invitado (`customer_id: 0`, sin
			// cuenta a la que atarle un carrito persistente). Es un
			// efecto secundario directo y predecible de loguear de
			// verdad, no un bug random de WooCommerce.
			//
			// Con el checkout híbrido de este bot, cada compra ya llega
			// con el/los producto(s) exactos que el bot va a agregar
			// (`/ir/{token}` inmediatamente después, ver `next` arriba)
			// — cualquier otra cosa que aparezca en el carrito en este
			// momento es, por definición, un resto de una visita vieja
			// sin terminar de pagar, no algo que el cliente de ESTA
			// conversación pidió. Se borran los dos rastros ACÁ, en el
			// momento exacto del login real, para que la cuenta arranque
			// sin ningún carrito propio antes de que
			// `maybe_expand_short_checkout_link` agregue el producto de
			// esta compra en la request siguiente — `maybe_replace_
			// product_qty_before_add` (reemplazar_item) solo reemplaza
			// la línea del producto que se está comprando, nunca tocaba
			// líneas de OTROS productos, así que no alcanzaba para esto.
			//
			// Verificado real (2026-09-10, Docker), las dos capas: con
			// SOLO el borrado de usermeta, "Mascara" (product_id 12,
			// resto de una prueba de Fix #3 de hace unas horas) seguía
			// apareciendo — confirmado que sobrevivía en
			// `wp_woocommerce_sessions` (`session_key = '2'`). Con las
			// dos borradas, un login+compra real de punta a punta contra
			// la tienda Docker terminó con el carrito conteniendo
			// ÚNICAMENTE el producto de esta compra.
			//
			// Fix (2026-10-03, causa raíz de "se borran los productos de la
			// compra anterior"): una compra de varios productos manda VARIOS
			// links, y cada uno pasa por este login. Antes, cada login borraba
			// la sesión, y con ella el carrito que el link anterior acababa de
			// armar y el marcador `chatbot_last_clear_batch` (ver
			// maybe_clear_cart_before_add) — así el último link que se abría
			// dejaba solo su producto. Ahora la limpieza corre UNA sola vez por
			// lote de compra (`batch`, que es el turno del bot): los links
			// hermanos de la misma compra la saltean y conservan el carrito.
			// Sin lote (link viejo, anterior a este cambio), se mantiene el
			// comportamiento de siempre: limpiar en cada login.
			// Con el token único el lote ya viene adentro del token; con los links
			// viejos (dos tokens) hay que sacarlo del destino `next`.
			$batch = 0;
			if ( $cart_login ) {
				$batch = $cart_login['batch_epoch_ms'];
			} elseif ( preg_match( '#^/ir/([A-Za-z0-9]+)#', $safe_next, $next_matches ) ) {
				$next_decoded = ( new \Hashids\Hashids( $api_key_hash, 6 ) )->decode( $next_matches[1] );
				// Mismo orden posicional que maybe_expand_short_checkout_link:
				// [productId, quantity, expiresAt, limpiarFlag, batchEpochMs, variationId, sig].
				// Los tokens de 6 números son de editarCarritoPendiente y no tienen lote.
				$batch = ( 7 === count( $next_decoded ) ) ? (int) $next_decoded[4] : 0;
			} elseif ( preg_match( '#^/carrito/([A-Za-z0-9]+)#', $safe_next, $cart_matches ) ) {
				// Token de compra completa (ver maybe_add_cart_from_link):
				// [expiresAt, limpiarFlag, batchEpochMs, sig, n, ...]. El lote es el 3er número.
				$cart_decoded = ( new \Hashids\Hashids( $api_key_hash, 6 ) )->decode( $cart_matches[1] );
				$batch        = ( count( $cart_decoded ) >= 6 ) ? (int) $cart_decoded[2] : 0;
			}

			// Sin lote se usa el token como clave (2026-10-04): antes, un link sin
			// lote se apoyaba en el marcador de "ya usado" para no volver a vaciar
			// el carrito en cada apertura. Ese marcador ya no existe, así que la
			// clave cae al token para que la limpieza siga pasando UNA sola vez.
			$cleanup_key       = 'chatbot_login_cleanup_' . md5( $user->ID . '|' . ( $batch ? $batch : $token ) );
			$cleaned_for_batch = get_transient( $cleanup_key );

			if ( ! $cleaned_for_batch ) {
				delete_user_meta( $user->ID, '_woocommerce_persistent_cart_' . get_current_blog_id() );
				global $wpdb;
				$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => (string) $user->ID ) );

				// Marca el lote (o el token) como ya limpiado: dos días cubren
				// cualquier compra en curso (los links de un turno caducan en horas).
				set_transient( $cleanup_key, 1, 2 * DAY_IN_SECONDS );
			}
		}

		// Con el token único el destino lo arma el código: el MISMO token sirve para la
		// ruta `/carrito/`, que es la que agrega los productos en el pedido siguiente.
		// Con los links viejos se respeta el `next` ya validado más arriba.
		wp_safe_redirect( home_url( $cart_login ? '/carrito/' . $token : $safe_next ) );
		exit;

	}

	/**
	 * Compra en un solo link (2026-10-03). Un link `/carrito/{token}` lleva TODOS
	 * los productos de la compra y los agrega en esta misma petición, dentro de la
	 * sesión del cliente. Reemplaza al link por producto: cada link abierto en otro
	 * navegador armaba su propio carrito y reemplazaba al anterior (bug real).
	 *
	 * Acepta DOS formatos de token (2026-10-04):
	 * - Token único de compra (nuevo): lo arma buildCartLoginToken y lleva también el
	 *   cliente y el nonce del login, porque el link ya no tiene `?next=`. Ver
	 *   parse_cart_login_token. A esta ruta se llega por el redirect de `/entrar/`.
	 * - Solo carrito (anterior, sigue vivo para links ya enviados): buildCartToken,
	 *   [expiresAt, limpiarFlag, batchEpochMs, sigTruncada, phone, n,
	 *   (productId, quantity, variationId) x n].
	 * En los dos casos la firma cubre expiración, limpiar, lote, teléfono y cada
	 * producto, cantidad y variación.
	 *
	 * Reglas:
	 * - limpiar=1 vacía el carrito UNA sola vez por lote (mismo criterio que
	 *   maybe_clear_cart_before_add, con el mismo marcador de sesión).
	 * - cada producto reemplaza su propia línea: abrir el link dos veces no duplica.
	 * - después redirige a /checkout/ conservando billing_phone.
	 *
	 * @since 1.3.0
	 */
	public function maybe_add_cart_from_link() {

		if ( ! class_exists( '\Hashids\Hashids' ) || ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( ! preg_match( '#/carrito/([A-Za-z0-9]+)#', $request_uri, $matches ) ) {
			return;
		}

		$store        = get_option( Chatbot_Assistant_Provision::OPTION_KEY, array() );
		$api_key_hash = $store['api_key_hash'] ?? '';
		if ( empty( $api_key_hash ) ) {
			return;
		}

		$decoded = ( new \Hashids\Hashids( $api_key_hash, 6 ) )->decode( $matches[1] );
		// Dos formatos vivos a propósito (2026-10-04): el token único de compra
		// (login + carrito, 8 + 3n números, ver parse_cart_login_token) y el de solo
		// carrito de antes (6 + 3n). Un link ya enviado tiene que seguir funcionando, así
		// que el formato viejo no se toca. Acá el login ya ocurrió en el pedido anterior
		// (`/entrar/`), así que los dos caminos terminan haciendo exactamente lo mismo:
		// vaciar por lote si corresponde, agregar los productos y mandar al checkout.
		$cart_login = $this->parse_cart_login_token( $decoded );
		if ( $cart_login ) {
			$expires_at          = $cart_login['expires_at'];
			$limpiar_flag        = $cart_login['limpiar_flag'];
			$batch_epoch_ms      = $cart_login['batch_epoch_ms'];
			$signature_truncated = $cart_login['signature_truncated'];
			$phone_digits        = $cart_login['phone_digits'];
			$items               = $cart_login['items'];
			$signed_message      = $cart_login['signed_message'];
		} else {
			if ( count( $decoded ) < 6 ) {
				$this->reject_checkout_link( 'Este link de compra no es válido.' );
				return;
			}

			// Los seis primeros números son fijos (el teléfono va en el token desde
			// 2026-10-03); después vienen 3 por producto.
			list( $expires_at, $limpiar_flag, $batch_epoch_ms, $signature_truncated, $phone_digits, $count ) = $decoded;
			if ( count( $decoded ) !== 6 + 3 * $count ) {
				$this->reject_checkout_link( 'Este link de compra no es válido.' );
				return;
			}

			$items           = array();
			$canonical_parts = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$product_id   = (int) $decoded[ 6 + 3 * $i ];
				$quantity     = (int) $decoded[ 7 + 3 * $i ];
				$variation_id = (int) $decoded[ 8 + 3 * $i ];
				$items[]           = array(
					'product_id'   => $product_id,
					'quantity'     => $quantity,
					'variation_id' => $variation_id,
				);
				$canonical_parts[] = "{$product_id}:{$quantity}:{$variation_id}";
			}
			$canonical = implode( ',', $canonical_parts );

			// Misma forma de firma que buildCartToken en services/sales.ts (prefijo "cart:").
			$signed_message = "cart:{$expires_at}:{$limpiar_flag}:{$batch_epoch_ms}:{$phone_digits}:{$canonical}";
		}

		$expected_signature           = hash_hmac( 'sha256', $signed_message, $api_key_hash );
		$expected_signature_truncated = hexdec( substr( $expected_signature, 0, 10 ) );
		$valid_signature              = hash_equals( (string) $expected_signature_truncated, (string) $signature_truncated );

		if ( ! $valid_signature || time() > $expires_at ) {
			$this->reject_checkout_link( 'Este link de compra ya venció.' );
			return;
		}

		// Vaciar el carrito una sola vez por lote. Mismo marcador de sesión que
		// maybe_clear_cart_before_add, así un link de un solo producto y este link
		// del mismo turno no se pisan entre sí.
		if ( $limpiar_flag ) {
			$last_clear_batch = WC()->session->get( 'chatbot_last_clear_batch' );
			if ( (string) $last_clear_batch !== (string) $batch_epoch_ms ) {
				WC()->cart->empty_cart();
				WC()->session->set( 'chatbot_last_clear_batch', (string) $batch_epoch_ms );
			}
		}

		foreach ( $items as $item ) {
			// Reemplaza la línea del mismo producto (o variación), no suma: así abrir
			// el link dos veces no duplica la cantidad.
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$same_product = (int) $cart_item['product_id'] === $item['product_id'];
				$same_line    = $item['variation_id']
					? $same_product && (int) $cart_item['variation_id'] === $item['variation_id']
					: $same_product;
				if ( $same_line ) {
					WC()->cart->remove_cart_item( $cart_item_key );
				}
			}

			$added = WC()->cart->add_to_cart( $item['product_id'], $item['quantity'], $item['variation_id'] );
			if ( ! $added ) {
				// Se sigue con los demás productos; el checkout muestra el carrito real.
				error_log( "[chatbot-assistant] carrito: no se pudo agregar product_id={$item['product_id']} variation_id={$item['variation_id']}" );
			}
		}

		// El teléfono viene FIRMADO en el token (2026-10-03): prellena el checkout sin
		// un parámetro aparte en la URL. 0 = el bot no lo conocía.
		$billing_phone_flag = $phone_digits > 0 ? '?billing_phone=' . (int) $phone_digits : '';
		wp_safe_redirect( home_url( '/checkout/' . $billing_phone_flag ) );
		exit;
	}

	/**
	 * Desarma el link corto de checkout (`/ir/{token}`, ver
	 * services/sales.ts:buildCheckoutToken en chatbot-backend — pedido del
	 * jefe del usuario, "hacer los links más amigables a la vista",
	 * 2026-08-22) y redirige al link largo real de siempre
	 * (`/checkout/?add-to-cart=...`), que sigue pasando por exactamente los
	 * mismos hooks ya probados (maybe_reject_expired_checkout_link,
	 * maybe_clear_cart_before_add, maybe_replace_product_qty_before_add) —
	 * nada de esa lógica se duplica acá.
	 *
	 * `hashids` empaqueta los números en un token compacto y reversible,
	 * pero OJO: no es cifrado ni firma — cualquiera que conozca el
	 * algoritmo (es público) puede desarmarlo sin el `salt`. La seguridad
	 * real sigue siendo el mismo HMAC-SHA256 de siempre: el token trae la
	 * firma truncada a 40 bits (10 hex) como último número empaquetado —
	 * acá se recalcula el HMAC completo con la MISMA copia de
	 * `api_key_hash` que ya usa maybe_reject_expired_checkout_link, y se
	 * compara la propia versión truncada contra la que venía en el token.
	 * Si alguien arma un token con productId/quantity/exp inventados, no
	 * puede producir la firma correcta porque no tiene el secreto — se
	 * rechaza igual que un link largo adulterado.
	 *
	 * El array que devuelve decode() es POSICIONAL, tiene que coincidir
	 * exacto con buildCheckoutToken() en sales.ts:
	 * [productId, quantity, expiresAt, limpiarCarritoFlag, batchEpochMs, variationId, sigTruncada]
	 *
	 * `variationId` (0 = producto simple, sin variación elegida) viaja DENTRO
	 * del mensaje firmado — no alcanza con que esté en el token nomás, si no
	 * formara parte de lo que firma el HMAC alguien podría cambiarle el
	 * variationId a un token válido sin invalidar la firma (comprar el
	 * talle/color equivocado). Al reconstruir la URL larga, si es distinto de
	 * 0 se agrega `&variation_id=...` — confirmado con una prueba real
	 * (2026-08-22, curl + Store API) que WooCommerce agrega la variación
	 * correcta al carrito con SOLO ese parámetro, sin necesitar los
	 * `attribute_*` (WC_Cart::add_to_cart() los resuelve solo a partir del
	 * variation_id).
	 *
	 * No hace falta ninguna regla de rewrite/permalink nueva — se lee la
	 * URL cruda de la request (`$_SERVER['REQUEST_URI']`), así que
	 * "/ir/token" funciona aunque WordPress no lo reconozca como una ruta
	 * propia (nunca llega a renderizar una plantilla: si el token es
	 * válido, esto redirige antes; si no, wp_die corta acá mismo).
	 *
	 * Se engancha a `wp_loaded` con prioridad 1 — la más temprana de todas.
	 *
	 * @since 1.2.0
	 */
	public function maybe_expand_short_checkout_link() {

		if ( ! class_exists( '\Hashids\Hashids' ) ) {
			// Vendor de Composer no instalado (ver
			// Chatbot_Assistant::load_dependencies) — no hay con qué
			// desarmar el token, se deja pasar sin tocar nada.
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( ! preg_match( '#/ir/([A-Za-z0-9]+)#', $request_uri, $matches ) ) {
			return;
		}
		$token = $matches[1];

		$store        = get_option( Chatbot_Assistant_Provision::OPTION_KEY, array() );
		$api_key_hash = $store['api_key_hash'] ?? '';

		if ( empty( $api_key_hash ) ) {
			// Tenant sin provisionar todavía — no hay con qué validar, se
			// deja pasar en vez de romper el flujo por un estado que no
			// debería darse en producción.
			return;
		}

		$hashids = new \Hashids\Hashids( $api_key_hash, 6 );
		$decoded = $hashids->decode( $token );

		// Token de 6 números = una acción de editarCarritoPendiente (2026-09-05,
		// ver buildCartEditToken en services/sales.ts), no una compra. Hashids
		// conserva la cantidad exacta de números empaquetados, así que la
		// longitud del array decodificado alcanza para distinguir los dos tipos
		// de token sin necesitar un prefijo de URL aparte.
		if ( 6 === count( $decoded ) ) {
			$this->apply_cart_edit_token( $decoded, $api_key_hash );
			return;
		}

		if ( count( $decoded ) !== 7 ) {
			$this->reject_checkout_link( 'Este link de compra no es válido.' );
			return;
		}

		list( $product_id, $quantity, $expires_at, $limpiar_flag, $batch_epoch_ms, $variation_id, $signature_truncated ) = $decoded;

		$expected_signature           = hash_hmac( 'sha256', "{$product_id}:{$quantity}:{$expires_at}:{$variation_id}", $api_key_hash );
		$expected_signature_truncated = hexdec( substr( $expected_signature, 0, 10 ) );
		$valid_signature              = hash_equals( (string) $expected_signature_truncated, (string) $signature_truncated );
		$expired                      = time() > $expires_at;

		if ( ! $valid_signature || $expired ) {
			$this->reject_checkout_link( 'Este link de compra ya venció.' );
			return;
		}

		// Reconstruye la URL larga de siempre — arma el MISMO formato que
		// armaba services/sales.ts antes de este cambio, así el resto del
		// flujo (maybe_reject_expired_checkout_link incluido, aunque acá ya
		// validamos nosotros mismos) no necesita saber que existió un link
		// corto en el medio.
		$clear_cart_flag   = $limpiar_flag ? "&limpiar_carrito=1&batch={$batch_epoch_ms}" : '';
		$variation_id_flag = $variation_id ? "&variation_id={$variation_id}" : '';

		// Fix #2 de "me mostró pedidos que no son mios" (2026-09-10, ver
		// comentario largo junto a checkoutUrl en services/sales.ts):
		// `billing_phone` viaja en el link CORTO como query param aparte
		// (no dentro del token firmado, ver ese comentario para el por qué)
		// — se reenvía tal cual a la URL larga para que sobreviva el
		// redirect 302 y llegue a maybe_prefill_billing_phone() en la
		// segunda request (la que sí renderiza /checkout/). Nunca rompe el
		// flujo si no viene (links viejos armados antes de este cambio).
		$billing_phone_flag = isset( $_GET['billing_phone'] ) && '' !== $_GET['billing_phone']
			? '&billing_phone=' . rawurlencode( sanitize_text_field( wp_unslash( $_GET['billing_phone'] ) ) )
			: '';

		$long_url = home_url( "/checkout/?add-to-cart={$product_id}&quantity={$quantity}&reemplazar_item=1{$variation_id_flag}{$clear_cart_flag}&exp={$expires_at}&sig={$expected_signature}{$billing_phone_flag}" );

		wp_safe_redirect( $long_url );
		exit;

	}

	/**
	 * Aplica una acción de `editarCarritoPendiente` (sacar un producto del
	 * carrito, o cambiarle la cantidad) — ver buildCartEditToken en
	 * services/sales.ts en chatbot-backend. A diferencia de una compra
	 * (`maybe_expand_short_checkout_link` con un token de 7 números), esto NO
	 * pasa por el add-to-cart nativo de WooCommerce: la acción se aplica acá
	 * mismo, directo sobre `WC()->cart` (ya disponible en `wp_loaded`), y
	 * redirige al carrito en vez de al checkout — no hay ningún producto
	 * nuevo que agregar, así que no tiene sentido reusar
	 * maybe_replace_product_qty_before_add/maybe_clear_cart_before_add (esos
	 * dos están pensados para correr ANTES de un add-to-cart que sí va a
	 * pasar).
	 *
	 * `action_type`: 1 = quitar (saca la línea entera, `value` se ignora),
	 * 2 = cambiar_cantidad (`value` = la cantidad nueva, vía
	 * `WC_Cart::set_quantity()`, función real y estable de WooCommerce
	 * — no un método propio). `variation_id` en 0 = producto simple, mismo
	 * criterio que en el token de compra.
	 *
	 * @since 1.3.0
	 * @param array  $decoded      [action_type, product_id, variation_id, value, expires_at, signature_truncada].
	 * @param string $api_key_hash Secreto compartido con chatbot-backend (mismo que usa el resto de esta clase).
	 */
	private function apply_cart_edit_token( $decoded, $api_key_hash ) {

		list( $action_type, $product_id, $variation_id, $value, $expires_at, $signature_truncated ) = $decoded;

		$expected_signature           = hash_hmac( 'sha256', "{$action_type}:{$product_id}:{$variation_id}:{$value}:{$expires_at}", $api_key_hash );
		$expected_signature_truncated = hexdec( substr( $expected_signature, 0, 10 ) );
		$valid_signature              = hash_equals( (string) $expected_signature_truncated, (string) $signature_truncated );
		$expired                      = time() > $expires_at;

		if ( ! $valid_signature || $expired ) {
			$this->reject_checkout_link( 'Este link ya venció.' );
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_safe_redirect( home_url( '/carrito/' ) );
			exit;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$same_product = (int) $cart_item['product_id'] === (int) $product_id;
			$matches_item = $variation_id ? $same_product && (int) $cart_item['variation_id'] === (int) $variation_id : $same_product;

			if ( ! $matches_item ) {
				continue;
			}

			if ( 1 === (int) $action_type ) {
				WC()->cart->remove_cart_item( $cart_item_key );
			} elseif ( 2 === (int) $action_type && $value > 0 ) {
				WC()->cart->set_quantity( $cart_item_key, $value );
			}
			break;
		}

		wp_safe_redirect( wc_get_cart_url() );
		exit;

	}

	/**
	 * Rechaza un link de checkout del bot (add-to-cart con `exp`/`sig`) si
	 * venció o si la firma no coincide — ver services/sales.ts en
	 * chatbot-backend, que arma `exp`/`sig` con HMAC-SHA256 usando
	 * `api_key_hash` como secreto compartido. No hace falta provisionar
	 * ningún secreto nuevo: los dos lados YA lo conocen (el Backend lo
	 * guarda como tenant.api_key_hash, este plugin lo calcula localmente al
	 * registrar el tenant y ya lo usa para autenticar los webhooks del
	 * Backend hacia acá, ver
	 * Chatbot_Assistant_Provision::backend_webhook_permission_check).
	 *
	 * Antes, un link de checkout armado por el bot quedaba válido PARA
	 * SIEMPRE — un cliente que volvía a tocar un link viejo de una
	 * conversación de hace días (con una cantidad que ya no aplicaba, o de
	 * un producto que ya no quería) terminaba con un carrito equivocado sin
	 * ningún aviso. Los links SIN `exp`/`sig` (un "Agregar al carrito"
	 * normal del sitio, o cualquier link que no venga del bot) no se tocan
	 * — este chequeo es exclusivo de los links que el bot firma.
	 *
	 * Se engancha a `wp_loaded` con prioridad 4 — antes que
	 * maybe_clear_cart_before_add/maybe_replace_product_qty_before_add (5)
	 * y que WC_Form_Handler::add_to_cart_action() (20), para poder anular
	 * el add-to-cart entero si el link no es válido.
	 *
	 * `variation_id` (2026-08-22) entra al mensaje firmado igual que en
	 * maybe_expand_short_checkout_link — tiene que ser EXACTAMENTE la misma
	 * fórmula en los dos lados, porque el link corto redirige a esta misma
	 * URL larga y esta función la vuelve a validar de cero. 0 cuando no
	 * viene en la URL (producto simple, sin variación).
	 *
	 * @since 1.1.0
	 */
	public function maybe_reject_expired_checkout_link() {

		if ( ! isset( $_GET['add-to-cart'], $_GET['exp'], $_GET['sig'] ) ) {
			return;
		}

		$product_id   = absint( $_GET['add-to-cart'] );
		$quantity     = isset( $_GET['quantity'] ) ? absint( $_GET['quantity'] ) : 1;
		$expires_at   = absint( $_GET['exp'] );
		$variation_id = isset( $_GET['variation_id'] ) ? absint( $_GET['variation_id'] ) : 0;
		$signature    = sanitize_text_field( wp_unslash( $_GET['sig'] ) );

		$store        = get_option( Chatbot_Assistant_Provision::OPTION_KEY, array() );
		$api_key_hash = $store['api_key_hash'] ?? '';

		if ( empty( $api_key_hash ) ) {
			// Tenant sin provisionar todavía — no hay con qué validar, se
			// deja pasar en vez de romper el flujo por un estado que no
			// debería darse en producción.
			return;
		}

		$expected_signature = hash_hmac( 'sha256', "{$product_id}:{$quantity}:{$expires_at}:{$variation_id}", $api_key_hash );
		$valid_signature    = hash_equals( $expected_signature, $signature );
		$expired            = time() > $expires_at;

		if ( $valid_signature && ! $expired ) {
			return;
		}

		$this->reject_checkout_link( 'Este link de compra ya venció.' );

	}

	/**
	 * wp_die compartido por los dos guards de link de checkout — el largo
	 * (maybe_reject_expired_checkout_link) y el corto
	 * (maybe_expand_short_checkout_link) — para cualquier motivo de
	 * rechazo (vencido, firma inválida, token corrupto). Antes de mostrar
	 * el error, limpia los parámetros de add-to-cart de $_GET: ninguno debe
	 * llegar a WC_Form_Handler::add_to_cart_action() (prioridad 20) — si
	 * dejamos pasar 'add-to-cart', WooCommerce agrega el producto igual
	 * aunque le mostremos un error al cliente después.
	 *
	 * @since 1.2.0
	 */
	/**
	 * Token único de compra (2026-10-04): un solo token lleva login + carrito.
	 *
	 * Antes el link llevaba DOS tokens — el de login en la ruta y el del carrito en
	 * `?next=%2Fcarrito%2F…` — y eso sumaba 44 caracteres a un link que el cliente
	 * recibe por WhatsApp. Ahora los dos viajan juntos y el destino lo arma el propio
	 * handler de `/entrar/`, así que el cliente ve `{sitio}/entrar/{token}` y nada más.
	 *
	 * Layout (services/sales.ts, buildCartLoginToken): [wc_customer_id, expires_at,
	 * limpiar, batch, sigTruncada, nonce, phone, n, (productId, quantity, variationId) x n].
	 * Son 8 + 3n números, 11 como mínimo. No se confunde con los formatos viejos: el de
	 * login tiene 3 o 4 números, y el de carrito 6 + 3n (que deja resto 0 al dividir por
	 * 3, mientras este deja resto 2).
	 *
	 * La firma cubre todo junto, incluido A QUÉ CLIENTE entra el link: nadie puede
	 * agarrar un token y cambiarle la cuenta, el lote, el teléfono ni los productos.
	 *
	 * @param array $decoded Números ya decodificados por hashids.
	 * @return array|false Datos del token, o false si no tiene esta forma.
	 * @since 1.4.0
	 */
	private function parse_cart_login_token( $decoded ) {
		$total = count( $decoded );
		if ( $total < 11 || 2 !== $total % 3 ) {
			return false;
		}

		list( $wc_customer_id, $expires_at, $limpiar_flag, $batch_epoch_ms, $signature_truncated, $nonce, $phone_digits, $item_count ) = $decoded;
		if ( $total !== 8 + 3 * (int) $item_count ) {
			return false;
		}

		$items           = array();
		$canonical_parts = array();
		for ( $i = 0; $i < (int) $item_count; $i++ ) {
			$product_id   = (int) $decoded[ 8 + 3 * $i ];
			$quantity     = (int) $decoded[ 9 + 3 * $i ];
			$variation_id = (int) $decoded[ 10 + 3 * $i ];

			$items[]           = array(
				'product_id'   => $product_id,
				'quantity'     => $quantity,
				'variation_id' => $variation_id,
			);
			$canonical_parts[] = "{$product_id}:{$quantity}:{$variation_id}";
		}
		$canonical = implode( ',', $canonical_parts );

		return array(
			'wc_customer_id'      => (int) $wc_customer_id,
			'expires_at'          => (int) $expires_at,
			'limpiar_flag'        => (int) $limpiar_flag,
			'batch_epoch_ms'      => (int) $batch_epoch_ms,
			'signature_truncated' => $signature_truncated,
			'phone_digits'        => (int) $phone_digits,
			'items'               => $items,
			'signed_message'      => "cartlogin:{$wc_customer_id}:{$expires_at}:{$limpiar_flag}:{$batch_epoch_ms}:{$nonce}:{$phone_digits}:{$canonical}",
		);
	}

	private function reject_checkout_link( $reason ) {

		unset( $_GET['add-to-cart'], $_GET['quantity'], $_GET['reemplazar_item'], $_GET['limpiar_carrito'], $_GET['variation_id'], $_GET['exp'], $_GET['sig'] );

		wp_die(
			'<p>' . esc_html( $reason ) . ' Volvé al chat y pedí uno nuevo para completar tu compra.</p>' .
			'<p><a href="' . esc_url( home_url() ) . '">Volver a la tienda</a></p>',
			'Link vencido',
			array( 'response' => 410, 'back_link' => false )
		);

	}

}
