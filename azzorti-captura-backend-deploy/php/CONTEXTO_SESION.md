# Contexto de sesión — módulo `captura_v1` (backend de benchmarking de precios)

> Este archivo es para el próximo Claude (u otra IA) que retome este
> proyecto. Lo escribió una sesión de Claude anterior, a pedido de un
> desarrollador que estaba ayudando **desde afuera** a la persona que
> realmente es dueña de este proyecto. Esa persona (la que probablemente
> te está hablando a vos ahora) **no tiene experiencia de programación**
> — explicá las cosas en consecuencia, sin asumir que entiende jerga
> técnica, y confirmá antes de hacer cambios grandes.

## ⚠️ LO MÁS IMPORTANTE: cómo se despliega esto

**Vos (Claude) trabajás sobre una copia LOCAL de estos archivos.** Cuando
edités un archivo PHP, ese cambio **no existe todavía en el servidor
real** hasta que la persona dueña del proyecto copie/suba manualmente
los archivos modificados a la instalación real (`hmvc/application/...`
en `servicioweb2bol.azzorti.co` u otro host de destino).

Esto quiere decir:
- Después de cada cambio de código, hay que decirle explícitamente a la
  persona **qué archivos cambiaron** y que los tiene que volver a subir
  antes de poder probar el resultado.
- Si algo "no funciona" después de un cambio, la primera pregunta antes
  de asumir que el código está mal es: **¿ya subiste el archivo nuevo al
  servidor?** — esto pasó varias veces en esta sesión y siempre terminó
  siendo la explicación real.
- No asumas que un `curl`/prueba en el servidor refleja el código que
  ves en este checkout local hasta que la persona confirme que subió los
  cambios.

## Qué es este proyecto

Un módulo de "benchmarking de precios de competencia" (subir catálogos
PDF de la competencia y de Azzorti, comparar precios, sugerir a qué
producto de Azzorti corresponde cada producto de la competencia). Nació
como un prototipo standalone en Python/FastAPI + SQLite (pensado para
correr en una laptop, demo LAN). **Esta carpeta (`php/`) es un port
completo a PHP/CodeIgniter**, hecho para que encaje con el backend real
de la empresa (`hmvc/`, con módulos como `ventas_v1`/`vincu_v1`), en vez
de vivir aparte. El README.md de esta misma carpeta tiene el detalle
técnico completo (endpoints, schema, convenciones) — este archivo es
solo el resumen narrativo de qué se hizo y por qué, y qué quedó
pendiente.

Convenciones ya establecidas (ver README.md para el detalle):
- Nombres de tabla/columna abreviados (máx. 4 letras), igual que el
  resto del backend real — `cata_prod`, `prod_estr`, etc.
- Base de datos: **Informix real compartida**, no SQLite aislado (ya no
  hay marcha atrás en esa decisión).
- SQL armado como string interpolado con escape manual
  (`Informix_util::literal()`), no bind arrays de CodeIgniter — así
  funciona el resto del backend real.
- Archivos (PDF subidos, fotos, recortes) se guardan en la constante
  global `RUTA_ARCHIVOS` (ruta SENSIBLE, sin acceso HTTP) y se copian a
  `RUTA_TEMPORALES` (pública) recién cuando hace falta mostrarlos —
  `Archivo_util::url_publica()`. Ninguna de las dos rutas se redefine en
  este módulo, son constantes globales que ya existen en el
  `constants.php` compartido del `hmvc/` real.

## Qué se hizo en esta sesión (orden cronológico resumido)

1. **Se corrigió `captura_v1_ruta_archivos`** para usar la constante
   global `RUTA_ARCHIVOS` en vez de un config propio del módulo (el
   resto del backend real la usa así, a secas, sin `$this->config`).
2. **Se agregó creación automática de carpetas** (`Archivo_util::
   asegurar_carpeta()`) para que la primera subida no falle en silencio
   si la carpeta destino todavía no existe.
3. **Se descubrió y arregló el mecanismo real de "archivo sensible vs.
   público"**: `RUTA_ARCHIVOS` no es accesible por HTTP directo — hay
   que copiar a `RUTA_TEMPORALES` para poder mostrar un archivo. Se
   agregó `Archivo_util::url_publica()`, usado por todos los modelos que
   arman `foto_url`/`archivo_url`. El dominio real que sirve
   `RUTA_TEMPORALES` se confirmó en producción:
   `https://servicioweb2bol.azzorti.co/temporales/` (NO es el mismo
   dominio que usa `RUTA_ARCHIVOS` en el mirror del backend real, que es
   otro).
