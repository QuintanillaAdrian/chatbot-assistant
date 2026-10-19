# Chatbot Assistant — Guía de instalación y funcionamiento

Plugin de WordPress que conecta una tienda **WooCommerce** con un asistente de ventas por
**WhatsApp** (o Telegram). El cliente le escribe al bot, el bot consulta el catálogo real de la
tienda, arma links de compra, revisa pedidos y los puede cancelar.

> **Para quién es esta guía:** para quien instala el plugin en su propio WordPress. Si seguís los
> pasos en orden, el registro de la tienda y la conexión con WooCommerce se hacen solos; WhatsApp,
> la personalización y la activación se terminan desde el panel del plugin.

---

## 1. Cómo encaja todo

```
 Cliente (WhatsApp)                                  Backend del chatbot (AWS)
        │                                         ┌──────────────────────────────┐
        └──────────────► Meta ──────────────────► │ API Gateway + Lambdas        │
                                                  │ - recibe mensajes            │
                                                  │ - IA que redacta respuestas  │
                                                  │ - lee la tienda vía API WC   │
                                                  └──────────────┬───────────────┘
                                                                 │ HTTPS
                                                                 ▼
 ┌──────────────────────── Servidor de la tienda ─────────────────────────────┐
 │  NGINX (HTTPS, proxy inverso)                                              │
 │     │  reenvía con X-Forwarded-Proto: https                                │
 │     ▼                                                                      │
 │  Contenedor Docker: WordPress + WooCommerce + este plugin                  │
 │     - registra la tienda en el Backend (onboarding)                        │
 │     - genera las claves de la API de WooCommerce para el Backend           │
 │     - atiende los links de compra (/entrar/…, /carrito/…, /ir/…)           │
 │     - avisa al Backend cuando se crea un pedido                            │
 └────────────────────────────────────────────────────────────────────────────┘
```

Tres ideas para entender el resto:

1. **El plugin nunca habla con WhatsApp ni con la IA.** Todo pasa por el Backend.
2. **El Backend sí entra a la tienda** (por la API de WooCommerce y por rutas propias del plugin).
   Por eso la tienda tiene que ser **accesible desde internet con HTTPS**.
