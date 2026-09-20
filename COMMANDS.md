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

### `wp chatbot clear-audit`

Vacía la bitácora de auditoría local (`chatbot_provision_audit`).

```bash
wp chatbot clear-audit
```

---

## REST API

Base URL: `https://tu-sitio.com/wp-json/chatbot/v1/`

Todos los endpoints del wizard requieren autenticación de WordPress admin (nonce `wp_rest` en header `X-WP-Nonce`).  
El endpoint `/webhook/backend` usa `Authorization: Bearer <api_key_hash>`.

---

### `POST /tenant/register`

**Paso 1** — Registra el tenant en el Backend. Público (sin autenticación requerida en primera instalación).

**Request:** ninguno

**Response 201:**
```json
{
  "success": true,
  "tenant_id": "uuid",
  "api_key": "plaintext-key",
  "state_token": "token",
  "status": "pending"
}
```

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

### `PATCH /config`

**Paso 4** — Guarda la configuración del bot (nombre, mensaje de bienvenida, system prompt).

**Headers:** `X-WP-Nonce: <nonce>`, `Content-Type: application/json`

**Request body** (todos los campos son opcionales, al menos uno requerido):
```json
{
  "bot_name": "Sofia",
  "welcome_message": "Hola! ¿En qué te puedo ayudar?",
  "system_prompt": "Eres un asistente de ventas amable..."
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