4. **Indexado de catálogos (OCR) — la parte más larga de la sesión.**
   `indexar-productos` lee un PDF, lo renderiza página por página
   (Imagick) y le corre OCR (Tesseract) para encontrar cada producto por
   su código (`COD. NNNNN` o `Ref.RNNNN`) y el texto/precio cercano.
   Se encontraron y arreglaron, todo en producción real (no local, no
   hay Tesseract instalado en esta máquina de desarrollo):
   - El servidor real tiene **Tesseract 3.04.00** (¡del 2015!). Genera
     PNG de 16-bit por canal que esa versión no puede leer → se fuerza
     8-bit (`Ocr_helper::renderizar_pagina`).
   - Esa versión de Tesseract **no soporta el formato de salida `tsv`**
     (se agregó recién en la 3.05) → se reemplazó por `hocr` (mucho más
     viejo, sí soportado), parseado a mano por regex.
   - El algoritmo original (fiel al Python) armaba una "ventana de texto
     cercano" por producto con márgenes fijos — mezclaba texto de
     productos vecinos (sobre todo con varios productos por fila, o
     descripciones largas). Se **rediseñó** para asignar cada palabra al
     código de producto más cercano (estilo Voronoi) — es la ÚNICA parte
     de este módulo que se apartó a propósito del algoritmo original del
     Python, documentado en el README sección 6.
   - Varias tolerancias a errores específicos de OCR de esta fuente:
     "Ref." leído como "ReÍ." (confusión f/í), "Ref."+código separados en
     dos palabras por el OCR, precio con espacio en vez de punto decimal
     ("Bs. 349 99"), la "B" de "Bs." a veces se pierde.
   - **DPI subido de 150 a 200** — ayudó mucho a leer bien el nombre del
     producto, pero el precio en algunos casos salió peor (dígitos
     confundidos por otros caracteres). El precio sigue siendo poco
     confiable con esta versión de Tesseract; no hay más margen de ajuste
     por regex sin arriesgar guardar un precio **incorrecto** (peor que
     no tener dato). El arreglo de fondo sería actualizar Tesseract en
     el servidor — eso queda fuera del alcance de este código.
5. **Catálogos grandes (~200-300 páginas) tiraban 504 Gateway Timeout**
   a mitad de camino (confirmado: se corta y NO sigue procesando solo en
   el fondo, ni aunque pasen horas). Se agregó soporte de **tandas**:
   `indexar-productos` acepta `?desde=N&hasta=M` (0-based), y el
   dashboard (`indexarProductos()` en `dashboard.html`) pide de a 10
   páginas por vez con una barra de progreso, en vez de todo en un
   request.
6. **Homologación Retail traía candidatos duplicados** (buscaba en
   TODOS los catálogos de Azzorti alguna vez subidos, no solo el
   vigente). Se acotó al catálogo de Azzorti más reciente — pero eso
   expuso un problema nuevo: "más reciente" se resolvía sin mirar la
   campaña, así que un catálogo de PRUEBA chico subido después del
   catálogo real (ambos con `competidor=azzorti`) le ganaba y tapaba
   todo el contenido real. Se agregó comparación de campaña **tolerante**
   (ignora guiones/espacios, completa el año con la fecha del registro
   si no viene en el texto) — pero esto NO resuelve el caso de dos
   catálogos que casualmente comparten la MISMA campaña (ahí gana el más
   nuevo igual, es ambigüedad real de los datos, no de código).
7. **Se agregó un botón "Eliminar" por catálogo** en el dashboard (y su
   endpoint `POST /catalogos/eliminar/{id}`) para que la persona pueda
   limpiar catálogos de prueba/viejos ella misma, sin necesitar SQL — es
   justo lo que hacía falta para el problema del punto 6.

## Cosas confirmadas por prueba directa en el servidor real (no supuestas)

- `RUTA_TEMPORALES` real: `servicioweb2bol.azzorti.co/temporales/`.
- Tesseract instalado: versión 3.04.00 (muy vieja).
- `shell_exec` SÍ está habilitado en ese servidor (no está en
  `disable_functions`).
- Un catálogo de ~300 páginas tarda sobre unos ~2.5-4 seg/página
  (render+OCR) → siempre va a necesitar procesarse en tandas, nunca en
  un solo request.

## Pendiente / cosas que la persona todavía tiene que hacer o decidir

- Volver a correr `indexar-productos` (por tandas, desde el dashboard)
  sobre los catálogos reales que ya estén subidos, para que los últimos
  fixes de OCR queden guardados en la base — los datos viejos en
  `cata_prod` son de corridas anteriores a estos arreglos.
- Usar el botón "Eliminar" para limpiar catálogos de prueba que estén
  compitiendo con el catálogo real de la misma campaña (ver punto 6 de
  arriba).
- Decidir si vale la pena pedir que actualicen Tesseract en el servidor
  (mejoraría mucho la lectura de precios y texto chico) — es un cambio
  de servidor, no de este código.
- Todo el checklist de despliegue (permisos de carpetas, PHP-FPM,
  `composer install`, etc.) está en la sección 7 del `README.md` de esta
  misma carpeta — no está completo, se fue tachando a medida que se
  confirmaban cosas en producción real.

## Dónde mirar para más detalle técnico

`README.md` (misma carpeta) tiene: requisitos de servidor, mapeo de
endpoints, mapeo de nombres de tabla/columna, la lista completa de
desviaciones conscientes del algoritmo original, y el checklist de
despliegue. Este archivo (`CONTEXTO_SESION.md`) es solo el resumen de
"qué pasó y por qué" — para el detalle exacto de cómo funciona cada
cosa, el README es la fuente de verdad.
