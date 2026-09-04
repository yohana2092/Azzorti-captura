# Modulo `captura_v1` (PHP) — puerto de Azzorti-captura

Puerto a PHP/CodeIgniter del backend Python/FastAPI de `Azzorti-captura`
(`backend/server.py`). Sigue las convenciones reales del backend de la
empresa: controllers que extienden `chriskacerguis\RestServer\RestController`,
models que extienden `CI_Model` con SQL crudo (`$this->db->query(...)`),
CORS manual, body via `php://input`, prefijo `M_` en los modelos.

**Confirmado contra una copia real de la aplicacion**: `hmvc/` NO es una
subcarpeta de `controllers/` — es la **raiz de otra instalacion
CodeIgniter separada** (con su propio `application/`, su propio
`config/database.php` — que apunta a **Informix via ODBC**, no Postgres
ni SQLite — y su propio `routes.php`, que es el default de fabrica). Esa
instalacion aparte es la que sirve `ventas_v1/`, `vincu_v1/`, etc., y por
eso las URLs reales son `/hmvc/<modulo>/...` (el segmento "hmvc" es
simplemente donde cuelga esa instalacion en el servidor). Este modulo ya
esta ajustado a eso: usa `$this->db` (Informix) y vive en
`controllers/captura_v1/` **dentro del `application/` de esa instalacion
`hmvc/`** (ej. `hmvc/application/controllers/captura_v1/`).

**Aun asi, no se probó localmente** — esta maquina no tiene PHP/Composer
ni acceso al Informix real. Verificar todo con el checklist del final.

## 1. Requisitos del servidor

- **PHP 7.4+** con extensiones: `odbc` (ya lo necesita el resto del
  backend real), `zip`, `dom`, `mbstring`, **`imagick`** (con delegado
  **Ghostscript** para poder abrir PDF — `imagick -list delegate | grep gs`
  para confirmar).
- **Composer**, para instalar `phpoffice/phpspreadsheet` y
  `chriskacerguis/codeigniter-restserver` (ver `composer.json`):
  ```bash
  composer install
  ```