3. **La tienda se identifica por su dirección pública** (la que WordPress tiene como "Dirección del
   sitio"). Esa dirección es la que el Backend usa para todo: consultar productos y armar los links
   que recibe el cliente. Si WordPress cree que es `http://…` o `localhost`, nada funciona.

---

## 2. Antes de instalar — checklist

| # | Requisito | Por qué | Cómo se verifica |
|---|---|---|---|
| 1 | WooCommerce instalado y **activo** | El plugin genera claves de su API | wp-admin → Plugins |
| 2 | Un usuario **administrador** | Las claves de WooCommerce se crean a su nombre | wp-admin → Usuarios |
| 3 | Dominio público con **HTTPS** | El Backend entra a la tienda desde AWS; WooCommerce rechaza sus claves si la petición no es HTTPS | Abrir `https://tu-dominio/wp-json/` desde fuera del servidor |
| 4 | WordPress sabe que está en **HTTPS** (detrás de NGINX) | Ver [sección 4](#4-https-detrás-de-nginx) | wp-admin → Ajustes → Generales muestra `https://tu-dominio` |
| 5 | **Enlaces permanentes** distintos de "Simple" | Las rutas `/wp-json/...` no existen con "Simple" | wp-admin → Ajustes → Enlaces permanentes |
| 6 | Variable de entorno **`CHATBOT_BACKEND_URL`** en el contenedor | El plugin la lee para saber dónde está el Backend | [sección 3.2](#32-configurar-la-dirección-del-backend) |
| 7 | La carpeta **`vendor/`** dentro del plugin (`composer install --no-dev`) | Sin ella **ningún link de compra funciona** | [sección 3.1](#31-conseguir-el-plugin-completo) |
| 8 | PHP 8.2 o más con las extensiones **`mbstring`** y **`bcmath`** (o `gmp`) | La librería de los links (hashids) las usa. Composer solo *sugiere* `bcmath`/`gmp`: la instalación termina bien sin ellas, pero cada link del bot da error al abrirse | `php -m \| grep -E "mbstring\|bcmath\|gmp"` en el servidor (la imagen oficial de Docker de WordPress ya las trae) |

---

## 3. Instalación paso a paso

### 3.1 Conseguir el plugin completo

El plugin usa una librería de Composer (`hashids`) para los links de compra (`/entrar/…`, `/carrito/…` y `/ir/…`).
Esa librería vive en `vendor/`, que **no está en el repositorio de Git**: se instala con Composer.

1. Pedile a Adrian acceso al repositorio de GitHub (es privado).
2. Cloná el repositorio en `wp-content/plugins/chatbot-assistant`.
3. Dentro de esa carpeta, corré:

   ```bash
   composer install --no-dev
   ```

   Instala exactamente la versión fijada en `composer.lock`. Necesita Composer en el servidor y las
   extensiones de PHP del punto 8 del checklist (`bcmath` o `gmp`).

Para actualizar el plugin después: `git pull` y otra vez `composer install --no-dev`. **Nunca lo
borres para reinstalarlo** (ver la advertencia de abajo y la [sección 7.1](#71-borrar-el-plugin-da-de-baja-la-tienda-desde-la-104)).

> ⚠️ **Borrar el plugin da de baja la tienda** (desde la 1.0.4). Al borrarlo (Plugins → Borrar), el
> plugin le pide al Backend la baja: se sueltan el número de WhatsApp y el bot de Telegram, y se
> borran la configuración del bot y los datos de los clientes. Si después se vuelve a instalar, es
> una tienda nueva y hay que repetir los 5 pasos del onboarding.
>
> Antes de la 1.0.4 la baja no existía: la tienda vieja quedaba con el número de WhatsApp y el paso 3
> de la nueva fallaba con "Este número ya está conectado a otra tienda" (pasó tres veces entre el
> 2026-10-12 y el 2026-10-15). Si pasa igual (por ejemplo, si el servidor no tenía conexión al
> Backend al borrar el plugin), pedile a Adrian que dé de baja la tienda vieja.

> ⚠️ Sin `vendor/` el plugin se activa y el onboarding funciona igual, pero los links que manda el
> bot **no hacen nada** al abrirlos, sin ningún error visible. Es el fallo más difícil de notar.

### 3.2 Configurar la dirección del Backend

El plugin lee la dirección del Backend **solo** de la variable de entorno `CHATBOT_BACKEND_URL`
(no hay valor por defecto, a propósito). Va **sin** `/api` al final; si se lo ponés, igual funciona.
El valor real te lo pasa Adrian.

En el `docker-compose.yml` de WordPress:

```yaml
services:
  wordpress:
    image: wordpress:6.9.1-php8.2-apache
    environment:
      CHATBOT_BACKEND_URL: https://<id>.execute-api.us-east-2.amazonaws.com/prod
      # ... el resto de tus variables
```

O con `docker run`: `-e CHATBOT_BACKEND_URL=https://...`

> Si falta, el onboarding **no avanza y no muestra error en pantalla**; solo queda en el log de PHP
> la línea `Falta configurar la variable de entorno CHATBOT_BACKEND_URL`.

### 3.3 Instalar y activar

1. Con el plugin ya en `wp-content/plugins/chatbot-assistant` y `vendor/` instalado (3.1).
2. Activá el plugin.
3. **Entrá a cualquier pantalla de wp-admin.** Ahí arranca solo el onboarding (pasos 1 y 2). Activar
   el plugin no alcanza: el disparo ocurre al cargar una página de administración.
4. Abrí **wp-admin → Asistente Chatbot** y verificá que el progreso esté en el paso 2 completo.

---

## 4. HTTPS detrás de NGINX

Con NGINX adelante, el contenedor recibe todo por HTTP aunque el cliente haya entrado por HTTPS.
Si WordPress no se entera, pasan tres cosas:

- el plugin registra la tienda en el Backend con una dirección `http://`;
- WooCommerce **rechaza las claves de su API** cuando la petición llega por HTTP (exige HTTPS para
  autenticación básica) → el paso 2 falla;
- los links de compra salen con `http://`.

Se resuelve en dos partes.

### 4.1 NGINX: avisar que la conexión original era HTTPS

```nginx
location / {
    proxy_pass         http://127.0.0.1:8080;   # puerto del contenedor
    proxy_set_header   Host              $host;
    proxy_set_header   X-Forwarded-Proto $scheme;
    proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header   Authorization     $http_authorization;
}
```

La imagen oficial `wordpress` de Docker ya trae en su `wp-config.php` el bloque que lee
`X-Forwarded-Proto` y marca la petición como HTTPS; con que NGINX mande el encabezado, alcanza. El
`Authorization` tiene que llegar intacto: el Backend lo usa para autenticarse contra las rutas del
plugin.

### 4.2 WordPress: la dirección del sitio con HTTPS, desde una variable de entorno

El plugin usa la dirección que tiene WordPress (`home`). Para fijarla desde el entorno, sin tocarla
a mano en la base:

```yaml
services:
  wordpress:
    environment:
      SITE_URL: https://tu-dominio.com
      WORDPRESS_CONFIG_EXTRA: |
        define('WP_HOME',    getenv('SITE_URL'));
        define('WP_SITEURL', getenv('SITE_URL'));
```

`WORDPRESS_CONFIG_EXTRA` lo inserta la imagen oficial dentro de `wp-config.php`. Con `WP_HOME` y
`WP_SITEURL` definidos, la dirección queda fija (wp-admin la muestra gris, no editable).

> ⚠️ Las variables `WORDPRESS_HOME` / `WORDPRESS_SITE_URL` **no las usa** la imagen oficial: ponerlas
> en el compose no cambia nada. Hace falta `WP_HOME`/`WP_SITEURL` como arriba.
>
> ℹ️ Esta es la forma correcta: la dirección sale de WordPress, se configura una sola vez y la usan
> WordPress, WooCommerce y el plugin por igual. La alternativa que usa el equipo hoy está en 4.3.

### 4.3 Alternativa en uso: el plugin lee la dirección de una variable de entorno ⚠️ deuda técnica

Es la forma que usa el equipo hoy para la migración (decisión del 2026-10-08): la dirección pública
con HTTPS se pone en la variable de entorno **`SITE_DOMAIN`** del contenedor y **el plugin** la lee
(desde la 1.0.3) para informársela al Backend, en lugar de configurarla en WordPress como en 4.2:

```yaml
services:
  wordpress:
    environment:
      SITE_DOMAIN: https://tu-dominio.com   # o solo tu-dominio.com: se asume https://
```

El plugin la usa en los dos datos que le manda al Backend: la dirección de la tienda (paso 1) y la de
la API de WooCommerce (paso 2). Si la variable no está o está vacía, usa la de WordPress
(`get_home_url()`), como antes. Se lee solo del entorno, igual que `CHATBOT_BACKEND_URL` (no de un
archivo `.env`).

Funciona para registrar la tienda, pero **no es la forma correcta** y está anotada para corregirse
(ítem L9 de `PLAN_DE_CORRECCIONES.md` en el repositorio del Backend):

- **Quedan dos direcciones para la misma tienda.** El plugin le dice al Backend la de la variable,
  pero WordPress y WooCommerce siguen usando la suya para todo lo demás: redirecciones, la página de
  pago, las cookies de sesión y la API. Si no son idénticas, el cliente abre el link por un dominio y
  WooCommerce lo manda o le arma la sesión en otro: llega sin sesión o con el carrito vacío.
- **La API de WooCommerce sigue dependiendo de que WordPress sepa que está en HTTPS** (4.1). La
  variable no lo arregla: si falta `X-Forwarded-Proto`, el paso 2 falla igual.
- **El Backend guarda la dirección una sola vez**, al registrar la tienda. Cambiar la variable después
  no la actualiza. Por eso tiene que estar puesta **antes** de activar el plugin (o del primer ingreso
  a wp-admin, que es cuando se registra la tienda).

Si se usa esta alternativa, la variable tiene que ser **exactamente** la misma dirección que WordPress
muestra en Ajustes → Generales (mismo `https://`, mismo dominio, sin `/` final).

La corrección futura es volver a una sola fuente: la de WordPress (4.2), con la variable de entorno
alimentando `WP_HOME`/`WP_SITEURL` en vez de leerse desde el plugin.

**Cómo verificar:** wp-admin → Ajustes → Generales muestra `https://tu-dominio.com` en "Dirección de
WordPress" y "Dirección del sitio". Desde fuera del servidor, `https://tu-dominio.com/wp-json/wc/v3`
responde 200 con la lista de rutas de WooCommerce, y `.../wp-json/wc/v3/products` responde 401 (existe
y pide credenciales, que es lo esperado).

---

## 5. El onboarding: los 5 pasos

| Paso | Qué pasa | ¿Automático? |
|---|---|---|
| **1. Registro** | El plugin genera una API key, registra la tienda en el Backend (`POST /tenants`) con su dirección pública y guarda el `tenant_id`. | ✅ Al abrir wp-admin |
| **2. WooCommerce** | Crea un par de claves de la API de WooCommerce (descripción `provisioning`, lectura/escritura) y el Backend las prueba contra la tienda (`POST /tenants/{id}/wc/validate`). Si falla, reintenta solo con espera creciente (30 s → 5 min). | ✅ En el mismo request que el paso 1 |
| **3. Canal** | WhatsApp (conexión directa con los datos de Meta) o Telegram. Ver [sección 6](#6-whatsapp-cómo-conectarlo). | ❌ Manual, un formulario |
| **4. Personalización** | Nombre del bot, mensaje de bienvenida y trato al cliente (`PATCH /config`). La bienvenida es lo que recibe quien abre el bot de Telegram (`/start`), tal cual, sin pasar por la IA (escribila con el mismo trato que elegiste); sin bienvenida, la IA saluda sola. El trato (de vos o de usted) vale para todos los mensajes del bot, también los que arma el sistema. Cómo atiende el bot (tono y reglas) es igual para todas las tiendas: desde la 1.0.5 no hay instrucciones propias, porque un texto libre podía contradecir las reglas del bot (por ejemplo, "mandá al cliente a la ficha del producto" frente al link de compra que arma el bot). | ❌ Manual, desde el panel |
| **5. Activación** | Exige WooCommerce verificado y al menos un canal conectado (`POST /activate`). | ❌ Manual, un botón |

Al abrir el panel del plugin, el estado se vuelve a leer del Backend (`GET /status`), así que lo que
se ve ahí es la fuente de verdad. Si el Backend ya no reconoce la tienda (respuesta
`INVALID_API_KEY`), el plugin borra su estado local y se vuelve a registrar solo.

### Estado esperado después de los pasos 1 y 2

```bash
wp chatbot status
```

Tiene que mostrar un `tenant_id` y `wc_verified: true`. Si no, forzalo:

```bash
wp chatbot provision-auto
```

Con Docker, WP-CLI corre en un contenedor aparte (por ejemplo la imagen `wordpress:cli`) montado
sobre el mismo `/var/www/html`, y ese contenedor también necesita `CHATBOT_BACKEND_URL`. Sin WP-CLI,
el mismo estado se ve en wp-admin → Asistente Chatbot.

Directo en la base:

```sql
SELECT option_value FROM wp_options WHERE option_name = 'chatbot_provision';
SELECT key_id, description, permissions, truncated_key
FROM wp_woocommerce_api_keys WHERE description = 'provisioning';
```

---

## 6. WhatsApp: cómo conectarlo

En el paso 3 del panel hay dos opciones para WhatsApp:

| Opción | Estado |
|---|---|
| Botón **"Conectar WhatsApp"** (inicio de sesión con Meta) | ⏸️ Deshabilitado. El regreso desde Facebook todavía no se completa en el Backend (Meta vuelve con una visita del navegador y la ruta espera una llamada de servidor). Se mantiene para cuando esté listo. |
| **Conexión directa** (formulario debajo del botón) | ✅ La que se usa hoy. |

### 6.1 Conexión directa, paso a paso

1. En [Meta for Developers](https://developers.facebook.com/) entrá a **la App del chatbot** →
   WhatsApp → **API Setup**. El token tiene que ser de esa App: es la que tiene configurado el
   webhook que manda los mensajes al Backend. Un token de otra App conecta el número, pero los
   mensajes no llegan.
2. Copiá los tres datos:
   - **Phone number ID** (no es el número de teléfono: es un ID numérico largo)
   - **WhatsApp Business Account ID**
   - **Token de acceso**. El temporal de API Setup vence en 24 horas; para producción, un token de
     usuario del sistema, que no vence.
3. En wp-admin → **Asistente Chatbot** → Paso 3 → **Conexión directa**, pegalos y tocá
   **Conectar WhatsApp**.
4. El Backend verifica los tres datos contra Meta, suscribe el webhook, guarda el token **cifrado** y
   el panel muestra el número conectado. El token no queda guardado en WordPress.
5. Seguí con los pasos 4 y 5.

### 6.2 Si da error

| Mensaje | Qué significa | Qué hacer |
|---|---|---|
| Meta no aceptó estos datos | El token venció o es de otra App, o el Phone number ID no es de ese WABA | Revisar los tres datos; generar un token nuevo |
| Este número ya está conectado a otra tienda | **Un número, una tienda**: el Backend identifica la tienda por el número. Si estuviera en dos, los mensajes irían a la equivocada | Pedile a Adrian que lo desconecte de la tienda anterior (por ejemplo, la de pruebas) y volvé a intentar |
| No se pudo suscribir el webhook | Meta rechazó la suscripción o falló el guardado | Reintentar; si sigue, revisar los permisos del token (`whatsapp_business_management`, `whatsapp_business_messaging`) |

Reconectar el **mismo** número en la **misma** tienda (por ejemplo, con un token nuevo) sí se puede:
"¿Reconectar o cambiar de número?" vuelve a mostrar el formulario.

### 6.3 Telegram

Funciona desde el mismo paso 3: se pega el token del bot (de @BotFather) y queda conectado en un solo
paso. Alcanza con un canal (WhatsApp o Telegram) para activar el bot.

---

## 7. Qué hace el plugin además del onboarding

| Función | Cómo |
|---|---|
| **Links de compra** | `/entrar/{token}` inicia sesión como el cliente; `/carrito/{token}` carga todos los productos de la compra; `/ir/{token}` agrega o cambia un producto. Los arma el Backend; el plugin los valida (firma de 64 bits con el secreto de links, vencimiento futuro y de no más de una hora) y los ejecuta. |
| **Aviso de pedido nuevo** | Al crearse un pedido (checkout clásico o de bloques, o pago completado) avisa al Backend, que borra el carrito pendiente de esa conversación. |
| **Búsqueda por teléfono** | `GET /wp-json/chatbot/v1/customers/lookup-by-phone` y `/orders/lookup-by-phone`: el Backend identifica al cliente y sus pedidos (también los de invitado). Compatible con HPOS y con el almacenamiento clásico. |
| **Webhooks de estado** | `POST /wp-json/chatbot/v1/webhook/backend`: el Backend avisa activación, suspensión, credenciales inválidas, etc. |

Las rutas que llama el Backend se autentican con `Authorization: Bearer <secreto de links>`; las del
panel, con la sesión de administrador de WordPress.

**El secreto de links** (desde la 1.0.2) firma los links y autentica al Backend ante la tienda. No se
configura ni viaja por la red: el plugin y el Backend lo calculan cada uno a partir de la API key de
la tienda (`Chatbot_Assistant_Provision::link_secret`). El Backend lo guarda cifrado la primera vez
que el plugin le hace un pedido, que pasa en el paso 2 del onboarding o al abrir wp-admin. Si el bot
no puede armar links ("sin link_secret_enc" en el log del Backend), alcanza con abrir wp-admin una vez.

### 7.1 Borrar el plugin da de baja la tienda (desde la 1.0.4)

| Acción en wp-admin | Qué pasa |
|---|---|
| **Desactivar** | Nada se borra. El bot sigue registrado; al reactivar, sigue todo igual. |
| **Actualizar** | No se borra: `git pull` y `composer install --no-dev` (sección 3.1). |
| **Borrar** (Plugins → Borrar) | **Se da de baja la tienda.** Es definitivo. |

Al borrarlo, `uninstall.php` hace esto, en este orden:

1. **Le pide la baja al Backend** (`DELETE /api/tenants/{id}`, autenticado con la API key de la
   tienda). El Backend:
   - suelta el **número de WhatsApp**, que queda libre para conectarlo en otra tienda;
   - le pide a **Telegram** que el bot deje de mandarle mensajes;
   - borra las claves de WooCommerce que tenía guardadas, los datos de los clientes de la tienda
     (qué email corresponde a cada teléfono) y la tienda misma, con la configuración del bot.
2. **Borra la clave de la API de WooCommerce** que creó el onboarding (paso 2).
3. **Borra sus opciones** de WordPress, entre ellas la API key de la tienda.

La baja va primero porque necesita esa API key: sin ella el Backend no sabría que el pedido viene de
esta tienda. Una tienda solo puede darse de baja a sí misma.

**Qué se pierde:** los canales conectados, el nombre y el mensaje de bienvenida del bot, y los clientes que el bot ya tenía identificados. **Qué no se toca:** la tienda de WooCommerce,
sus productos, clientes y pedidos. Si se vuelve a instalar el plugin, es una tienda nueva para el
Backend: hay que repetir los 5 pasos del onboarding y volver a conectar el número.

**Si el Backend no responde** al borrarlo (servidor sin salida a internet, Backend caído), la
desinstalación sigue igual y queda en el log de PHP una línea como
`[Chatbot Assistant] DELETE https://…/api/tenants/… -> error: …`. La tienda queda viva en el Backend
con el número tomado: pedile a Adrian que la dé de baja (tiene un script para eso). Lo mismo con las
tiendas instaladas con una versión anterior a la 1.0.4.

### 7.2 Tiendas con varios vendedores (Dokan)

Si la tienda usa **Dokan** (cada emprendedor publica sus productos), el bot lo detecta solo, sin
configurar nada y sin actualizar el plugin:

- responde "¿qué vendedores tienen?" y muestra los productos de un vendedor puntual;
- en cada producto que muestra dice quién lo vende ("Máscara Colibrí · de Máscaras"), también en la
  lista del link de compra;
- recomienda otros productos del mismo vendedor, y el menú de "¿qué productos tienen?" ofrece ver
  lo de cada vendedor;
- una compra a varios vendedores la ve como **un** pedido (Dokan la parte en un sub-pedido por vendedor;
  el bot trabaja siempre con el pedido principal).

Lo único que necesita es que las rutas públicas de Dokan respondan desde fuera:
`https://tu-dominio/wp-json/dokan/v1/stores` tiene que devolver la lista de vendedores. Si algún
plugin de seguridad bloquea la REST API para visitantes, el bot sigue funcionando pero sin vendedores.
Otros plugins de marketplace (WCFM, MultiVendorX) todavía no están soportados.

---

## 8. Problemas comunes

| Síntoma | Causa probable | Solución |
|---|---|---|
| El panel se queda en el paso 1, sin error | Falta `CHATBOT_BACKEND_URL` | [3.2](#32-configurar-la-dirección-del-backend); reiniciar el contenedor |
| Paso 2 falla una y otra vez | WordPress cree que está en HTTP, o el Backend no llega al dominio | [Sección 4](#4-https-detrás-de-nginx); probar `https://tu-dominio/wp-json/wc/v3` desde fuera |
| Paso 2 falla con 404 | Enlaces permanentes en "Simple" | Cambiarlos a "Nombre de la entrada" |
| El bot manda links que no hacen nada | Falta `vendor/` | [3.1](#31-conseguir-el-plugin-completo) |
| Paso 3: "Este número ya está conectado a otra tienda" después de reinstalar el plugin | La tienda vieja no se dio de baja (plugin anterior a la 1.0.4, o el Backend no respondió al borrarlo: buscar `DELETE …/api/tenants/` en el log de PHP) | Pedirle a Adrian que dé de baja la tienda vieja; para actualizar, [3.1](#31-conseguir-el-plugin-completo) sin borrar |
| Los links dan error al abrirse ("Missing math extension for Hashids" en el log de PHP) | PHP sin `bcmath` ni `gmp` | Punto 8 del [checklist](#2-antes-de-instalar--checklist) |
| Los links abren `http://` o `localhost` | La dirección del sitio no es la pública con HTTPS | [4.2](#42-wordpress-la-dirección-del-sitio-con-https-desde-una-variable-de-entorno) |
| El bot no responde por WhatsApp | El número sigue conectado a otra tienda, o no se conectó | [Sección 6](#6-whatsapp-cómo-conectarlo) |
| Con Dokan, el bot dice que no hay vendedores | `/wp-json/dokan/v1/stores` bloqueada para visitantes (plugin de seguridad) | [7.2](#72-tiendas-con-varios-vendedores-dokan); abrir esa dirección desde fuera |
| Hay que empezar de cero | — | Borrar las opciones `chatbot_provision` y `chatbot_api_key_plain` y abrir wp-admin: se registra como tienda nueva (con otro `tenant_id`; avisale a Adrian para dar de baja el anterior) |

**Modo soporte.** El dueño de la tienda no ve nada técnico en el panel. Para diagnosticar, agregá en
`wp-config.php` (en Docker, dentro de `WORDPRESS_CONFIG_EXTRA`):

```php
define( 'CHATBOT_DEBUG', true );
```

Con eso aparece al pie del panel **Información Técnica (modo soporte)**: el estado del onboarding y
la bitácora del plugin (últimos 10 eventos), con las credenciales (`api_key_hash`, `consumerKey`)
como `***`. Si el registro no arranca, el aviso del panel muestra además el comando para correrlo a
mano. Sacá la constante al terminar.

Desde WP-CLI no hace falta la constante:

```bash
wp chatbot status        # estado del onboarding (tenant, paso, WooCommerce, WhatsApp)
wp chatbot audit         # bitácora del plugin, el evento más nuevo primero
wp chatbot clear-audit   # vacía la bitácora
```

Las llamadas al Backend que **fallan** quedan en el log de PHP (en Docker, `docker logs` del
contenedor de WordPress), una línea por fallo con la respuesta del Backend:
`[Chatbot Assistant] POST …/wa/connect-direct -> 400 response={"error":"INVALID_WHATSAPP_CREDENTIALS",…}`.
Con `WP_DEBUG` encendido se registran también las que salen bien, con el cuerpo del pedido. Los
tokens, secretos y claves aparecen siempre como `***`.

---

## 9. Referencia rápida

- Comandos de WP-CLI y rutas REST con ejemplos: [`COMMANDS.md`](COMMANDS.md)
- Contrato completo con el Backend: [`BACKEND_API_SPEC.md`](BACKEND_API_SPEC.md)
- Entorno local de desarrollo de Adrian: `docker-compose.yml` + `docker-init.ps1` (WordPress en
  `localhost:8210`, expuesto con un túnel HTTPS). No se usa en producción.
