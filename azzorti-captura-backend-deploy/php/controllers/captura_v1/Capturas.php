<?php
defined('BASEPATH') or exit('No direct script access allowed');
require APPPATH . '/libraries/RESTController.php';
require APPPATH . '/libraries/Format.php';

use chriskacerguis\RestServer\RestController;

/**
 * Puerto de /capturas* de server.py (lineas 1117-1735): crear, listar,
 * sugerencias de homologacion (Retail vs Venta Directa), confirmar y
 * evaluar. La logica de negocio vive en M_captura/M_homologacion; este
 * controller solo valida entrada, arma el base_url para las fotos y
 * traduce a codigos HTTP (igual patron que Usuario.php/Inscripcion.php
 * del backend real).
 *
 * Rutas con segmentos anidados (necesitan entrada en routes.php, ver
 * README del modulo):
 *   GET  /capturas/{id}/homologacion/sugerencias -> homologacion_sugerencias_get($id)
 *   POST /capturas/{id}/homologacion/confirmar   -> homologacion_confirmar_post($id)
 *   GET  /capturas/{id}/evaluacion                -> evaluacion_get($id)
 */
class Capturas extends RestController {

    // Debe apuntar a donde queda montado este modulo (config_item('base_url')
    // de CodeIgniter) - se usa para armar las URL de foto igual que
    // request.base_url en el Python original. Ver README.
    private $ruta_archivos;

    function __construct() {
        // El constructor de RESTController (linea 257, codigo compartido
        // del backend real, no de este modulo) dispara warnings de
        // sesion en este servidor (una sesion PHP ya viene activa antes
        // de que CI intente configurarla de nuevo). Sin esto, esos
        // warnings se imprimen como HTML antes del JSON de la respuesta
        // y rompen el parseo en el cliente. No oculta el problema de
        // fondo (eso esta fuera de este modulo), solo evita que ensucie
        // la respuesta de la API.
        ini_set('display_errors', '0');
        parent::__construct();
        header('Access-Control-Allow-Origin: *');
        $this->json = json_decode(file_get_contents('php://input'), true);
        $this->load->helper('url');
        $this->load->config('captura_v1');
        $this->load->model('captura_v1/m_schema');
        $this->load->model('captura_v1/m_captura');
        $this->load->model('captura_v1/m_homologacion');
        $this->load->library('archivo_util');
        $this->m_schema->asegurar();
        $this->ruta_archivos = RUTA_ARCHIVOS . 'captura_v1';
    }

    private function base_url_modulo() {
        return $this->config->item('captura_v1_base_url');
    }

