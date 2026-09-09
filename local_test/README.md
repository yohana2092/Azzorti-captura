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

## Si algo da un error raro que no tiene que ver con el cambio que hiciste

Puede ser que el nuevo código use algo de CodeIgniter que esta
imitación todavía no cubre (por ejemplo, otro modismo de SQL de
Informix que no está en la lista de `traducirSql()` en
`bootstrap.php`). Avisale a Claude con el mensaje de error completo —
se agrega a la traducción una sola vez y sigue funcionando para
siempre, sin tocar los archivos reales del módulo.
