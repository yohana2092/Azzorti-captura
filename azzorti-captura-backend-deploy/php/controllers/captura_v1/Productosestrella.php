<?php
defined('BASEPATH') or exit('No direct script access allowed');
require APPPATH . '/libraries/RESTController.php';
require APPPATH . '/libraries/Format.php';

use chriskacerguis\RestServer\RestController;

/**
 * Puerto de /productos-estrella/importar y /productos-estrella (server.py
 * lineas 941-1114). Sigue el patron de subida de archivos ya usado en el
 * backend real (Inscripcion.php::fotos_post -> $_FILES, ver M_inscripcion::
 * cargaImagen) en vez del multipart de FastAPI/Form.
 *
 * URLs reales (sin routes.php, ver README seccion 5):
 *   POST /hmvc/captura_v1/productosestrella/importar -> importar_post()
 *   GET  /hmvc/captura_v1/productosestrella           -> index_get()
 *
 * El archivo/clase se llaman "Productosestrella" (sin la E interna en
 * mayuscula) a proposito: CodeIgniter resuelve el controller como
 * ucfirst($segmento_de_uri) - con el segmento "productosestrella" (todo
 * minuscula, como cualquier URL) eso da "Productosestrella", que en un
 * filesystem case-sensitive (el servidor real es Linux) NO matchea un
 * archivo "ProductosEstrella.php". Iba a dar 404 sin routes.php aunque
 * el resto del enrutamiento estuviera bien.
 */
class Productosestrella extends RestController {

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
        $this->load->model('captura_v1/m_producto_estrella');
        $this->m_schema->asegurar();
        $this->ruta_archivos = RUTA_ARCHIVOS . 'captura_v1';
    }

    private function base_url_modulo() {
        return $this->config->item('captura_v1_base_url');
    }

    /** Puerto de _guardar() interno de importar_productos_estrella
     * (server.py lineas 964-969): extension por magic bytes JPEG, resto PNG. */
    private function guardar_foto($carpeta, $bytes, $prefijo) {
        $extension = (substr($bytes, 0, 3) === "\xff\xd8\xff") ? '.jpg' : '.png';
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($prefijo, 'UTF-8')), '-');
        $nombre = $slug . $extension;
        $this->archivo_util->asegurar_carpeta($this->ruta_archivos . '/' . $carpeta);
        file_put_contents($this->ruta_archivos . '/' . $carpeta . '/' . $nombre, $bytes);
        return $nombre;
    }

    /** POST /productos-estrella/importar (server.py lineas 941-1045). */
    function importar_post() {
        $campana = trim((string) ($_POST['campana'] ?? ''));
        if ($campana === '' || empty($_FILES['archivo']['tmp_name'])) {
            $this->response(['status' => false, 'message' => 'Falta campana o archivo.'], 400);
            return;
        }
        $ruta_temporal = $_FILES['archivo']['tmp_name'];
        $contenido = file_get_contents($ruta_temporal);

        try {
            $filas = $this->m_producto_estrella->parsear($ruta_temporal);
        } catch (Exception $e) {
            $this->response(['status' => false, 'message' => 'No se pudo leer el Excel: ' . $e->getMessage()], 400);
            return;
        }
        if (!$filas) {
            $this->response([
                'status' => false,
                'message' => 'No se encontraron filas con el formato esperado (columnas '
                    . 'Descripción y Referente Azzorti). Revisa los encabezados del archivo.',
            ], 400);
            return;
        }

        $fotos_por_fila = $this->xlsx_image_extractor->extraer_fotos_por_fila($contenido);
        $fotos_por_celda = $this->xlsx_image_extractor->extraer_fotos_por_celda($contenido);

        $con_foto_competidor = 0;
        $con_foto_azzorti = 0;
        $sospechosos = [];
        foreach ($filas as $f) {
            if ($f['precio_azzorti'] !== null && !empty($f['precio_competidor'])
                && $f['precio_azzorti'] < $f['precio_competidor'] * 0.2) {
                $sospechosos[] = "{$f['competidor']} · {$f['descripcion_competidor']} (Bs {$f['precio_azzorti']})";
            }

            $fila_excel = $f['_fila_excel'];
            $col_foto = $f['_col_foto'];
            $col_foto_azzorti = $f['_col_foto_azzorti'];

            $foto_competidor_bytes = ($col_foto ? ($fotos_por_celda["{$fila_excel}_{$col_foto}"] ?? null) : null)
                ?: ($fotos_por_fila[$fila_excel] ?? null);
            $foto_competidor_nombre = null;
            if ($foto_competidor_bytes) {
                $foto_competidor_nombre = $this->guardar_foto(
                    'productos_estrella_fotos', $foto_competidor_bytes,
                    "{$f['competidor']}-{$f['descripcion_competidor']}"
                );
                $con_foto_competidor++;
            }

            $foto_azzorti_bytes = $fotos_por_celda["{$fila_excel}_{$col_foto_azzorti}"] ?? null;
            $foto_azzorti_nombre = null;
            if ($foto_azzorti_bytes) {
                $foto_azzorti_nombre = $this->guardar_foto(
                    'productos_estrella_fotos', $foto_azzorti_bytes,
                    'azzorti-' . ($f['azzorti_referente'] ?: $f['descripcion_competidor'])
                );
                $con_foto_azzorti++;
            }

            $this->m_producto_estrella->upsert($f, $foto_competidor_nombre, $foto_azzorti_nombre, $campana);
        }

        $mensaje = count($filas) . ' productos estrella importados/actualizados '
            . "({$con_foto_competidor} con foto de competidor, {$con_foto_azzorti} con foto Azzorti).";
        if ($sospechosos) {
            $mostrados = array_slice($sospechosos, 0, 5);
            $mensaje .= ' ⚠ ' . count($sospechosos) . ' con precio Azzorti sospechosamente bajo, revisa esa '
                . 'columna en el archivo: ' . implode('; ', $mostrados) . (count($sospechosos) > 5 ? '...' : '');
        }
        $this->response(['mensaje' => $mensaje, 'total' => count($filas), 'sospechosos' => $sospechosos], 200);
    }

    /** GET /productos-estrella (server.py lineas 1048-1114). */
    function index_get() {
        $campana = $this->get('campana');
        $umbral = $this->m_schema->umbral_alerta_actual();
        $this->response($this->m_producto_estrella->listar($campana, $umbral, $this->base_url_modulo()), 200);
    }
}
