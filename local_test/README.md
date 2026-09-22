# Entorno local de pruebas del backend PHP

Esto permite correr el código de `azzorti-captura-backend-deploy/php/`
en tu propia compu — no solo para probar lógica antes de subir al
servidor real, sino como un backend REAL que la app del celular y el
dashboard pueden usar directamente, mientras no se pueda subir al
servidor de la empresa.

## Qué es y qué no es

- **No es una copia exacta del servidor real.** El servidor real usa
  CodeIgniter (un framework de verdad) conectado a Informix (una base
  de datos empresarial). Acá hay una imitación mínima de CodeIgniter
  (`bootstrap.php`) y una base de datos SQLite local en vez de
  Informix — alcanza para correr la MISMA lógica y las MISMAS consultas
  SQL (traducidas al vuelo, ver el comentario en `bootstrap.php` sobre
  `traducirSql()`), pero no es un espejo exacto.
- **Los archivos de `azzorti-captura-backend-deploy/php/` no se tocan
  para nada** — se usan tal cual, exactamente los mismos que se suben
  al servidor. Todo lo que hay en esta carpeta (`local_test/`) es
  aparte y nunca se sube.
- **Ya funciona TODO, incluido lo pesado:** capturas, homologación,
  configuración, indexar catálogos PDF de verdad (Tesseract 5.4 +
  Ghostscript + ImageMagick, instalados en esta compu), e importar el
  Excel de productos estrella (PhpSpreadsheet, instalado con Composer).
  El único hueco real es que es Informix contra SQLite, no Informix de
  verdad — si algún día aparece un modismo de SQL nuevo que
  `traducirSql()` no traduce, avisale a Claude para agregarlo.
- **Ojo con la calidad del OCR**: esta compu tiene Tesseract 5.4
  (moderno), mientras que el servidor real tiene Tesseract 3.04 (del
  2015, mucho peor leyendo texto). Un catálogo que se lee BIEN acá
  puede leerse PEOR en el servidor real — no es una garantía 1:1 de
  cómo se va a comportar en producción, solo de que la LÓGICA del
  código está bien.

## Cómo prender el servidor (para usar desde la app o el dashboard)

Hacen falta DOS ventanas abiertas a la vez:

1. Doble clic en `iniciar_servidor.bat` — prende el backend en tu
   compu (puerto 8765).
2. Doble clic en `iniciar_tunel.bat` — abre un túnel público (de
   Cloudflare) hacia ese backend. Esto reemplaza usar la IP de tu red
   WiFi directamente: el Firewall de Windows bloquea esa conexión en
   esta compu (tu usuario no tiene permisos de administrador para
   arreglarlo), así que se usa un túnel en su lugar — de paso, ya no
   hace falta estar en la misma red WiFi que el celular.
3. **Dashboard**: abrir `dashboard.html` en el navegador de la MISMA
   compu — ya está configurado con la URL del túnel.
4. **App del celular**: ya está compilada con la misma URL del túnel,
   funciona desde cualquier red (no hace falta la misma WiFi).

⚠️ **Cada vez que se reinicia `iniciar_tunel.bat`, la URL pública
CAMBIA** (Cloudflare da una nueva al azar, no se puede fijar sin pagar
un plan). Si cerraste esa ventana o reiniciaste la compu, avisale a
Claude para que actualice la URL nueva en 3 archivos y recompile el
APK — mientras tanto la app/dashboard van a fallar con errores de
conexión.

Si querés reiniciar los datos de prueba (borra todo y vuelve a cargar
el ejemplo inicial):
```
php seed.php
```
(hay que parar el servidor primero, `Ctrl+C` en su ventana, y prenderlo
de nuevo después — no hace falta tocar el túnel).

**Antes de volver a usar el servidor real**, hay que revertir 3
cambios temporales que se hicieron para este modo local:
- `_backendBaseUrl` en `lib/main.dart` (volver a la URL real).
- `BACKEND_URL` en `dashboard.html` (volver a la URL real).
- `LOCAL_BASE_URL` en `local_test/bootstrap.php` (ya no importa, no se
  sube al servidor real, pero conviene dejarlo actualizado igual).

## Herramientas instaladas en esta compu para que esto funcione

Todo instalado por Claude, para referencia futura (por si hay que
reinstalar o mover esto a otra compu):
- **PHP 8.2** (winget, `PHP.PHP.8.2`) — con `sqlite3`, `pdo_sqlite`,
  `mbstring`, `gd`, `intl`, `openssl`, `zip`, `imagick` habilitados en
  `php.ini`.
- **Composer** — descargado con el instalador oficial, queda en
  `local_test/tools/composer.phar`.
- **Tesseract OCR 5.4** (winget, `UB-Mannheim.TesseractOCR`), con
  idioma español ya incluido.
- **Ghostscript 10.08** (instalador oficial de GitHub) — delegado de
  Imagick para poder abrir PDF.
- **ImageMagick + extensión PHP `imagick`** — el paquete de
  windows.php.net (PECL) trae su propio ImageMagick empaquetado junto
  con `php_imagick.dll`; se copiaron ambos dentro de la carpeta de PHP.
- Composer instaló `phpoffice/phpspreadsheet` (y de paso
  `chriskacerguis/codeigniter-restserver`, sin usar por ahora) dentro
  de `azzorti-captura-backend-deploy/php/vendor/` (carpeta generada,
  no se sube a ningún lado).

## Solo scripts de prueba (sin servidor HTTP)

También se puede probar lógica puntual sin prender el servidor:
```
php probar_homologacion.php
```
Corre `sugerir_retail()` directo contra los datos de ejemplo y muestra
el resultado en la terminal — útil para revisar un cambio rápido antes
de armar todo el flujo completo.

## Si algo da un error raro que no tiene que ver con el cambio que hiciste

Puede ser que el nuevo código use algo de CodeIgniter que esta
imitación todavía no cubre (por ejemplo, otro modismo de SQL de
Informix que no está en la lista de `traducirSql()` en
`bootstrap.php`). Avisale a Claude con el mensaje de error completo —
se agrega a la traducción una sola vez y sigue funcionando para
siempre, sin tocar los archivos reales del módulo.
