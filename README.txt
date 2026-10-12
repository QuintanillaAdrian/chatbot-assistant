=== Chatbot Assistant ===
Contributors: quintanilla
Tags: woocommerce, whatsapp, chatbot, telegram
Requires at least: 6.9
Requires PHP: 8.2
Stable tag: 1.0.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Asistente de ventas por WhatsApp (o Telegram) para tiendas WooCommerce.

== Description ==

Conecta la tienda con el Backend del chatbot. El cliente le escribe al bot, el bot consulta el
catálogo real, arma links de compra, revisa pedidos y los puede cancelar.

El plugin:

* Registra la tienda en el Backend y le crea una clave de la API de WooCommerce (onboarding
  automático, pasos 1 y 2, al abrir wp-admin por primera vez).
* Ofrece un panel (wp-admin → Asistente Chatbot) para conectar WhatsApp o Telegram, personalizar
  el bot y activarlo.
* Atiende los links de compra que manda el bot (`/entrar/`, `/carrito/`, `/ir/`).
* Le avisa al Backend cuando se crea un pedido.

== Installation ==

La guía completa (requisitos, HTTPS detrás de NGINX, WhatsApp, problemas comunes) está en
`HOW_IT_WORKS.md`. En resumen:

1. WordPress con WooCommerce activo, en HTTPS y con enlaces permanentes distintos de "Simple".
2. Variable de entorno `CHATBOT_BACKEND_URL` en el servidor (la dirección del Backend).
3. Copiar esta carpeta, **con `vendor/`** (o correr `composer install --no-dev`), a
   `wp-content/plugins/chatbot-assistant` y activar el plugin.
4. Abrir wp-admin → Asistente Chatbot y seguir los pasos 3 a 5.

== Changelog ==

= 1.0.2 =
* Seguridad: los links del bot y las llamadas del Backend a la tienda usan un secreto de links que
  se deriva de la API key (antes el hash de la clave, que el Backend guarda en claro). Los links de
  versiones anteriores dejan de valer.
* Firma de los links de 64 bits (antes 40) y vencimiento de no más de una hora.
* Se quitan los formatos de link viejos (login suelto con `?next=`, carrito suelto).

= 1.0.1 =
* Limpieza: un solo script en el panel (antes "Guardar configuración" y "Activar" mandaban dos
  pedidos al Backend), sin archivos públicos vacíos, sin la ruta anónima `/tenant/register`.
* Formulario "Conexión directa" de WhatsApp.
* Al desinstalar, borra sus opciones y la clave de WooCommerce que creó.
* El log de PHP registra solo las llamadas al Backend que fallan (todas, con `WP_DEBUG`).
* La información técnica del panel (estado y bitácora) solo se ve en modo soporte
  (`CHATBOT_DEBUG` en `wp-config.php`), con las credenciales ocultas. Comando nuevo
  `wp chatbot audit`.
* El paso del onboarding ya no baja al reconectar un canal o guardar la configuración con el bot
  activo.

= 1.0.0 =
* Primera versión.
