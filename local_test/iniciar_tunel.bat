@echo off
REM Prende el tunel de Cloudflare hacia el backend local (puerto 8765).
REM Dejar esta ventana Y la de iniciar_servidor.bat abiertas mientras
REM se prueba - cerrar cualquiera de las dos corta la conexion.
REM
REM OJO: cada vez que se prende este tunel, Cloudflare da una URL
REM NUEVA y distinta (no se puede fijar sin pagar un plan). Si esta
REM ventana se cierra o la compu se reinicia, hay que avisarle a
REM Claude para que actualice la URL nueva en 3 lugares
REM (local_test/bootstrap.php, lib/main.dart, dashboard.html) y
REM recompile el APK - mientras tanto la app/dashboard van a fallar.
cd /d "%~dp0"
echo Iniciando tunel hacia http://localhost:8765 ...
echo (la URL publica va a aparecer abajo, ya deberia coincidir con la
echo  que ya esta configurada en la app y el dashboard)
echo.
"..\backend\cloudflared\cloudflared.exe" tunnel --url http://localhost:8765
