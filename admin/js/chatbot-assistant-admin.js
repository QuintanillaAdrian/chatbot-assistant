(function ($) {
	'use strict';

	/**
	 * 5-Step Wizard AJAX Handlers
	 * Handles WhatsApp connection, bot configuration, and activation
	 */

	$(function () {

		// PASO 3: WhatsApp Connect Button
		$('#chatbot-wa-connect-btn').on('click', function (e) {
			e.preventDefault();
			var btn = $(this);
			var status = $('#chatbot-wa-status');

			btn.prop('disabled', true);
			status.html('Obteniendo URL de Meta...');

			$.ajax({
				url: chatbotAdmin.restUrl + 'wa/signup-url',
				type: 'GET',
				headers: {
					'X-WP-Nonce': chatbotAdmin.nonce
				},
				success: function (response) {
					if (response.success && response.signup_url) {
						// Redirigir a Meta OAuth
						window.location.href = response.signup_url;
					} else {
						status.html('<span style="color:red;">Error: ' + (response.error || 'Respuesta inválida') + '</span>');
						btn.prop('disabled', false);
					}
				},
				error: function (xhr) {
					var msg = 'Error en la solicitud';
					try {
						msg = xhr.responseJSON?.error || msg;
					} catch (e) { }
					status.html('<span style="color:red;">Error: ' + msg + '</span>');
					btn.prop('disabled', false);
				}
			});
		});

		// PASO 4: Configuration Form
		$('#chatbot-config-form').on('submit', function (e) {
			e.preventDefault();
			var form = $(this);
			var status = $('#chatbot-config-status');
			var btn = form.find('button[type="submit"]');

			// Get form data
			var data = {
				bot_name: $('#chatbot-bot-name').val() || '',
				welcome_message: $('#chatbot-welcome-msg').val() || '',
				system_prompt: $('#chatbot-system-prompt').val() || ''
			};

			// Validate at least one field
			if (!data.bot_name && !data.welcome_message && !data.system_prompt) {
				status.html('<span style="color:red;">Por favor completa al menos un campo</span>');
				return;
			}

			btn.prop('disabled', true);
			status.html('Guardando configuración...');

			$.ajax({
				url: chatbotAdmin.restUrl + 'config',
				type: 'PATCH',
				headers: {
					'X-WP-Nonce': chatbotAdmin.nonce,
					'Content-Type': 'application/json'
				},
				data: JSON.stringify(data),
				success: function (response) {
					if (response.success) {
						status.html('<span style="color:green;">Configuración guardada exitosamente</span>');
						setTimeout(function () {
							window.location.reload();
						}, 1500);
					} else {
						status.html('<span style="color:red;">Error: ' + (response.error || 'No se pudo guardar') + '</span>');
						btn.prop('disabled', false);
					}
				},
				error: function (xhr) {
					var msg = 'Error en la solicitud';
					try {
						msg = xhr.responseJSON?.error || msg;
					} catch (e) { }
					status.html('<span style="color:red;">Error: ' + msg + '</span>');
					btn.prop('disabled', false);
				}
			});
		});

		// PASO 5: Activate Button
		$('#chatbot-activate-btn').on('click', function (e) {
			e.preventDefault();
			var btn = $(this);
			var status = $('#chatbot-activate-status');

			btn.prop('disabled', true);
			status.html('Activando bot...');

			$.ajax({
				url: chatbotAdmin.restUrl + 'activate',
				type: 'POST',
				headers: {
					'X-WP-Nonce': chatbotAdmin.nonce,
					'Content-Type': 'application/json'
				},
				data: JSON.stringify({}),
				success: function (response) {
					if (response.success) {
						status.html('<span style="color:green;">Bot activado correctamente</span>');
						setTimeout(function () {
							window.location.reload();
						}, 1500);
					} else {
						status.html('<span style="color:red;">Error: ' + (response.error || 'No se pudo activar') + '</span>');
						btn.prop('disabled', false);
					}
				},
				error: function (xhr) {
					var msg = 'Error en la solicitud';
					try {
						msg = xhr.responseJSON?.error || msg;
					} catch (e) { }
					status.html('<span style="color:red;">Error: ' + msg + '</span>');
					btn.prop('disabled', false);
				}
			});
		});

	});

})(jQuery);
