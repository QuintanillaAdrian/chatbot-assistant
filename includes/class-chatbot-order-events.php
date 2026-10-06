<?php
/**
 * Avisos de pedido hacia el Backend.
 *
 * Por qué existe (2026-10-05): el backend no tenía forma de enterarse de que el
 * cliente completó la compra. Lo averiguaba preguntándole a WooCommerce en cada
 * turno ("¿este cliente hizo algún pedido después de armarse este carrito?"), y
 * solo si el carrito que el bot venía rastreando ya tenía más de 2 minutos. Un
 * cliente que pagaba y enseguida pedía otro producto recibía un link que le
 * volvía a meter lo que acababa de comprar.
 *
 * Acá se invierte: WooCommerce habla primero. Apenas el pedido existe, el plugin
 * avisa y el backend borra el carrito que estaba rastreando para esa
 * conversación. El sondeo del otro lado se deja igual, como red — si este aviso
 * no llega (sitio caído, backend inalcanzable, pedido creado a mano desde el
 * panel), el backend se entera igual en el turno siguiente.
 *
 * @package Chatbot_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatbot_Order_Events {

	/**
	 * Marca en el propio pedido que ya se avisó, para no avisar dos veces.
	 * Los dos hooks de abajo pueden dispararse para el mismo pedido (según el
	 * medio de pago, uno u otro puede no llegar nunca — por eso se enganchan
	 * los dos, y por eso hace falta esta marca).
	 */
	const NOTIFIED_META = '_chatbot_backend_notified';

	/**
	 * Clave de sesión donde se guarda el teléfono REAL de la conversación, tal
	 * como vino dentro del token firmado del link del bot (lo escribe
	 * Chatbot_Assistant_Public::maybe_prefill_billing_phone al abrir el link).
	 */
	const SESSION_PHONE_KEY = 'chatbot_conversation_phone';

	/**
	 * Meta del pedido donde se sella ese mismo teléfono. La sesión no sirve
	 * sola: `woocommerce_payment_complete` puede dispararse en OTRA petición
	 * (una vuelta del medio de pago, un pago aplicado desde el panel), donde
	 * no hay sesión del cliente. Copiándolo al pedido apenas existe, el dato
	 * sobrevive a eso.
	 */
	const CONVERSATION_PHONE_META = '_chatbot_conversation_phone';

	/**
	 * Avisa al backend que este pedido existe, para que deje de considerar
	 * pendiente el carrito que venía rastreando.
	 *
	 * Nunca interrumpe el checkout: la llamada es no bloqueante (el cliente no
	 * espera por ella) y cualquier problema se registra en el log sin afectar
	 * al pedido. Si falla, la red del lado del backend lo cubre.
	 *
	 * @param int $order_id ID del pedido de WooCommerce.
	 */
	public static function notify_order_created( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( self::NOTIFIED_META ) ) {
			return;
		}

		// El teléfono es lo que identifica la conversación: en WhatsApp el
		// cliente ES su número. Sin teléfono no hay a quién avisarle, así que
		// no vale la pena ni hacer la llamada.
		list( $phone, $phone_source ) = self::resolve_conversation_phone( $order );
		if ( '' === trim( (string) $phone ) ) {
			return;
		}

		$backend_url = Chatbot_Assistant_Backend_Client::get_backend_url();
		$api_key     = get_option( 'chatbot_api_key_plain', '' );
		if ( null === $backend_url || '' === $api_key ) {
			return;
		}

		// Se marca ANTES de mandar: si la llamada se cuelga o el pedido se
		// vuelve a guardar, no se duplica el aviso. Un aviso perdido lo cubre
		// la red del backend; uno duplicado sería ruido sin ninguna ventaja.
		$order->update_meta_data( self::NOTIFIED_META, '1' );
		$order->save();

		$response = wp_remote_post(
			$backend_url . '/webhooks/woocommerce',
			array(
				// No bloqueante: el cliente que acaba de pagar no tiene por qué
				// esperar a que el backend conteste.
				'blocking'  => false,
				'timeout'   => 1,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'      => wp_json_encode(
					array(
						'event'        => 'order_created',
						'order_id'     => $order->get_id(),
						'phone'        => $phone,
						'customer_id'  => $order->get_customer_id(),
						// De dónde salió el teléfono: 'link' (sellado del token
						// firmado, confiable) o 'billing' (lo que quedó en el
						// formulario, puede no corresponder a la conversación).
						// Solo para diagnóstico — el backend lo registra en el
						// log, no cambia ninguna decisión.
						'phone_source' => $phone_source,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Chatbot Assistant] No se pudo avisar del pedido ' . $order->get_id() . ' al backend: ' . $response->get_error_message() );
		}
	}

	/**
	 * Decide con qué teléfono avisar, y lo sella en el pedido (2026-10-05).
	 *
	 * El backend encuentra la conversación armando una clave con el teléfono
	 * (`tenant#whatsapp#<dígitos>`); si no coincide, no hay plan B — el
	 * `customer_id` solo sirve para CONFIRMAR al dueño, nunca para buscar. Así
	 * que usar el teléfono de facturación a secas es frágil: el campo del
	 * checkout viene precargado con el correcto pero es editable, y lo más
	 * común es que el cliente lo reescriba sin el código de país ("72587929"
	 * en vez de "50672587929"). Ahí el pedido se crea y se paga bien, pero el
	 * backend sigue creyendo que ese carrito quedó pendiente.
	 *
	 * Orden de preferencia:
	 *   1. La meta del pedido, si ya se selló antes (incluye el caso de
	 *      `woocommerce_payment_complete` en otra petición, sin sesión).
	 *   2. La sesión, escrita al abrir el link del bot con el número que venía
	 *      DENTRO del token firmado — la identidad real de la conversación. Se
	 *      copia al pedido en el acto, para que sobreviva a la sesión.
	 *   3. El teléfono de facturación, como estaba antes. Sigue siendo lo
	 *      correcto cuando el pedido no vino de un link del bot.
	 *
	 * @param WC_Order $order Pedido recién creado o pagado.
	 * @return array{0:string,1:string} Teléfono y de dónde salió ('link'|'billing').
	 */
	private static function resolve_conversation_phone( $order ) {

		$sealed = (string) $order->get_meta( self::CONVERSATION_PHONE_META );
		if ( '' !== trim( $sealed ) ) {
			return array( $sealed, 'link' );
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$from_session = (string) WC()->session->get( self::SESSION_PHONE_KEY, '' );
			if ( '' !== trim( $from_session ) ) {
				// Se sella ahora que el pedido existe. El save() lo hace igual
				// notify_order_created al marcar NOTIFIED_META, pero no se
				// confía en ese orden: si alguna vez se separan, este dato no
				// puede perderse.
				$order->update_meta_data( self::CONVERSATION_PHONE_META, $from_session );
				$order->save();
				return array( $from_session, 'link' );
			}
		}

		return array( (string) $order->get_billing_phone(), 'billing' );
	}
}
