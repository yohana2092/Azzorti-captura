<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * La ruta fisica DE ESCRITURA de este modulo NO se define aqui: se arma
 * en cada controller como RUTA_ARCHIVOS . 'captura_v1' (constante global
 * ya definida por el constants.php compartido de hmvc/ - la misma que
 * usan vincu_v1/M_vinculacion.php y ventas_v1/M_inscripcion.php en el
 * mirror C:\Trabajo\Apis EC, siempre a secas, nunca via
 * $this->config->item()). Reproducirla como config propio del modulo
 * hubiera sido una fuente de verdad separada y desactualizable de la
 * real - "captura_v1/" es el unico subfolder propio, para no mezclarse
 * con lo que ya guarda ahi el resto del sistema (clientes/vinculacion/,
 * etc.).
 *
 * RUTA_ARCHIVOS es una ruta SENSIBLE, sin acceso HTTP directo. Por eso,
 * para CONSULTAR un archivo (armar cualquier *_url de respuesta), este
 * modulo primero lo copia a la otra constante global, RUTA_TEMPORALES
 * (esa si publica) - ver Archivo_util::url_publica(), usado por todos
 * los modelos que arman foto_url/archivo_url. Mismo patron que
 * M_panel.php::pedi_cons_lide en el mirror (copia a RUTA_TEMPORALES
 * antes de que el cliente pueda verlo).
 *
 * captura_v1_base_url de aca abajo es la URL publica que sirve esa copia
 * en RUTA_TEMPORALES (no la de RUTA_ARCHIVOS).
 *
 * CONFIRMADO en el servidor real (GET /catalogos, 2026-09-02): a
 * diferencia de RUTA_ARCHIVOS (que en el mirror varia por pais, ej.
 * intranet2ecu.azzorti.co / intranet.azzorti.com), RUTA_TEMPORALES
 * cuelga del MISMO host que sirve la API
 * (servicioweb2bol.azzorti.co) bajo la subruta estatica /temporales/ -
 * no un vhost aparte. Un archivo_url devuelto por la API con este valor
 * ya se probo accesible.
 */
$config['captura_v1_base_url'] = 'https://servicioweb2bol.azzorti.co/temporales/captura_v1/';
