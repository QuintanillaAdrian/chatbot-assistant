# Chatbot Assistant — Cómo funciona y cómo probarlo

## Arquitectura general

```
WordPress Plugin
      │
      ├── Pasos 1-2 (automático al activar)
      │         └──► Backend Node.js (api.chatbot.io)
      │                     └──► MCP / WooCommerce
      │
      └── Pasos 3-5 (manual desde panel admin)
                └──► Backend Node.js
                           └──► Meta (WhatsApp)
```

El plugin nunca habla directamente con WhatsApp ni con el MCP. Todo pasa por el Backend de Shopia.

---

## Flujo de los 5 pasos

### Paso 1 — Registro de tenant *(automático)*

- Se dispara desde wp-admin o WP-CLI (`init` hook via `maybe_run_pending_provision`, restringido a `is_admin()`/WP-CLI para no correr en cada visita del front-end)
- Genera un UUID como API key temporal y lo guarda en `chatbot_api_key_plain`
- Llama `POST /tenants` en el Backend → recibe `tenant_id` + `state_token`
- Guarda `tenant_id`, `api_key_hash`, `state_token` en `wp_options` (clave `chatbot_provision`)

### Paso 2 — Validación de WooCommerce *(automático, mismo request que Paso 1)*

- Genera un par `consumer_key / consumer_secret` nuevo directamente en `wp_woocommerce_api_keys`
- Reintentos con backoff exponencial (30s–5min) si falla, para evitar ráfagas
- Llama `POST /tenants/{id}/wc/validate` → Backend verifica la conexión WC
- `chatbot_api_key_plain` se conserva (no se borra): el Backend exige el Bearer en texto plano en todos los requests posteriores (Pasos 3-5, reconexiones, `/status`)
- `current_step` avanza a `2`

### Paso 3 — WhatsApp *(manual, desde el wizard)*

- Usuario hace clic en "Conectar WhatsApp"
- Plugin llama `GET /wa/signup-url` → Backend devuelve URL de Meta OAuth
- El navegador redirige al usuario a Meta para autorizar
- Meta redirige de vuelta a `/wp-json/chatbot/v1/webhook/meta-oauth` con `oauth_code`
- El plugin intercambia el code → `wa_verified = true`, se guarda el número de teléfono
- `current_step` avanza a `3`

### Paso 4 — Personalización *(manual)*

- Usuario llena `bot_name`, `welcome_message`, `system_prompt` en el formulario
- Plugin llama `PATCH /config` → Backend guarda la configuración
- Los valores se persisten también en `chatbot_provision` (wp_options)
- `current_step` avanza a `4`

### Paso 5 — Activación *(manual)*

- Solo disponible si `wc_verified = true` Y `wa_verified = true`
- Plugin llama `POST /activate` → Backend activa el bot
- `status` cambia a `active`, `current_step = 5`

---

## Estado esperado en la BD tras instalación exitosa

Opción `chatbot_provision` en `wp_options`:

```json
{
  "tenant_id": "uuid-generado",
  "state_token": "token-del-backend",
  "api_key_hash": "sha256-hash",
  "status": "pending",
  "current_step": 2,
  "wc_verified": true,
  "wa_verified": false,
  "consumerKey": "ck_xxx",
  "consumerSecret_encrypted": "base64...",
  "consumerSecret_last4": "xxxx"
}
```

---

## Cómo probarlo

### Requisitos previos

- XAMPP corriendo (Apache + MySQL)
- WordPress 6.9.1 con WooCommerce instalado y activo
- Plugin activado en el panel de WordPress

---

### 1. Verificar que los Pasos 1-2 se ejecutaron

```bash
wp chatbot status
```

Deberías ver `tenant_id` y `wc_verified: true`. Si no se ejecutaron aún:

```bash
wp chatbot provision-auto
```

O bien ve al panel admin → **Asistente Chatbot** y verifica el indicador de progreso.

---

### 2. Revisar la base de datos directamente

```sql
-- Ver el estado de provisioning guardado
SELECT option_name, option_value
FROM wp_options
WHERE option_name IN ('chatbot_provision', 'chatbot_provision_audit');

-- Verificar que se creó la WC API key
SELECT * FROM wp_woocommerce_api_keys
WHERE description = 'provisioning';
```

---

### 3. Probar los endpoints REST desde el navegador

Abre el panel admin de WordPress, luego abre la consola del navegador (F12) y ejecuta:

```javascript
// Probar que el endpoint de WhatsApp responde
fetch('/wp-json/chatbot/v1/wa/signup-url', {
    headers: { 'X-WP-Nonce': chatbotAdmin.nonce }
})
.then(r => r.json())
.then(console.log);
```

> `chatbotAdmin.nonce` está disponible en el panel admin gracias a `wp_localize_script`.

---

### 4. Probar el wizard visual

1. Ir a **WordPress Admin → Asistente Chatbot**
2. El indicador de progreso debe mostrar el paso actual resaltado en azul
3. Si `current_step = 2`, verás el panel de Paso 3 con el botón "Conectar WhatsApp"
4. Si `current_step = 3`, verás además el formulario de Paso 4
5. Si `current_step = 4`, verás el panel de Paso 5 con el botón "Activar Bot"

---

### 5. Simular un webhook del Backend

Obtén el `api_key_hash` desde la base de datos:

```sql
SELECT option_value FROM wp_options WHERE option_name = 'chatbot_provision';
-- Busca el campo "api_key_hash" en el JSON resultante
```

Luego envía el webhook con curl:

```bash
curl -X POST https://tu-sitio.com/wp-json/chatbot/v1/webhook/backend \
  -H "Authorization: Bearer <api_key_hash>" \
  -H "Content-Type: application/json" \
  -d '{"event": "tenant.activated", "data": {}}'
```

Eventos disponibles: `tenant.activated`, `tenant.suspended`, `wc.credentials_invalid`, `wa.token_revoked`, `plan.limit_warning`, `plan.limit_reached`.

---

### 6. Revisar la bitácora de auditoría

```bash
wp chatbot status
```

O desde la base de datos:

```sql
SELECT option_value FROM wp_options WHERE option_name = 'chatbot_provision_audit';
```

Para limpiarla:

```bash
wp chatbot clear-audit
```
