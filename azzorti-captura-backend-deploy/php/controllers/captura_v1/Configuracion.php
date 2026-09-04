<?php
defined('BASEPATH') or exit('No direct script access allowed');
require APPPATH . '/libraries/RESTController.php';
require APPPATH . '/libraries/Format.php';

use chriskacerguis\RestServer\RestController;

/**
 * Puerto de /info y /configuracion/* de server.py (lineas 460-530).
 * Convenciones tomadas del backend real (ver Usuario.php/Inscripcion.php
 * en ventas_v1): extiende RestController, CORS manual, body via
 * php://input, $this->response([...], codigoHttp).
 *
 * Las URLs originales usan guiones ("umbral-alerta", "tipo-cambio") que
 * no son nombres de metodo PHP validos - necesitan una entrada en
 * application/config/routes.php que los traduzca a los metodos de abajo
 * (umbral_alerta_get/post, tipo_cambio_get/post). Ver el fragmento de
 * rutas en el README del modulo.
 */
class Configuracion extends RestController {

    function __construct() {
        // Ver Capturas.php: evita que los warnings de sesion del
        // RESTController (codigo compartido) ensucien el JSON de respuesta.
        ini_set('display_errors', '0');
        parent::__construct();
        header('Access-Control-Allow-Origin: *');
        $this->json = json_decode(file_get_contents('php://input'), true);
        $this->load->model('captura_v1/m_schema');
        $this->load->model('captura_v1/m_configuracion');
        $this->m_schema->asegurar();
    }

    /** GET /info (server.py lineas 460-474). */
    function info_get() {
        $this->response([
            'servicio' => 'Azzorti Benchmarking — backend PHP (puerto del prototipo)',
            'nota' => 'azzorti_producto (campana C-10) es dato real de negocio, cruzado entre '
                . 'inv_prca_202610.xlsx y el catalogo digital BOL202610NAL.pdf. politica_precio '
                . 'quedo retirada de la evaluacion: el umbral unico de configuracion reemplaza '
                . 'las politicas puntuales por categoria, tanto en Retail como en Venta Directa.',
            'umbral_alerta_generica_pct' => $this->m_schema->umbral_alerta_actual(),
        ], 200);
    }

    /** GET /configuracion/umbral-alerta (server.py lineas 485-488). */
    function umbral_alerta_get() {
        $this->response(['umbral_pct' => $this->m_schema->umbral_alerta_actual()], 200);
    }

    /** POST /configuracion/umbral-alerta (server.py lineas 491-507). */
    function umbral_alerta_post() {
        $umbral_pct = isset($this->json['umbral_pct']) ? (float) $this->json['umbral_pct'] : null;
        if ($umbral_pct === null || $umbral_pct <= 0) {
            $this->response(['status' => false, 'message' => 'El umbral debe ser un porcentaje mayor a 0.'], 400);
            return;
        }
        $this->m_configuracion->actualizar_umbral_alerta($umbral_pct);
        $this->response(['umbral_pct' => $umbral_pct, 'mensaje' => 'Umbral actualizado.'], 200);
    }

    /** GET /configuracion/tipo-cambio (server.py lineas 510-514). */
    function tipo_cambio_get() {
        $this->response(['tc' => $this->m_configuracion->tipo_cambio_actual()], 200);
    }

    /** POST /configuracion/tipo-cambio (server.py lineas 517-530). */
    function tipo_cambio_post() {
        $tc = isset($this->json['tc']) ? (float) $this->json['tc'] : null;
        if ($tc === null || $tc <= 0) {
            $this->response(['status' => false, 'message' => 'El tipo de cambio debe ser mayor a 0.'], 400);
            return;
        }
        $this->m_configuracion->actualizar_tipo_cambio($tc);
        $this->response(['tc' => $tc, 'mensaje' => 'Tipo de cambio actualizado.'], 200);
    }
}