    /** POST /capturas (server.py lineas 1117-1176). */
    function index_post() {
        $c = [
            'competidor' => trim((string) ($this->json['competidor'] ?? '')),
            'canal' => trim((string) ($this->json['canal'] ?? 'Retail')),
            'campana' => trim((string) ($this->json['campana'] ?? '')),
            'categoria' => trim((string) ($this->json['categoria'] ?? '')),
            'nivel_precio' => trim((string) ($this->json['nivel_precio'] ?? '')),
            'descripcion' => $this->json['descripcion'] ?? null,
            'silueta' => $this->json['silueta'] ?? null,
            'talla' => $this->json['talla'] ?? null,
            'composicion1' => $this->json['composicion1'] ?? null,
            'composicion2' => $this->json['composicion2'] ?? null,
            'manga' => $this->json['manga'] ?? null,
            'color' => $this->json['color'] ?? null,
            'detalle' => $this->json['detalle'] ?? null,
            'caracteristicas' => $this->json['caracteristicas'] ?? null,
            'precio' => isset($this->json['precio']) ? (float) $this->json['precio'] : null,
            'sku_competidor' => $this->json['sku_competidor'] ?? null,
            'catalogo_id' => isset($this->json['catalogo_id']) ? (int) $this->json['catalogo_id'] : null,
        ];
        if ($c['competidor'] === '' || $c['campana'] === '' || $c['categoria'] === ''
            || $c['nivel_precio'] === '' || $c['precio'] === null) {
            $this->response(['status' => false, 'message' => 'Faltan campos obligatorios.'], 400);
            return;
        }

        $reciente = $this->m_captura->duplicado_reciente($c);
        if ($reciente) {
            $this->response([
                'id' => $reciente->id,
                'mensaje' => 'Ya se habia sincronizado este producto hace unos minutos - no se duplico.',
            ], 201);
            return;
        }

        $foto_archivo = null;
        if (!empty($this->json['foto_producto_b64'])) {
            try {
                $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($c['competidor'], 'UTF-8')), '-') ?: 'captura';
                // server.py linea 537: sufijo de microsegundos para evitar
                // colisiones si dos capturas llegan en el mismo segundo.
                $ahora = \DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
                $nombre = $slug . '_' . $ahora->format('Ymd\THis') . $ahora->format('u') . '.jpg';
                $bytes = base64_decode($this->json['foto_producto_b64'], true);
                if ($bytes === false) {
                    throw new Exception('base64 invalido');
                }
                $this->archivo_util->asegurar_carpeta($this->ruta_archivos . '/capturas_fotos');
                file_put_contents($this->ruta_archivos . '/capturas_fotos/' . $nombre, $bytes);
                $foto_archivo = $nombre;
            } catch (Exception $e) {
                $foto_archivo = null; // best-effort: si la foto falla, la captura sigue (server.py 1146-1151)
            }
        }

        if ($this->m_captura->existe_duplicado($c)) {
            // UNIQUE(competidor, campana, sku_competidor) - server.py 1169-1174.
            $this->response([
                'status' => false,
                'message' => "Registro duplicado: ya existe una captura de '{$c['competidor']}' "
                    . "con SKU '{$c['sku_competidor']}' en la campaña '{$c['campana']}'.",
            ], 409);
            return;
        }
        $id = $this->m_captura->crear($c, $foto_archivo);
        $this->response(['id' => $id, 'mensaje' => 'Captura sincronizada correctamente.'], 201);
    }

    /** GET /capturas (server.py lineas 1179-1323). */
    function index_get() {
        $competidor = $this->get('competidor');
        $campana = $this->get('campana');
        $resultado = $this->m_captura->listar($competidor, $campana, $this->base_url_modulo());
        $this->response($resultado, 200);
    }

    /** GET /capturas/{id}/homologacion/sugerencias (server.py lineas 1534-1621). */
    function homologacion_sugerencias_get($captura_id) {
        $captura = $this->m_captura->por_id($captura_id);
        if (!$captura) {
            $this->response(['status' => false, 'message' => 'Captura no encontrada'], 404);
            return;
        }
        $base_url = $this->base_url_modulo();
        if ($captura->canal === 'Venta Directa') {
            $resultado = $this->m_homologacion->sugerir_venta_directa($captura, $base_url);
        } else {
            $resultado = $this->m_homologacion->sugerir_retail($captura, $base_url);
        }
        $this->response($resultado, 200);
    }

    /** POST /capturas/{id}/homologacion/confirmar (server.py lineas 1624-1654). */
    function homologacion_confirmar_post($captura_id) {
        $captura = $this->m_captura->por_id($captura_id);
        if (!$captura) {
            $this->response(['status' => false, 'message' => 'Captura no encontrada'], 404);
            return;
        }
        $azzorti_sku = trim((string) ($this->json['azzorti_sku'] ?? ''));
        if ($azzorti_sku === '') {
            $this->response(['status' => false, 'message' => 'azzorti_sku es obligatorio'], 400);
            return;
        }
        if (!$this->m_captura->sku_valido_para_canal($captura, $azzorti_sku)) {
            $mensaje = $captura->canal === 'Venta Directa'
                ? "Código '{$azzorti_sku}' no existe en el catálogo de Azzorti indexado"
                : "SKU Azzorti '{$azzorti_sku}' no existe en el catálogo";
            $this->response(['status' => false, 'message' => $mensaje], 404);
            return;
        }
        $this->m_captura->confirmar_homologacion($captura_id, $azzorti_sku);
        $this->response(['mensaje' => "Homologación confirmada: captura {$captura_id} <-> {$azzorti_sku}"], 200);
    }

    /** GET /capturas/{id}/evaluacion (server.py lineas 1657-1735). */
    function evaluacion_get($captura_id) {
        $captura = $this->m_captura->por_id($captura_id);
        if (!$captura) {
            $this->response(['status' => false, 'message' => 'Captura no encontrada'], 404);
            return;
        }
        $resultado = $this->m_captura->evaluar($captura_id);
        if (isset($resultado['error'])) {
            $this->response(['status' => false, 'message' => $resultado['error']], 404);
            return;
        }
        $this->response($resultado, 200);
    }
}
