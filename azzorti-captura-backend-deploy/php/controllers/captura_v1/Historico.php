<?php
defined('BASEPATH') or exit('No direct script access allowed');
require APPPATH . '/libraries/RESTController.php';
require APPPATH . '/libraries/Format.php';

use chriskacerguis\RestServer\RestController;

/** Puerto de GET /historico (server.py lineas 1750-1819). */
class Historico extends RestController {

    function __construct() {
        // Ver Capturas.php: evita que los warnings de sesion del
        // RESTController (codigo compartido) ensucien el JSON de respuesta.
        ini_set('display_errors', '0');
        parent::__construct();
        header('Access-Control-Allow-Origin: *');
        $this->json = json_decode(file_get_contents('php://input'), true);
        $this->load->model('captura_v1/m_schema');
        $this->load->model('captura_v1/m_historico');
        $this->m_schema->asegurar();
    }

    /** GET /historico. */
    function index_get() {
        $this->response($this->m_historico->calcular(), 200);
    }
}
