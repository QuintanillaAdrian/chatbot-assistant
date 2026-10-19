# Chatbot Assistant — Referencia de Comandos

## WP-CLI

Requiere WordPress instalado y WP-CLI disponible en el sistema.

### `wp chatbot provision-auto`

Ejecuta automáticamente los **Pasos 1 y 2** del onboarding (registro de tenant + validación de WooCommerce).  
Ideal para scripts de instalación CI/CD.

```bash
wp chatbot provision-auto
```

**Salida esperada (éxito):**
```
Iniciando automatizacion de provisioning (Pasos 1-2)...
Success: Provisioning completado exitosamente!

Estado del Tenant:
  • tenant_id: 550e8400-e29b-41d4-a716-446655440000
  • current_step: 2
  • wc_verified: true
  • status: pending

Proximos pasos: Completar Pasos 3-5 desde el admin panel
```

---

### `wp chatbot status`

Muestra el estado actual del provisioning almacenado en la base de datos local.

```bash
wp chatbot status
```

**Salida esperada:**
```
Estado del Provisioning:

tenant_id: 550e8400-e29b-41d4-a716-446655440000
current_step: 2
status: pending
wc_verified: true
wa_verified: false
```

---

### `wp chatbot audit`

Muestra la bitácora del onboarding (`chatbot_provision_audit`, últimos 10 eventos, el más nuevo
primero), con las credenciales como `***`. Es la misma que el panel muestra solo en modo soporte
(`define( 'CHATBOT_DEBUG', true );` en `wp-config.php`); acá no hace falta la constante.
`provision-auto` la muestra sola cuando falla.

```bash
wp chatbot audit
```

**Salida de ejemplo:**
```
[2026-10-08 23:30:53] SUCCESS: WhatsApp conectado (conexión directa) {"phone":"+506 8888 9999"}
[2026-10-08 23:25:10] SUCCESS: Paso 2 completado: WooCommerce validado {"mcp_registered":true}
```

---

### `wp chatbot clear-audit`

Vacía la bitácora de auditoría local (`chatbot_provision_audit`).

```bash
wp chatbot clear-audit
```

---

## REST API

Base URL: `https://tu-sitio.com/wp-json/chatbot/v1/`

Todos los endpoints del wizard requieren autenticación de WordPress admin (nonce `wp_rest` en header `X-WP-Nonce`).  
Las rutas que llama el Backend (`/webhook/backend`, `/customers/lookup-by-phone`, `/orders/lookup-by-phone`) usan `Authorization: Bearer <api_key_hash>`. Los pasos 1 y 2 no tienen ruta REST: corren solos al abrir wp-admin.

---

### `POST /webhook/meta-oauth`

**Paso 3B** — Recibe el OAuth code de Meta tras la autorización de WhatsApp Business.

**Headers:** `X-WP-Nonce: <nonce>`

**Request body:**
```json
{
  "oauth_code": "code_from_meta",
  "state_token": "token"
}
```

**Response 200:**
```json
{
  "success": true,
  "wa_verified": true,
  "phone": "+521234567890"
}
```

---

### `GET /wa/signup-url`

**Paso 3A** — Obtiene la URL de Meta OAuth para conectar WhatsApp Business.

**Headers:** `X-WP-Nonce: <nonce>`

**Response 200:**
```json
{
  "success": true,
  "signup_url": "https://www.facebook.com/...",
  "redirect_uri": "https://tu-sitio.com/wp-json/chatbot/v1/webhook/meta-oauth"
}
```

---

### `POST /wa/connect-direct`

**Paso 3 (alternativa sin OAuth)** — Conecta WhatsApp con los tres datos de Meta for Developers →
App → WhatsApp → API Setup. Lo usa el formulario "Conexión directa" del wizard. El plugin reenvía los
datos al Backend (`POST /tenants/{id}/wa/connect-direct`) y no guarda el token.

**Headers:** `X-WP-Nonce: <nonce>` (administrador)

**Body:**
```json
{
  "phone_number_id": "123456789012345",
  "waba_id": "123456789012345",
  "access_token": "EAA..."
}
```

**Response 200:**
```json
{
  "success": true,
  "phone": "+506 8888-9999"
}
```

Errores: los del Backend, con su código HTTP (`400` datos inválidos, `409` número ya conectado a
otra tienda, `500` fallo al suscribir en Meta).

---

### `PATCH /config`

**Paso 4** — Guarda la configuración del bot (nombre y mensaje de bienvenida). Desde la 1.0.5 no hay
instrucciones propias: cómo atiende el bot es igual para todas las tiendas.

**Headers:** `X-WP-Nonce: <nonce>`, `Content-Type: application/json`

**Request body** (los dos campos son opcionales, al menos uno requerido):
```json
{
  "bot_name": "Sofia",
  "welcome_message": "Hola! ¿En qué te puedo ayudar?"
}
```

**Response 200:**
```json
{
  "success": true,
  "current_step": 4
}
```

---

### `POST /activate`

**Paso 5** — Activa el bot. Requiere que `wc_verified` y `wa_verified` sean `true`.

**Headers:** `X-WP-Nonce: <nonce>`

**Response 200:**
```json
{
  "success": true,
  "status": "active",
  "current_step": 5
}
```

**Response 400 (requisitos no cumplidos):**
```json
{
  "error": "WooCommerce not verified. Complete Paso 2 first."
}
```

---

### `POST /webhook/backend`

Webhook interno que recibe notificaciones de estado del Backend de Shopia.

**Headers:** `Authorization: Bearer <api_key_hash>`

**Eventos soportados:**

| Evento | Efecto |
|--------|--------|
| `tenant.activated` | Marca status = `active`, current_step = 5 |
| `tenant.suspended` | Marca status = `suspended` |
| `wc.credentials_invalid` | Marca wc_verified = false |
| `wa.token_revoked` | Marca wa_verified = false |
| `plan.limit_warning` | Log de advertencia |
| `plan.limit_reached` | Marca status = `suspended` |

**Request body:**
```json
{
  "event": "tenant.activated",
  "data": {}
}
```
