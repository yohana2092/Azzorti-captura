@echo off
REM Prende el backend local (ver README.md). Dejar esta ventana abierta
REM mientras se prueba - cerrarla apaga el servidor.
REM
REM Tambien hace falta iniciar_tunel.bat (en una ventana APARTE) para
REM que la app del celular y el dashboard puedan conectarse - el
REM Firewall de Windows bloquea la conexion directa por IP de LAN en
REM esta compu (no hay permisos de administrador para arreglarlo), asi
REM que se usa un tunel de Cloudflare en su lugar.
cd /d "%~dp0"
echo Iniciando backend local en http://0.0.0.0:8765 ...
echo (Tambien hace falta abrir iniciar_tunel.bat en OTRA ventana)
echo.
php -S 0.0.0.0:8765 router.php