- **Tesseract OCR** instalado a nivel de sistema, con el paquete de
  idioma español (`spa`):
  - Debian/Ubuntu: `apt install tesseract-ocr tesseract-ocr-spa`
  - Windows: instalador de [UB-Mannheim](https://github.com/UB-Mannheim/tesseract/wiki),
    agregando `tesseract` al `PATH` del usuario que corre el webserver.
  - ⚠️ **El servidor de produccion real trae tesseract 3.04.00** (muy
    viejo, del 2015) — `Ocr_helper.php` ya esta adaptado a sus dos
    limitaciones confirmadas ahi (no hace falta tocar nada si es esta
    misma version, pero documentado por si se migra a un tesseract mas
    nuevo o a otro servidor):
    1. **PNG de 16-bit por canal**: Ghostscript/Imagick en ese servidor
       renderiza los PDF a 16-bit por default, y la Leptonica 1.72 que
       trae esta version de tesseract no lo soporta (falla en silencio,
       sin ningun error - simplemente no encuentra texto). Por eso
       `renderizar_pagina()` fuerza `setImageDepth(8)`.
    2. **Sin soporte para el config `tsv`** (se agrego en tesseract
       3.05): `tesseract img stdout tsv` da
       `read_params_file: Can't open tsv` y stdout vacio. Por eso
       `datos_ocr()` usa `hocr` (HTML con bbox por palabra, soportado
       desde tesseract 3.0x) en vez de `tsv`, parseando el `title='bbox
       x0 y0 x1 y1'` de cada `<span class='ocrx_word'>` por regex.
- La instalacion de CodeIgniter real ya trae `RESTController.php` y
  `Format.php` (`chriskacerguis/codeigniter-restserver`) en
  `application/libraries/` — no hace falta agregarlas.

## 2. Copiar los archivos

Todo esto va dentro de `hmvc/application/` (la instalacion CodeIgniter
separada que ya sirve `ventas_v1`/`vincu_v1`/etc — NO en el
`application/` del sitio principal):

```
hmvc/application/
  config/captura_v1.php                    <- de este paquete: config/captura_v1.php
  controllers/captura_v1/                   <- de este paquete: controllers/captura_v1/
  models/captura_v1/                         <- de este paquete: models/captura_v1/
  libraries/Texto_util.php                   <- de este paquete: libraries/
  libraries/Xlsx_image_extractor.php
  libraries/Ocr_helper.php
  libraries/Informix_util.php
```

Ademas, la carpeta `archivos/` de este paquete (con sus 5 subcarpetas
vacias) se copia FUERA de `hmvc/` por completo — bajo la ruta fisica de
la constante global `RUTA_ARCHIVOS` (ver seccion 4 para la ruta exacta y
el aviso sobre el dominio sin confirmar).

## 3. Base de datos: Informix real (`$this->db` default)

**No se crea ninguna conexion nueva** — los modelos usan `$this->db`, que
en `hmvc/application/config/database.php` (`$active_group = 'default'`)
ya apunta a Informix via ODBC. Las 9 tablas de `captura_v1` se crean ahi
mismo, junto a las del resto del sistema.

Informix no tiene varias cosas que SQLite/Postgres sí, y el codigo ya
esta escrito para no depender de ellas:

- **Sin bind de CodeIgniter (`$this->db->query($sql, $params)`)**: se
  observo que el driver `odbc` de este servidor ejecuta una misma forma
  de consulta por dos caminos internos distintos e inconsistentes
  (`odbc_execute`/`SQLExecute` vs `odbc_exec`/`SQLExecDirect`) segun la
  query, devolviendo el mismo error mutilado en ambos casos. Se elimino
  la ambiguedad: cada valor se escapa con `Informix_util::literal()` y se
  arma el SQL completo como un solo string antes de ejecutar, exactamente
  igual convencion que ya usa el resto del backend real (`M_inscripcion.php`
  y compañia arman el query con los valores ya interpolados y llaman
  `$this->db->query($query)` con un solo argumento). `literal()` NO usa
  `$this->db->escape()` — el driver `odbc` de este servidor tampoco lo
  implementa ("Unsupported feature of the database platform you are
  using") — escapa a mano duplicando comillas simples (el escape
  estandar de SQL).
- **Sin `CREATE TABLE IF NOT EXISTS`**: `M_schema::crear_tablas_faltantes()`
  chequea el catalogo del sistema (`systables`) antes de crear cada tabla.
- **Sin `INSERT ... ON CONFLICT DO UPDATE` / `INSERT OR IGNORE`**: todos
  los upserts (`M_configuracion`, `M_catalogo`, `M_producto_estrella`,
  `M_oferta_referencia`) usan `Informix_util::upsert()` /
  `Informix_util::existe_fila()` — chequean si la fila existe (con SQL ya
  literal) y deciden UPDATE o INSERT a mano.
- **Sin `LIMIT`**: se usa `SELECT FIRST 1 ...` (sintaxis de Informix) en
  vez de `... LIMIT 1` al final.
- **Sin `$this->db->insert_id()` confiable con el driver `odbc`**: se usa
  `Informix_util::ultimo_id_serial()`, que lee
  `DBINFO('sqlca.sqlerrd1')` justo despues del INSERT (el idiom real de
  Informix para el ultimo valor `SERIAL`).

`models/captura_v1/schema.sql` ya trae el DDL en dialecto Informix
(`SERIAL`, `VARCHAR(n)`, `PRIMARY KEY`/`UNIQUE` como restriccion de
tabla, sin `IF NOT EXISTS`).

⚠️ **Esto crea tablas nuevas en la base de datos de PRODUCCION real**
(Informix, la misma que usa `ventas_v1`/`vincu_v1`/etc). Antes de que
`M_schema::asegurar()` las cree solo en el primer request, conviene
confirmar que no chocan con tablas existentes:
```sql
SELECT tabname FROM systables WHERE tabname IN
('capt','azzo_prod','poli_prec','capt_conf','cata_comp','prod_estr',
'ofer_refe','cata_ofer','cata_prod');
```
debería devolver 0 filas.

### Nombres de tabla y columna (abreviados, igual convencion que el resto del sistema)

Siguiendo el mismo estilo de nombres cortos (segmentos de maximo 4 letras)
que ya usa el backend real (`tab_zona`, `tab_vend`, `nume_iden`,
`codi_usua`), las 9 tablas quedaron asi (nombre real en Informix / nombre
logico del diseño original):

| Tabla real | Nombre logico |
|---|---|
| `capt` | captura |
| `azzo_prod` | azzorti_producto |
| `poli_prec` | politica_precio |
| `capt_conf` | configuracion |
| `cata_comp` | catalogo_competidor |
| `prod_estr` | producto_estrella |
| `ofer_refe` | oferta_referencia |
| `cata_ofer` | catalogo_oferta |
| `cata_prod` | catalogo_producto |

Las columnas siguen el mismo patron (`comp`=competidor, `camp`=campana,
`cate`=categoria, `prec`=precio, `dscr`=descripcion, `fcre`/`fact`/`fsub`
= fecha creacion/actualizacion/subida, etc. — ver `schema.sql` para el
detalle completo de cada tabla). El codigo PHP no se entera de esto: cada
`SELECT` pone alias (`comp AS competidor`, `prec AS precio`, ...) para
que el resto de los modelos y el JSON que recibe la app Flutter sigan
usando los nombres completos de siempre.

## 4. Donde se guardan los archivos (fotos, catalogos, recortes)

Sigue la convencion REAL del backend, no la que se asumio en tres
intentos anteriores de este README (todos quedaron descartados — ver
`controllers/captura_v1/Archivos.php` si hace falta volver a la idea de
servir por PHP como respaldo). La convencion real tiene DOS rutas
globales distintas, no una sola, confirmadas contra el mirror
`C:\Trabajo\Apis EC`:

- **`RUTA_ARCHIVOS`** — ruta SENSIBLE, sin acceso HTTP directo. Es donde
  se escribe todo (`vincu_v1/M_vinculacion.php`,
  `ventas_v1/M_inscripcion.php`, `sami_v3/Vinc.php` la usan **a secas**,
  ej. `RUTA_ARCHIVOS . "clientes/vinculacion/..."`, nunca via
  `$this->config->item()`).
- **`RUTA_TEMPORALES`** — ruta PUBLICA, esta si accesible por cualquiera.
  El mirror muestra el mismo patron en `ventas_v1/M_panel.php` (funcion
  `pedi_cons_lide`, que arma facturas PDF para descarga): el archivo
  vive en una ruta sensible y, para poder consultarlo, primero se copia
  a `RUTA_TEMPORALES` (`system("cp $ruta_desc $ruta_desc2")` con
  `$ruta_desc2 = RUTA_TEMPORALES`) — recien ahi es visible desde afuera.

Ambas son constantes globales `define()`-adas una sola vez en el
`constants.php` compartido de `hmvc/` (no esta en este mirror, pero por
eso mismo no hay que redefinirlas: ya existen en el servidor real). Este
modulo sigue el mismo patron de punta a punta:

- **Escritura** (subir catalogo, guardar foto de captura, recorte de
  producto, etc.): siempre a `RUTA_ARCHIVOS . 'captura_v1/<subcarpeta>'`
  — nunca directo a un lugar publico. Cada controller arma
  `$this->ruta_archivos = RUTA_ARCHIVOS . 'captura_v1'` en su
  constructor.
- **Consulta** (cualquier `foto_url`/`archivo_url` en una respuesta
  JSON): pasa por `Archivo_util::url_publica($ruta_relativa, $base_url)`
  (`libraries/Archivo_util.php`), que copia el archivo de
  `RUTA_ARCHIVOS . 'captura_v1/' . $ruta_relativa` a
  `RUTA_TEMPORALES . 'captura_v1/' . $ruta_relativa` (con `copy()` de
  PHP en vez del `system("cp ...")` del mirror — mismo efecto, sin
  depender del shell) y recien entonces devuelve
  `$base_url . $ruta_relativa`. Si el archivo origen no existe, devuelve
  `null` sin copiar nada. Se llama en cada modelo que arma una URL de
  archivo (`M_captura`, `M_catalogo` via `Catalogos.php`,
  `M_oferta_referencia`, `M_homologacion`, `M_producto_estrella`) — la
  copia se hace de nuevo en cada consulta (no se cachea), igual de
  "crudo" que el patron del mirror.
  - Desviacion menor y documentada: dentro de `RUTA_TEMPORALES` este
    modulo usa su propio subfolder `captura_v1/<subcarpeta>/...` (el
    mirror copia todo plano, sin subcarpetas) — evita pisar archivos
    temporales de otros modulos con el mismo nombre.
- **`captura_v1_base_url`** (`config/captura_v1.php`) es la URL publica
  que sirve esa copia dentro de `RUTA_TEMPORALES` — NO la de
  `RUTA_ARCHIVOS` (que es privada a proposito).

✅ **Dominio de `captura_v1_base_url` CONFIRMADO en el servidor real**
(`GET /catalogos`, 2026-09-02): a diferencia de `RUTA_ARCHIVOS` (que en
el mirror varia por pais — Bolivia: `intranet2bol.azzorti.co`; Peru:
`intranet2per.azzorti.co`; Ecuador con dos candidatos segun el archivo:
`intranet2ecu.azzorti.co` / `intranet.azzorti.com`, cada uno comentado
en el otro), `RUTA_TEMPORALES` NO cuelga de ninguno de esos — cuelga del
**mismo host que sirve la API** (`servicioweb2bol.azzorti.co`), bajo la
subruta estatica `/temporales/`. Un `archivo_url` devuelto por la API
con `captura_v1_base_url = https://servicioweb2bol.azzorti.co/temporales/
captura_v1/` ya se probo accesible — si el servidor de destino final es
otro, hay que volver a confirmarlo ahi (mismo metodo: subir un catalogo
nuevo y mirar que `archivo_url` en `GET /catalogos` no salga `null`).

Pasos:
1. Confirmar que `captura_v1_base_url` en `config/captura_v1.php` sigue
   apuntando al host correcto en el servidor de destino (ver arriba).
2. Confirmar que el usuario con el que corre PHP tiene permiso de
   escritura en `RUTA_ARCHIVOS . 'captura_v1'` Y en
   `RUTA_TEMPORALES . 'captura_v1'` (esta segunda es la que se usa en
   cada consulta, no solo al subir).
3. Copiar la carpeta `archivos/` de este paquete (con sus 5 subcarpetas
   vacias: `capturas_fotos/`, `catalogos_competidor/`,
   `productos_estrella_fotos/`, `ofertas_fotos/`, `catalogo_paginas/`)
   dentro de `RUTA_ARCHIVOS . 'captura_v1'`.
4. Confirmar que la URL de `captura_v1_base_url` responde estatico
   (subir cualquier archivo a mano dentro de
   `RUTA_TEMPORALES . 'captura_v1'` y probar que se puede ver por HTTP)
   — si por algun motivo ese vhost tampoco sirve estatico,
   `Archivos.php` queda de respaldo (ver su propio comentario para
   reactivarlo; ese camino ni siquiera necesita `RUTA_TEMPORALES`, sirve
   directo desde `RUTA_ARCHIVOS` via PHP).

No hace falta crear las subcarpetas a mano si no se puede: tanto al
escribir (`Archivo_util::asegurar_carpeta()`) como al copiar a
`RUTA_TEMPORALES` (`Archivo_util::url_publica()`), se crea con
`mkdir(..., recursive: true)` toda la cadena de carpetas faltante —
incluida la carpeta base `captura_v1/` si tampoco existiera todavia.
Sirve como red de seguridad; de todos modos conviene crear la
estructura a mano de antemano y confirmar permisos, en vez de depender
de que el usuario de PHP pueda crear carpetas en esas rutas.

## 5. Rutas — NO hace falta tocar `routes.php`

El `routes.php` real de la instalacion `hmvc/` es un archivo compartido
por todos los modulos (`ventas_v1`, `vincu_v1`, etc.) que no se toca
desde 2022 — mejor no meterle nada. **Se puede evitar por completo**:
las URLs de este modulo se diseñaron para caer directo en el
enrutamiento nativo de CodeIgniter (`controlador/metodo/parametro`), sin
guiones y con el segmento de accion ANTES del id (no despues, que es lo
que hubiera necesitado una regla custom). Por eso:

- Nada de URLs como `capturas/{id}/homologacion/sugerencias` — es
  `capturas/homologacion_sugerencias/{id}`.
- Nada de guiones (`productos-estrella`) — es `productosestrella`
  (ver nota de la clase mas abajo).

Todos los endpoints reales, tal como los llaman `lib/main.dart` y
`dashboard.html` de `Azzorti-captura`:

| Metodo | URL (relativa a `/hmvc/captura_v1/`) | Controller::metodo |
|---|---|---|
| GET | `info` | `Configuracion::info_get` |
| GET/POST | `configuracion/umbral_alerta` | `Configuracion::umbral_alerta_get/post` |
| GET/POST | `configuracion/tipo_cambio` | `Configuracion::tipo_cambio_get/post` |
| GET/POST | `capturas` | `Capturas::index_get/post` |
| GET | `capturas/homologacion_sugerencias/{id}` | `Capturas::homologacion_sugerencias_get` |
| POST | `capturas/homologacion_confirmar/{id}` | `Capturas::homologacion_confirmar_post` |
| GET | `capturas/evaluacion/{id}` | `Capturas::evaluacion_get` |
| GET/POST | `catalogos` | `Catalogos::index_get/post` |
| POST | `catalogos/detectar_ofertas/{id}` | `Catalogos::detectar_ofertas_post` |
| GET | `catalogos/ofertas/{id}` | `Catalogos::ofertas_get` |
| POST | `catalogos/indexar_productos/{id}` | `Catalogos::indexar_productos_post` |
| GET | `catalogos/productos_resumen/{id}` | `Catalogos::productos_resumen_get` |
| POST | `catalogos/eliminar/{id}` | `Catalogos::eliminar_post` |
| GET | `productosestrella` | `Productosestrella::index_get` |
| POST | `productosestrella/importar` | `Productosestrella::importar_post` |
| GET/POST | `ofertas`, `ofertas/importar` | `Ofertas::index_get`, `Ofertas::importar_post` |
| GET | `historico` | `Historico::index_get` |

**`catalogos/indexar_productos/{id}` acepta `?desde=N&hasta=M`** (0-based,
inclusive, ambos opcionales). Un catalogo de ~300 paginas tarda ~2.5seg
por pagina (render + OCR) - hacerlo entero en un solo request da 504
Gateway Timeout bastante antes de terminar (confirmado en produccion: se
corto a los ~42 productos y se quedo ahi - el servidor NO sigue
procesando en el fondo despues del timeout). El dashboard llama este
endpoint en tandas de 15 paginas (`PAGINAS_POR_TANDA` en
`dashboard.html`), mostrando progreso; sin `desde`/`hasta` sigue
procesando el catalogo entero de una (para catalogos chicos o uso
directo por curl). Cada tanda solo borra lo indexado DE ESE RANGO de
paginas antes de reprocesarlo (`M_catalogo::eliminar_productos_de()`
ahora acepta un rango), para no pisar el trabajo de las tandas
anteriores. La respuesta cambio de forma (`num_paginas`,
`total_productos_tanda`, `pagina_desde`, `pagina_hasta`, `completo` en
vez de `paginas_analizadas`/`total_productos`) - si algo mas aparte del
dashboard llega a consumir este endpoint, hay que actualizarlo.

**Nota sobre `Productosestrella`** (clase y archivo, sin la "E" interna
en mayuscula, a diferencia de como se ve el nombre "natural"): CodeIgniter
resuelve el controller como `ucfirst($segmento_de_uri)`. El segmento de
URL es `productosestrella` (todo minuscula, como cualquier URL), y
`ucfirst()` de eso da `Productosestrella` — en un filesystem
case-sensitive (el servidor real es Linux) eso NO matchea un archivo
`ProductosEstrella.php`. Por eso el archivo/clase de este paquete se
llaman `Productosestrella` a proposito.

`config.php` tiene `index_page = 'index.php'` — si el `.htaccess` de esa
instalacion no quita `index.php` de la URL, hay que llamar
`/hmvc/index.php/captura_v1/...` en vez de `/hmvc/captura_v1/...`.

## 6. Desviaciones conscientes del original

- **`detectar-ofertas` renderiza la pagina PDF completa**, no solo "la
  imagen embebida mas grande" (que es lo que hace `server.py` via
  `fitz.get_page_images` + max por area). Imagick no tiene equivalente
  limpio a esa optimizacion puntual; el resultado funcional es
  equivalente, pero consume mas tiempo de OCR por pagina. Ver
  `Ocr_helper::renderizar_pagina`.
