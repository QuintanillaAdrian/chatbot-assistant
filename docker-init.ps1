# Docker Initialization Script para Chatbot Assistant Plugin
# Instala: WordPress + WooCommerce + Plugin + Provisioning
# Usa "docker compose run --rm" para wpcli (contenedor one-shot, no necesita estar corriendo)

$WP = "docker compose run --rm wpcli wp"

Write-Host "========================================" -ForegroundColor Green
Write-Host "Inicializando Chatbot Assistant Plugin" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green

# PASO 1: Levantar db y wordpress
Write-Host "`n[PASO 1/6] Levantando contenedores Docker..." -ForegroundColor Cyan
docker compose up -d db wordpress

if ($LASTEXITCODE -ne 0) {
    Write-Host "Error al levantar los contenedores" -ForegroundColor Red
    exit 1
}

Write-Host "Esperando 45 segundos a que WordPress este completamente listo..." -ForegroundColor Yellow
Start-Sleep -Seconds 45

# PASO 2: Verificar WordPress
Write-Host "`n[PASO 2/6] Verificando instalacion de WordPress..." -ForegroundColor Cyan

Invoke-Expression "$WP core is-installed --allow-root" 2>&1 | Out-Null

if ($LASTEXITCODE -eq 0) {
    Write-Host "WordPress ya esta instalado" -ForegroundColor Green
} else {
    Write-Host "WordPress no esta instalado, instalando..." -ForegroundColor Yellow
    Invoke-Expression "$WP core install --url=http://localhost:8210 --title='Chatbot Shop' --admin_user=admin --admin_password=admin123 --admin_email=admin@example.com --skip-email --allow-root"
    if ($LASTEXITCODE -eq 0) {
        Write-Host "WordPress instalado correctamente" -ForegroundColor Green
    } else {
        Write-Host "Error al instalar WordPress" -ForegroundColor Red
        exit 1
    }
}

# PASO 3: Instalar y activar WooCommerce
Write-Host "`n[PASO 3/6] Instalando WooCommerce..." -ForegroundColor Cyan
Invoke-Expression "$WP plugin install woocommerce --activate --allow-root"
if ($LASTEXITCODE -eq 0) {
    Write-Host "WooCommerce instalado y activado" -ForegroundColor Green
} else {
    Write-Host "Error al instalar WooCommerce" -ForegroundColor Red
    exit 1
}

# PASO 4: Activar plugin Chatbot Assistant
Write-Host "`n[PASO 4/6] Activando plugin Chatbot Assistant..." -ForegroundColor Cyan
Invoke-Expression "$WP plugin activate chatbot-assistant --allow-root"
if ($LASTEXITCODE -eq 0) {
    Write-Host "Plugin activado correctamente" -ForegroundColor Green
} else {
    Write-Host "Error al activar el plugin" -ForegroundColor Red
    Invoke-Expression "$WP plugin list --allow-root"
    exit 1
}

# PASO 5: Ejecutar provisioning automatico
Write-Host "`n[PASO 5/6] Ejecutando provisioning automatico..." -ForegroundColor Cyan
Invoke-Expression "$WP chatbot provision-auto --allow-root"
if ($LASTEXITCODE -eq 0) {
    Write-Host "Provisioning ejecutado" -ForegroundColor Green
} else {
    Write-Host "Provisioning no completo (esperado si Backend no esta accesible)" -ForegroundColor Yellow
}

# PASO 6: Ver estado final
Write-Host "`n[PASO 6/6] Verificando estado final..." -ForegroundColor Cyan
Write-Host "`nEstado actual del plugin:" -ForegroundColor Cyan
Invoke-Expression "$WP chatbot status --allow-root"
Write-Host "`nPlugins activos:" -ForegroundColor Cyan
Invoke-Expression "$WP plugin list --allow-root"

Write-Host "`n========================================" -ForegroundColor Green
Write-Host "Inicializacion completada" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host "`nAccede a WordPress en: http://localhost:8210" -ForegroundColor Cyan
Write-Host "Usuario: admin" -ForegroundColor Cyan
Write-Host "Contrasena: admin123" -ForegroundColor Cyan
Write-Host "`nPanel del Chatbot: http://localhost:8210/wp-admin/admin.php?page=chatbot-assistant" -ForegroundColor Cyan
