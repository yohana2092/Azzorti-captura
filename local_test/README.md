# Entorno local de pruebas del backend PHP

Esto permite correr el código de `azzorti-captura-backend-deploy/php/`
en tu propia compu, para probar cambios ANTES de subirlos al servidor
real — así se evita otro incidente como el del 8/09 (un error de
sintaxis que rompió `servicioweb2bol.azzorti.co` hasta que alguien lo
notó).

## Qué es y qué no es

- **No es una copia del servidor real.** El servidor real usa
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
- **Lo que SÍ se puede probar acá:** que el código compile (sin
  errores de sintaxis), la lógica de homologación (`M_homologacion`),
  captura, catálogos (crear/listar/eliminar), configuración, etc.
- **Lo que NO se puede probar acá:** todo lo que necesita leer un PDF
  de verdad (indexar productos de un catálogo, OCR) — eso depende de
  Tesseract/Imagick/Ghostscript, que no están instalados en esta compu
  y son pesados de instalar. Para esa parte seguimos usando los
  diagnósticos temporales sobre el servidor real (`diagnostico_pagina`,
  etc.) como hicimos hoy.

## Cómo usarlo

1. Si es la primera vez, o si querés reiniciar los datos de prueba:
   ```
   php seed.php
   ```
   Esto crea las 9 tablas del módulo y carga algunos datos de ejemplo
   (una captura y unos productos de catálogo, tomados de los
   diagnósticos reales que ya sacamos hoy).

2. Para probar la homologación con esos datos de ejemplo:
   ```
   php probar_homologacion.php
   ```
   Muestra los mismos resultados que vería la app (SKU, % de
   similitud, descripción) pero calculados en tu compu.

3. Si PHP no se reconoce como comando, agregalo a la sesión primero
   (Claude ya lo instaló, queda en esta ruta):
   ```
   export PATH="/c/Users/yohana.gaitan.MAJORIS/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe:$PATH"
   ```
   (En una terminal nueva de Windows alcanza con abrir una consola
   nueva — el instalador ya dejó `php` en el PATH del sistema).

## Servidor local real (para probar desde la app del celular o el dashboard)

Además de los scripts de prueba (`php archivo.php`), se puede prender
un servidor de verdad que responde por HTTP, para que la APP DEL
CELULAR y el DASHBOARD le hablen directamente — sin depender de subir
nada al servidor real.

1. Cargar datos de ejemplo (una sola vez, o cuando se quiera reiniciar):
   ```
   php seed.php
   ```
2. Prender el servidor (queda corriendo, dejar la ventana abierta o
   correrlo en segundo plano):
   ```
   php -S 0.0.0.0:8765 router.php
   ```
3. **Dashboard**: abrir `dashboard.html` en el navegador de la MISMA
   compu — ya está configurado para hablarle a `http://localhost:8765`.
4. **App del celular**: el celular tiene que estar conectado a la
   MISMA red WiFi que esta compu. La app ya está compilada apuntando a
   `http://172.16.14.12:8765` (la IP de esta compu al momento de
   armar esto — si cambia de red, hay que avisarle a Claude para que
   actualice la IP y recompile el APK).
5. **Firewall de Windows**: la primera vez, Windows puede bloquear la
   conexión desde el celular. Si no conecta, correr esto en PowerShell
   COMO ADMINISTRADOR (clic derecho > "Ejecutar como administrador"):
   ```powershell
   New-NetFirewallRule -DisplayName "Azzorti Backend Local" -Direction Inbound -Protocol TCP -LocalPort 8765 -Action Allow -Profile Any
   ```

**Limitaciones de este servidor local** (mismas de siempre, ver
sección de arriba): no puede indexar catálogos PDF (necesita
Tesseract/Imagick, no instalados) ni importar el Excel de productos
estrella (necesita PhpSpreadsheet, no instalado) — para esas dos cosas
puntuales sigue haciendo falta el servidor real. Todo lo demás
(capturas, homologación, configuración) funciona igual.

**Antes de volver a usar el servidor real**: hay que revertir 3
cambios temporales que se hicieron para este modo local:
- `_backendBaseUrl` en `lib/main.dart` (volver a la URL real).
- `BACKEND_URL` en `dashboard.html` (volver a la URL real).
- `android:usesCleartextTraffic="true"` en
  `android/app/src/main/AndroidManifest.xml` (sacarlo o poner "false").

## Si algo da un error raro que no tiene que ver con el cambio que hiciste

Puede ser que el nuevo código use algo de CodeIgniter que esta
imitación todavía no cubre (por ejemplo, otro modismo de SQL de
Informix que no está en la lista de `traducirSql()` en
`bootstrap.php`). Avisale a Claude con el mensaje de error completo —
se agrega a la traducción una sola vez y sigue funcionando para
siempre, sin tocar los archivos reales del módulo.
