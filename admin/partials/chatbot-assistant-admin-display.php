<?php
/**
 * Admin view - 5-Step Wizard UI
 *
 * @package    Chatbot_Assistant
 * @subpackage Chatbot_Assistant/admin/partials
 */
?>
<div class="wrap">
	<h1>Asistente Chatbot — Onboarding</h1>

	<?php if ( empty( $store ) || empty( $store['tenant_id'] ) ) : ?>
		<div class="notice notice-warning">
			<p>Inicializando provisioning... Si persiste, ejecuta <code>wp chatbot provision-auto</code>.</p>
		</div>
	<?php else :
		$current = (int) ( $store['current_step'] ?? 1 );
		$steps   = array( 1 => 'Registro', 2 => 'WooCommerce', 3 => 'WhatsApp', 4 => 'Personalización', 5 => 'Activación' );
	?>

		<!-- Indicador de progreso -->
		<div style="margin: 30px 0; padding: 20px; background: #f5f5f5; border-radius: 8px;">
			<div style="display: flex; justify-content: space-between; align-items: center;">
				<?php foreach ( $steps as $step => $label ) : ?>
					<div style="flex: 1; text-align: center; position: relative;">
						<div style="
							width: 40px; height: 40px; margin: 0 auto 8px; border-radius: 50%;
							display: flex; align-items: center; justify-content: center;
							font-weight: bold; color: white;
							background: <?php echo $step <= $current ? '#0073aa' : '#ccc'; ?>;
						"><?php echo $step; ?></div>
						<div style="font-size: 12px; color: #666;"><?php echo esc_html( $label ); ?></div>
						<?php if ( $step < 5 ) : ?>
							<div style="position: absolute; top: 20px; left: 50%; width: 100%; height: 2px;
								background: <?php echo $step < $current ? '#0073aa' : '#ccc'; ?>; margin-left: 20px;
							"></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Contenido de pasos -->
		<div style="margin-top: 40px;">

			<!-- PASOS 1 & 2 -->
			<div style="padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
				<h2>Paso 1 &amp; 2: Registro y Conexión WooCommerce</h2>
				<table style="width: 100%; border-collapse: collapse;">
					<tr>
						<td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Tenant ID:</strong></td>
						<td style="padding: 8px; border-bottom: 1px solid #eee; font-family: monospace; font-size: 11px;">
							<?php echo esc_html( $store['tenant_id'] ?? 'N/A' ); ?>
						</td>
					</tr>
					<tr>
						<td style="padding: 8px;"><strong>Estado WC:</strong></td>
						<td style="padding: 8px;">
							<?php if ( ! empty( $store['wc_verified'] ) ) : ?>
								<span style="color: green; font-weight: bold;">&#10003; Verificado</span>
							<?php else : ?>
								<span style="color: #999;">En progreso...</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td style="padding: 8px;"><strong>Última sync con Backend:</strong></td>
						<td style="padding: 8px;">
							<?php if ( ! empty( $store['status_synced_at'] ) ) : ?>
								<span style="color: #333;"><?php echo esc_html( $store['status_synced_at'] ); ?></span>
								<?php if ( isset( $store['last_sync_ok'] ) && false === $store['last_sync_ok'] ) : ?>
									<br><span style="color: #d32f2f;">&#9888; El intento más reciente de conectar con el Backend falló — mostrando el último estado confirmado</span>
								<?php endif; ?>
							<?php else : ?>
								<span style="color: #d32f2f;">Nunca — no se pudo confirmar con el Backend, datos posiblemente desactualizados</span>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div>

			<!-- PASO 3: Elegí tus canales -->
			<?php if ( $current >= 2 ) : ?>
				<div style="padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
					<h2>Paso 3: Elegí tus canales</h2>
					<p>Conectá al menos un canal para que tu bot pueda recibir mensajes. Podés activar los dos.</p>

					<div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 15px;">

						<!-- Telegram -->
						<div style="flex: 1; min-width: 260px; padding: 15px; background: #fafafa; border: 1px solid #eee; border-radius: 6px;">
							<h3 style="margin-top: 0;">Telegram</h3>
							<?php if ( ! empty( $store['telegram_verified'] ) ) : ?>
								<div style="padding: 12px; background: #ecf0f1; border-left: 4px solid #27ae60;">
									<strong>&#10003; Telegram Conectado</strong>
									<?php if ( ! empty( $store['telegram_bot_username'] ) ) : ?>
										— Bot: <code>@<?php echo esc_html( $store['telegram_bot_username'] ); ?></code>
									<?php endif; ?>
								</div>
								<?php if ( ! empty( $store['telegram_owner_confirmed'] ) ) : ?>
									<div style="padding: 12px; background: #ecf0f1; border-left: 4px solid #27ae60; margin-top: 8px;">
										<strong>&#10003; Confirmado</strong> — ya recibimos un mensaje tuyo, en Paso 5 te va a llegar la prueba final.
									</div>
								<?php elseif ( ! empty( $store['telegram_bot_username'] ) ) : ?>
									<div style="padding: 12px; background: #fff8e1; border-left: 4px solid #f0ad4e; margin-top: 8px;">
										Falta un paso: abrí Telegram y escribile cualquier mensaje a
										<a href="https://t.me/<?php echo esc_attr( $store['telegram_bot_username'] ); ?>" target="_blank" rel="noopener noreferrer">
											@<?php echo esc_html( $store['telegram_bot_username'] ); ?>
										</a>
										para confirmar que la conexión funciona — sin eso no podemos mandarte el mensaje de prueba en el Paso 5.
										<br><br>
										<button type="button" class="button" id="chatbot-telegram-check-confirm-btn">Ya le escribí, revisar</button>
									</div>
								<?php endif; ?>
								<p style="margin: 10px 0 0;">
									<a href="#" id="chatbot-telegram-reconnect-link" style="font-size: 12px;">¿Reconectar o cambiar de bot?</a>
								</p>
							<?php endif; ?>
							<div id="chatbot-telegram-connect-form" <?php echo ! empty( $store['telegram_verified'] ) ? 'style="display: none; margin-top: 10px;"' : ''; ?>>
								<p class="description">
									Creá un bot con <a href="https://t.me/BotFather" target="_blank" rel="noopener noreferrer">@BotFather</a>
									y pegá acá el token que te da.
								</p>
								<input type="text" id="chatbot-telegram-token" class="regular-text" placeholder="123456:ABC-DEF...">
								<br><br>
								<button type="button" class="button button-primary" id="chatbot-telegram-connect-btn">Conectar Telegram</button>
								<div id="chatbot-telegram-status" style="margin-top: 10px;"></div>
							</div>
						</div>

						<!-- WhatsApp -->
						<div style="flex: 1; min-width: 260px; padding: 15px; background: #fafafa; border: 1px solid #eee; border-radius: 6px;">
							<h3 style="margin-top: 0;">WhatsApp</h3>
							<?php if ( ! empty( $store['wa_verified'] ) ) : ?>
								<div style="padding: 12px; background: #ecf0f1; border-left: 4px solid #27ae60;">
									<strong>&#10003; WhatsApp Conectado</strong>
									<?php if ( ! empty( $store['phone_number'] ) ) : ?>
										— Número: <code><?php echo esc_html( $store['phone_number'] ); ?></code>
									<?php endif; ?>
								</div>
								<p style="margin: 10px 0 0;">
									<a href="#" id="chatbot-wa-reconnect-link" style="font-size: 12px;">¿Reconectar o cambiar de número?</a>
								</p>
							<?php endif; ?>
							<div id="chatbot-wa-connect-form" <?php echo ! empty( $store['wa_verified'] ) ? 'style="display: none; margin-top: 10px;"' : ''; ?>>
								<div style="padding: 12px; background: #fff8e1; border-left: 4px solid #f0ad4e; margin-bottom: 10px;">
									Temporalmente no disponible — requiere verificación de negocio en Meta, en trámite.
								</div>
								<button type="button" class="button button-primary" id="chatbot-wa-connect-btn" disabled>Conectar WhatsApp</button>
								<div id="chatbot-wa-status" style="margin-top: 10px;"></div>
							</div>
						</div>

					</div>
				</div>
			<?php endif; ?>

			<!-- PASO 4: Personalización -->
			<?php if ( $current >= 3 ) : ?>
				<div style="padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
					<h2>Paso 4: Personalizar Bot</h2>
					<form id="chatbot-config-form">
						<table class="form-table">
							<tr>
								<th><label for="chatbot-bot-name">Nombre del Bot</label></th>
								<td><input type="text" id="chatbot-bot-name" name="bot_name" class="regular-text"
									placeholder="ej: Asistente Sofia"
									value="<?php echo esc_attr( $store['bot_name'] ?? '' ); ?>"></td>
							</tr>
							<tr>
								<th><label for="chatbot-welcome-msg">Mensaje de Bienvenida</label></th>
								<td><textarea id="chatbot-welcome-msg" name="welcome_message" class="large-text" rows="3"
									placeholder="ej: Hola! Soy Sofia, tu asistente virtual..."
									><?php echo esc_textarea( $store['welcome_message'] ?? '' ); ?></textarea></td>
							</tr>
							<tr>
								<th><label for="chatbot-system-prompt">System Prompt</label></th>
								<td>
									<textarea id="chatbot-system-prompt" name="system_prompt" class="large-text" rows="4"
										placeholder="Define cómo debe comportarse el bot..."
										><?php echo esc_textarea( $store['system_prompt'] ?? '' ); ?></textarea>
									<p class="description">Describe el rol, tono y comportamiento del bot.</p>
								</td>
							</tr>
						</table>
						<button type="submit" class="button button-primary">Guardar Configuración</button>
						<span id="chatbot-config-status" style="margin-left: 10px;"></span>
					</form>
				</div>
			<?php endif; ?>

			<!-- PASO 5: Activación -->
			<?php if ( $current >= 4 ) : ?>
				<div style="padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
					<h2>Paso 5: Activar Bot</h2>
					<div style="padding: 12px; background: #f0f8ff; border-left: 4px solid #0073aa; margin-bottom: 15px;">
						<p><strong>WooCommerce:</strong> <?php echo ! empty( $store['wc_verified'] ) ? '&#10003; Verificado' : '&#10007; Pendiente'; ?></p>
						<p><strong>Telegram:</strong> <?php echo ! empty( $store['telegram_verified'] ) ? '&#10003; Conectado' : '&#10007; No conectado'; ?></p>
						<p><strong>WhatsApp:</strong> <?php echo ! empty( $store['wa_verified'] ) ? '&#10003; Conectado' : '&#10007; No conectado'; ?></p>
					</div>
					<?php
					$has_channel = ! empty( $store['telegram_verified'] ) || ! empty( $store['wa_verified'] );
					?>
					<?php if ( ! empty( $store['wc_verified'] ) && $has_channel ) : ?>
						<?php if ( isset( $store['status'] ) && 'active' === $store['status'] ) : ?>
							<div style="padding: 12px; background: #ecf0f1; border-left: 4px solid #27ae60;">
								<strong style="color: #27ae60;">&#10003; Bot Activo</strong>
							</div>
						<?php else : ?>
							<?php if ( ! empty( $store['telegram_verified'] ) ) : ?>
								<p class="description" style="margin-bottom: 10px;">
									<?php if ( ! empty( $store['telegram_owner_confirmed'] ) ) : ?>
										Al activar te va a llegar por Telegram un mensaje de prueba con el resumen de tu configuración.
									<?php else : ?>
										No vas a recibir el mensaje de prueba por Telegram porque todavía no confirmaste la conexión en el Paso 3 — el bot igual queda activo para tus clientes.
									<?php endif; ?>
								</p>
							<?php endif; ?>
							<button type="button" class="button button-primary button-large" id="chatbot-activate-btn">Activar Bot</button>
							<span id="chatbot-activate-status" style="margin-left: 10px;"></span>
						<?php endif; ?>
					<?php else : ?>
						<p style="color: #d32f2f;">Completa WooCommerce y conectá al menos un canal (Paso 3) antes de activar.</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

		</div>

		<!-- Info técnica (colapsable) -->
		<details style="margin-top: 40px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
			<summary style="cursor: pointer; font-weight: bold; color: #0073aa;">Información Técnica</summary>
			<div style="margin-top: 10px; padding: 10px; background: #f5f5f5; font-family: monospace; font-size: 11px; overflow: auto;">
				<strong>Estado:</strong>
				<pre><?php echo esc_html( wp_json_encode( $store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
				<?php if ( ! empty( $audit ) && is_array( $audit ) ) : ?>
					<strong>Bitácora (últimas 10):</strong>
					<?php foreach ( $audit as $entry ) : ?>
						<div style="margin: 5px 0; padding: 5px; background: white; border-left: 2px solid #0073aa;">
							[<?php echo esc_html( $entry['created_at'] ?? '' ); ?>]
							<strong><?php echo esc_html( $entry['level'] ?? 'info' ); ?>:</strong>
							<?php echo esc_html( $entry['message'] ?? '' ); ?>
							<?php if ( ! empty( $entry['context'] ) ) : ?>
								<br><code><?php echo esc_html( wp_json_encode( $entry['context'] ) ); ?></code>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</details>

	<?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
	var REST_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

	var waBtn = document.getElementById('chatbot-wa-connect-btn');
	if (waBtn) {
		waBtn.addEventListener('click', function () {
			var statusEl = document.getElementById('chatbot-wa-status');
			statusEl.textContent = 'Obteniendo URL de Meta...';
			fetch('/wp-json/chatbot/v1/wa/signup-url', { headers: { 'X-WP-Nonce': REST_NONCE } })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data.signup_url) { window.location.href = data.signup_url; }
					else { statusEl.textContent = 'Error: ' + (data.error || 'Respuesta inesperada'); }
				})
				.catch(function () { statusEl.textContent = 'Error de red'; });
		});
	}

	var telegramCheckConfirmBtn = document.getElementById('chatbot-telegram-check-confirm-btn');
	if (telegramCheckConfirmBtn) {
		telegramCheckConfirmBtn.addEventListener('click', function () {
			// display_admin_page() re-sincroniza con el Backend en cada carga
			// (ver admin/class-chatbot-assistant-admin.php) — no hace falta
			// ningún endpoint nuevo, alcanza con recargar.
			telegramCheckConfirmBtn.disabled = true;
			telegramCheckConfirmBtn.textContent = 'Revisando...';
			window.location.reload();
		});
	}

	var telegramReconnectLink = document.getElementById('chatbot-telegram-reconnect-link');
	if (telegramReconnectLink) {
		telegramReconnectLink.addEventListener('click', function (e) {
			e.preventDefault();
			if (!confirm('¿Seguro que querés reconectar? Esto va a reemplazar el bot de Telegram actual.')) {
				return;
			}
			document.getElementById('chatbot-telegram-connect-form').style.display = 'block';
			telegramReconnectLink.style.display = 'none';
		});
	}

	var waReconnectLink = document.getElementById('chatbot-wa-reconnect-link');
	if (waReconnectLink) {
		waReconnectLink.addEventListener('click', function (e) {
			e.preventDefault();
			if (!confirm('¿Seguro que querés reconectar? Esto va a reemplazar el número de WhatsApp actual.')) {
				return;
			}
			document.getElementById('chatbot-wa-connect-form').style.display = 'block';
			waReconnectLink.style.display = 'none';
		});
	}

	var telegramBtn = document.getElementById('chatbot-telegram-connect-btn');
	if (telegramBtn) {
		telegramBtn.addEventListener('click', function () {
			var tokenEl = document.getElementById('chatbot-telegram-token');
			var statusEl = document.getElementById('chatbot-telegram-status');
			var token = tokenEl.value.trim();
			if (!token) {
				statusEl.textContent = 'Pegá el token que te dio @BotFather primero';
				return;
			}
			telegramBtn.disabled = true;
			statusEl.textContent = 'Conectando con Telegram...';
			fetch('/wp-json/chatbot/v1/telegram/connect', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
				body: JSON.stringify({ bot_token: token })
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data.success) { statusEl.textContent = 'Conectado!'; setTimeout(function () { window.location.reload(); }, 800); }
					else { statusEl.textContent = 'Error: ' + (data.error || ''); telegramBtn.disabled = false; }
				})
				.catch(function () { statusEl.textContent = 'Error de red'; telegramBtn.disabled = false; });
		});
	}

	var configForm = document.getElementById('chatbot-config-form');
	if (configForm) {
		configForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var statusEl = document.getElementById('chatbot-config-status');
			statusEl.textContent = 'Guardando...';
			fetch('/wp-json/chatbot/v1/config', {
				method: 'PATCH',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
				body: JSON.stringify({
					bot_name: document.getElementById('chatbot-bot-name').value,
					welcome_message: document.getElementById('chatbot-welcome-msg').value,
					system_prompt: document.getElementById('chatbot-system-prompt').value,
				})
			})
				.then(function (r) { return r.json(); })
				.then(function (d) {
					statusEl.textContent = d.success ? 'Guardado correctamente' : ('Error: ' + (d.error || ''));
					if (d.success) { setTimeout(function () { window.location.reload(); }, 800); }
				})
				.catch(function () { statusEl.textContent = 'Error de red'; });
		});
	}

	var activateBtn = document.getElementById('chatbot-activate-btn');
	if (activateBtn) {
		activateBtn.addEventListener('click', function () {
			var statusEl = document.getElementById('chatbot-activate-status');
			activateBtn.disabled = true;
			statusEl.textContent = 'Activando...';
			fetch('/wp-json/chatbot/v1/activate', { method: 'POST', headers: { 'X-WP-Nonce': REST_NONCE } })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (d.success) { statusEl.textContent = 'Bot activado!'; setTimeout(function () { window.location.reload(); }, 800); }
					else { statusEl.textContent = 'Error: ' + (d.error || ''); activateBtn.disabled = false; }
				})
				.catch(function () { statusEl.textContent = 'Error de red'; activateBtn.disabled = false; });
		});
	}
});
</script>
