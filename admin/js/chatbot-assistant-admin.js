/**
 * Panel de onboarding (wp-admin → Asistente Chatbot).
 *
 * Único script del panel (2026-10-08): antes había una copia en jQuery en este archivo y otra en
 * línea dentro de la vista, y los dos enganchaban los mismos botones — "Guardar configuración" y
 * "Activar" mandaban cada uno dos pedidos al Backend. `chatbotAdmin` (restUrl, nonce) lo inyecta
 * Chatbot_Assistant_Admin::enqueue_scripts.
 */
(function () {
	'use strict';

	// Llamada a las rutas REST del plugin (chatbot/v1) con el nonce del admin. Devuelve el JSON.
	function rest(path, method, body) {
		var options = { method: method || 'GET', headers: { 'X-WP-Nonce': chatbotAdmin.nonce } };
		if (body !== undefined) {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify(body);
		}
		return fetch(chatbotAdmin.restUrl + path, options).then(function (r) { return r.json(); });
	}

	function reloadSoon() {
		setTimeout(function () { window.location.reload(); }, 800);
	}

	// Enlace "¿Reconectar…?": muestra el formulario oculto de un canal ya conectado.
	function bindReconnect(linkId, formId, message) {
		var link = document.getElementById(linkId);
		if (!link) return;
		link.addEventListener('click', function (e) {
			e.preventDefault();
			if (!confirm(message)) return;
			document.getElementById(formId).style.display = 'block';
			link.style.display = 'none';
		});
	}

	document.addEventListener('DOMContentLoaded', function () {

		// PASO 3 — WhatsApp con Meta (Embedded Signup). El botón está deshabilitado en la vista
		// mientras el regreso de Meta no se complete en el Backend; se deja enganchado para entonces.
		var waBtn = document.getElementById('chatbot-wa-connect-btn');
		if (waBtn) {
			waBtn.addEventListener('click', function () {
				var statusEl = document.getElementById('chatbot-wa-status');
				statusEl.textContent = 'Obteniendo URL de Meta...';
				rest('wa/signup-url')
					.then(function (data) {
						if (data.signup_url) { window.location.href = data.signup_url; }
						else { statusEl.textContent = 'Error: ' + (data.error || 'Respuesta inesperada'); }
					})
					.catch(function () { statusEl.textContent = 'Error de red'; });
			});
		}

		// PASO 3 — WhatsApp, conexión directa con los tres datos de Meta (API Setup).
		var waDirectBtn = document.getElementById('chatbot-wa-direct-btn');
		if (waDirectBtn) {
			waDirectBtn.addEventListener('click', function () {
				var statusEl = document.getElementById('chatbot-wa-direct-status');
				var payload = {
					phone_number_id: document.getElementById('chatbot-wa-phone-number-id').value.trim(),
					waba_id: document.getElementById('chatbot-wa-waba-id').value.trim(),
					access_token: document.getElementById('chatbot-wa-access-token').value.trim()
				};
				if (!payload.phone_number_id || !payload.waba_id || !payload.access_token) {
					statusEl.textContent = 'Completa los tres datos';
					return;
				}
				waDirectBtn.disabled = true;
				statusEl.textContent = 'Verificando con Meta...';
				rest('wa/connect-direct', 'POST', payload)
					.then(function (data) {
						if (data.success) { statusEl.textContent = 'Conectado: ' + (data.phone || ''); reloadSoon(); }
						else { statusEl.textContent = 'Error: ' + (data.error || ''); waDirectBtn.disabled = false; }
					})
					.catch(function () { statusEl.textContent = 'Error de red'; waDirectBtn.disabled = false; });
			});
		}

		// PASO 3 — Telegram, con el token de @BotFather.
		var telegramBtn = document.getElementById('chatbot-telegram-connect-btn');
		if (telegramBtn) {
			telegramBtn.addEventListener('click', function () {
				var statusEl = document.getElementById('chatbot-telegram-status');
				var token = document.getElementById('chatbot-telegram-token').value.trim();
				if (!token) {
					statusEl.textContent = 'Pega el token que te dio @BotFather primero';
					return;
				}
				telegramBtn.disabled = true;
				statusEl.textContent = 'Conectando con Telegram...';
				rest('telegram/connect', 'POST', { bot_token: token })
					.then(function (data) {
						if (data.success) { statusEl.textContent = 'Conectado!'; reloadSoon(); }
						else { statusEl.textContent = 'Error: ' + (data.error || ''); telegramBtn.disabled = false; }
					})
					.catch(function () { statusEl.textContent = 'Error de red'; telegramBtn.disabled = false; });
			});
		}

		// Telegram: "Ya le escribí, revisar". display_admin_page() re-sincroniza con el Backend en
		// cada carga, así que alcanza con recargar.
		var telegramCheckConfirmBtn = document.getElementById('chatbot-telegram-check-confirm-btn');
		if (telegramCheckConfirmBtn) {
			telegramCheckConfirmBtn.addEventListener('click', function () {
				telegramCheckConfirmBtn.disabled = true;
				telegramCheckConfirmBtn.textContent = 'Revisando...';
				window.location.reload();
			});
		}

		bindReconnect('chatbot-telegram-reconnect-link', 'chatbot-telegram-connect-form',
			'¿Seguro que quieres reconectar? Esto va a reemplazar el bot de Telegram actual.');
		bindReconnect('chatbot-wa-reconnect-link', 'chatbot-wa-connect-form',
			'¿Seguro que quieres reconectar? Esto va a reemplazar el número de WhatsApp actual.');

		// PASO 4 — Personalización.
		var configForm = document.getElementById('chatbot-config-form');
		if (configForm) {
			configForm.addEventListener('submit', function (e) {
				e.preventDefault();
				var statusEl = document.getElementById('chatbot-config-status');
				statusEl.textContent = 'Guardando...';
				rest('config', 'PATCH', {
					bot_name: document.getElementById('chatbot-bot-name').value,
					welcome_message: document.getElementById('chatbot-welcome-msg').value
				})
					.then(function (d) {
						statusEl.textContent = d.success ? 'Guardado correctamente' : ('Error: ' + (d.error || ''));
						if (d.success) { reloadSoon(); }
					})
					.catch(function () { statusEl.textContent = 'Error de red'; });
			});
		}

		// PASO 5 — Activación.
		var activateBtn = document.getElementById('chatbot-activate-btn');
		if (activateBtn) {
			activateBtn.addEventListener('click', function () {
				var statusEl = document.getElementById('chatbot-activate-status');
				activateBtn.disabled = true;
				statusEl.textContent = 'Activando...';
				rest('activate', 'POST')
					.then(function (d) {
						if (d.success) { statusEl.textContent = 'Bot activado!'; reloadSoon(); }
						else { statusEl.textContent = 'Error: ' + (d.error || ''); activateBtn.disabled = false; }
					})
					.catch(function () { statusEl.textContent = 'Error de red'; activateBtn.disabled = false; });
			});
		}
	});
})();
