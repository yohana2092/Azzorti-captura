@echo off
REM Prende el backend local (ver README.md). Dejar esta ventana abierta
REM mientras se prueba - cerrarla apaga el servidor.
cd /d "%~dp0"
echo Iniciando backend local en http://0.0.0.0:8765 ...
echo (Dashboard: abrir dashboard.html en el navegador de esta compu)
echo (App del celular: conectado a la misma red WiFi, ya apunta a esta IP)
echo.
php -S 0.0.0.0:8765 router.php