- **Bug corregido, no replicado**: en `server.py`, la constante `_REF_RE`
  se redefine dos veces a nivel de modulo; en ejecucion real siempre gana
  la segunda definicion, que **no tiene grupo de captura** —
  `_anclas_producto_retail` llama `m.group(1)` sobre ese resultado, lo
  que en Python revienta con `IndexError` en cuanto aparece un
  "Ref.RNNNN" en un catalogo Retail. Aqui `Texto_util::REF_RE` usa el
  mismo patron de reconocimiento pero CON grupo de captura.
- **SQLite → Informix**: el diseño original de este puerto (primera
  version) usaba SQLite aislado. Se migró a la conexión Informix real
  compartida por decisión explícita — ver sección 3 para el detalle de
  qué sintaxis cambió (`FIRST 1`, upsert manual, `DBINFO` para el
  último id).
- **`Texto_util::indexar_pagina_productos` NO es un puerto fiel** del
  algoritmo de `server.py` (a diferencia de casi todo el resto del
  modulo) — el original usa una ventana rectangular con margenes fijos
  por ancla, recortada contra los vecinos, pero el recorte solo llega
  hasta la LINEA del codigo del vecino, no hasta el final real de su
  bloque de texto (sus propias viñetas quedan en el hueco entre dos
  anclas y se filtran al producto siguiente). Confirmado en produccion:
  paginas con productos muy juntos (varios por fila, o descripciones
  largas) mezclaban texto de 2-3 productos en un solo `texto_cercano`,
  perdiendo la palabra clave real del producto (ej. "CROP" de "Crop
  Top") — Retail nunca podia homologar contra esos productos aunque
  estuvieran bien indexados. Se reemplazo por asignacion a la ancla mas
  cercana (particion tipo Voronoi, reutilizando
  `Texto_util::producto_mas_cercano()` que ya existia para
  detectar-ofertas) — mas robusto para cualquier layout, pero es una
  mejora real sobre el original, no una traduccion 1:1.

## 7. Checklist antes de conectar la app Flutter real

No se pudo correr nada de esto en esta sesion (sin PHP ni Informix
local):

- [ ] `php -l` sobre cada archivo de `controllers/captura_v1/`,
      `models/captura_v1/` y `libraries/` (sintaxis).
- [ ] `composer install` sin errores.
- [ ] Confirmar que ninguna de las 9 tablas de `schema.sql` colisiona con
      una tabla existente en Informix (ver query de la sección 3).
- [ ] `imagick -list delegate` incluye `gs` (Ghostscript).
- [ ] `tesseract --list-langs` incluye `spa`.
- [ ] Probar `SELECT FIRST 1 ... FROM systables WHERE tabid=1` con
      `DBINFO('sqlca.sqlerrd1')` a mano contra el Informix real, para
      confirmar que el idiom de `Informix_util::ultimo_id_serial()`
      funciona tal cual en esa version del motor.
- [ ] Levantar el servidor y probar con `curl`:
  - `GET /hmvc/captura_v1/info` -> debe devolver el umbral de alerta
    (crea las tablas y siembra los datos la primera vez que corre
    `M_schema::asegurar()`).
  - `POST /hmvc/captura_v1/capturas` con un JSON de prueba.
  - Subir un catalogo PDF de prueba y correr `indexar-productos` /
    `detectar-ofertas`, comparando el resultado contra lo que devolvia el
    prototipo Python para el mismo archivo (si esta disponible).
- [x] Dominio que sirve `RUTA_TEMPORALES` como estatico — confirmado
      `servicioweb2bol.azzorti.co/temporales/` (ver seccion 4). Si el
      servidor de destino final es otro, revalidar ahi.
- [ ] Confirmar permisos de escritura para el usuario de PHP en
      `RUTA_ARCHIVOS . 'captura_v1'` (donde se guarda todo) Y en
      `RUTA_TEMPORALES . 'captura_v1'` (donde `Archivo_util::url_publica()`
      copia cada archivo para poder mostrarlo), y que `captura_v1_base_url`
      sirve estatico esta segunda ruta.
