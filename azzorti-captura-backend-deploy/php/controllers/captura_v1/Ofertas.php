<?php
defined('BASEPATH') or exit('No direct script access allowed');
require APPPATH . '/libraries/RESTController.php';
require APPPATH . '/libraries/Format.php';

use chriskacerguis\RestServer\RestController;

/** Puerto de /ofertas/importar y /ofertas (server.py lineas 2283-2333). */
class Ofertas extends RestController {

    private $ruta_archivos;

    function __construct() {
        // Ver Capturas.php: evita que los warnings de sesion del
        // RESTController (codigo compartido) ensucien el JSON de respuesta.
        ini_set('display_errors', '0');
        parent::__construct();
        header('Access-Control-Allow-Origin: *');
        $this->json = json_decode(file_get_contents('php://input'), true);
        $this->load->helper('url');
        $this->load->config('captura_v1');
        $this->load->library('xlsx_image_extractor');
        $this->load->library('archivo_util');
        $this->load->model('captura_v1/m_schema');
        $this->load->model('captura_v1/m_oferta_referencia');
        $this->m_schema->asegurar();
        $this->ruta_archivos = RUTA_ARCHIVOS . 'captura_v1';
    }

    private function base_url_modulo() {
        return $this->config->item('captura_v1_base_url');
    }

    /** Puerto de _guardar() interno de importar_ofertas_referencia
     * (server.py lineas 2297-2302). */
    private function guardar_foto($bytes, $prefijo) {
        $extension = (substr($bytes, 0, 3) === "\xff\xd8\xff") ? '.jpg' : '.png';
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($prefijo, 'UTF-8')), '-');
        $nombre = $slug . $extension;
        $this->archivo_util->asegurar_carpeta($this->ruta_archivos . '/ofertas_fotos');
        file_put_contents($this->ruta_archivos . '/ofertas_fotos/' . $nombre, $bytes);
        return $nombre;
    }

    /** POST /ofertas/importar (server.py lineas 2283-2323). */
    function importar_post() {
        if (empty($_FILES['archivo']['tmp_name'])) {
            $this->response(['status' => false, 'message' => 'Falta el archivo.'], 400);
            return;
        }
        $ruta_temporal = $_FILES['archivo']['tmp_name'];
        $contenido = file_get_contents($ruta_temporal);

        try {
            $filas = $this->m_oferta_referencia->parsear($ruta_temporal);
        } catch (Exception $e) {
            $this->response(['status' => false, 'message' => 'No se pudo leer el Excel: ' . $e->getMessage()], 400);
            return;
        }
        if (!$filas) {
            $this->response(['status' => false, 'message' => "No se encontraron filas con Catalogo y Nombre oferta."], 400);
            return;
        }

        $fotos_por_celda = $this->xlsx_image_extractor->extraer_fotos_por_celda($contenido);
        $con_foto = 0;
        foreach ($filas as $f) {
            $foto_bytes = $f['_col_foto'] ? ($fotos_por_celda["{$f['_fila_excel']}_{$f['_col_foto']}"] ?? null) : null;
            $foto_nombre = null;
            if ($foto_bytes) {
                $foto_nombre = $this->guardar_foto($foto_bytes, "{$f['competidor']}-{$f['nombre_oferta']}");
                $con_foto++;
            }
            $this->m_oferta_referencia->upsert($f['competidor'], $f['nombre_oferta'], $foto_nombre);
        }

        $this->response([
            'mensaje' => count($filas) . " ofertas de referencia importadas/actualizadas ({$con_foto} con logotipo).",
            'total' => count($filas),
        ], 200);
    }

    /** GET /ofertas (server.py lineas 2326-2333). */
    function index_get() {
        $this->response($this->m_oferta_referencia->listar($this->base_url_modulo()), 200);
    }
}
