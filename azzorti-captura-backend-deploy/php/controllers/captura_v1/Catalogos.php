<?php
defined('BASEPATH') or exit('No direct script access allowed');
require APPPATH . '/libraries/RESTController.php';
require APPPATH . '/libraries/Format.php';

use chriskacerguis\RestServer\RestController;

/**
 * Puerto de /catalogos* de server.py: subir/listar (lineas 542-588),
 * detectar-ofertas (2336-2406), listar-ofertas-de-catalogo (2409-2417),
 * indexar-productos (2420-2482) y productos-resumen (2485-2495).
 *
 * Rutas anidadas (necesitan routes.php, ver README):
 *   POST /catalogos/{id}/detectar-ofertas     -> detectar_ofertas_post($id)
 *   GET  /catalogos/{id}/ofertas              -> ofertas_get($id)
 *   POST /catalogos/{id}/indexar-productos    -> indexar_productos_post($id)
 *   GET  /catalogos/{id}/productos-resumen    -> productos_resumen_get($id)
 */
class Catalogos extends RestController {

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
        $this->load->library('texto_util');
        $this->load->library('ocr_helper');
        $this->load->library('archivo_util');
        $this->load->model('captura_v1/m_schema');
        $this->load->model('captura_v1/m_catalogo');
        $this->load->model('captura_v1/m_oferta_referencia');
        $this->m_schema->asegurar();
        $this->ruta_archivos = RUTA_ARCHIVOS . 'captura_v1';
    }

    private function base_url_modulo() {
        return $this->config->item('captura_v1_base_url');
    }

    /** POST /catalogos (server.py lineas 542-569). */
    function index_post() {
        $competidor = trim((string) ($_POST['competidor'] ?? ''));
        $campana = trim((string) ($_POST['campana'] ?? ''));
        if ($competidor === '' || $campana === '' || empty($_FILES['archivo']['tmp_name'])) {
            $this->response(['status' => false, 'message' => 'Faltan competidor, campana o archivo.'], 400);
            return;
        }
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($competidor, 'UTF-8')), '-') ?: 'competidor';
        $slug_campana = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($campana, 'UTF-8'));
        $extension = pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION);
        $extension = $extension ? '.' . $extension : '.pdf';
        $nombre = "{$slug}_{$slug_campana}_" . gmdate('Ymd\THis') . $extension;
        $this->archivo_util->asegurar_carpeta($this->ruta_archivos . '/catalogos_competidor');
        move_uploaded_file($_FILES['archivo']['tmp_name'], $this->ruta_archivos . '/catalogos_competidor/' . $nombre);
        $id = $this->m_catalogo->crear($competidor, $campana, $nombre);
        $this->response(['id' => $id, 'mensaje' => 'Catálogo subido correctamente.'], 201);
    }

    /** GET /catalogos (server.py lineas 572-588). */
    function index_get() {
        $competidor = $this->get('competidor');
        $campana = $this->get('campana');
        $base_url = $this->base_url_modulo();
        $filas = [];
        foreach ($this->m_catalogo->listar($competidor, $campana) as $r) {
            $r = (array) $r;
            $r['archivo_url'] = $this->archivo_util->url_publica('catalogos_competidor/' . $r['archivo'], $base_url);
            $filas[] = $r;
        }
        $this->response($filas, 200);
    }

    /** POST /catalogos/{id}/detectar-ofertas (server.py lineas 2336-2406). */
    function detectar_ofertas_post($catalogo_id) {
        $catalogo = $this->m_catalogo->obtener($catalogo_id);
        if (!$catalogo) {
            $this->response(['status' => false, 'message' => 'Catálogo no encontrado'], 404);
            return;
        }
        $ruta = $this->ruta_archivos . '/catalogos_competidor/' . $catalogo->archivo;
        if (!file_exists($ruta)) {
            $this->response(['status' => false, 'message' => 'El archivo del catálogo ya no está en el servidor.'], 404);
            return;
        }
        if (mb_strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) !== 'pdf') {
            $this->response(['status' => false, 'message' => 'Este catálogo no es un PDF (es una planilla) - las '
                . 'planillas de esta fuente no traen banda de oferta como imagen, solo la foto del producto, así '
                . 'que no hay nada que buscar aquí.'], 400);
            return;
        }
        $ofertas_ref = $this->m_oferta_referencia->por_competidor($catalogo->competidor);
        if (!$ofertas_ref) {
            $this->response(['status' => false, 'message' => "No hay ofertas de referencia importadas para "
                . "'{$catalogo->competidor}' - importa primero el Excel de ofertas (POST /ofertas/importar)."], 400);
            return;
        }

        $num_paginas = $this->ocr_helper->num_paginas($ruta);
        $this->m_catalogo->eliminar_ofertas_de($catalogo_id);
        $con_oferta = 0;
        $con_producto_identificado = 0;

        for ($pno = 0; $pno < $num_paginas; $pno++) {
            // Simplificacion documentada (ver Ocr_helper): se renderiza la
            // pagina completa en vez de solo "la imagen mas grande" como
            // hace el Python original.
            $render = $this->ocr_helper->renderizar_pagina($ruta, $pno);
            $datos = $this->ocr_helper->datos_ocr($render['ruta']);
            unlink($render['ruta']);

            $texto_ocr = implode(' ', array_filter($datos['text'], fn($t) => trim($t) !== ''));
            $detecciones = $this->texto_util->detectar_ofertas_en_pagina($datos, $ofertas_ref);
            if ($detecciones) {
                $con_oferta++;
            }
            foreach ($detecciones as $d) {
                if ($d['producto_codigo']) {
                    $con_producto_identificado++;
                }
                $this->m_catalogo->upsert_oferta($catalogo_id, $pno + 1, $d['producto_codigo'], $d['oferta'], $d['score'], $texto_ocr);
            }
        }

        $this->response([
            'mensaje' => "{$num_paginas} páginas analizadas, {$con_oferta} con oferta identificada "
                . "({$con_producto_identificado} con producto específico ubicado).",
            'paginas_analizadas' => $num_paginas,
            'paginas_con_oferta' => $con_oferta,
            'con_producto_identificado' => $con_producto_identificado,
        ], 200);
    }

    /** GET /catalogos/{id}/ofertas (server.py lineas 2409-2417). */
    function ofertas_get($catalogo_id) {
        $this->response($this->m_catalogo->listar_ofertas_de($catalogo_id), 200);
    }

    /**
     * POST /catalogos/{id}/indexar-productos (server.py lineas 2420-2482).
     *
     * Acepta $_REQUEST['desde']/['hasta'] (0-based, inclusive, opcionales)
     * para procesar solo un rango de paginas en este request - un
     * catalogo de ~300 paginas tarda ~2.5seg/pagina (render + OCR), asi
     * que hacerlo entero en un solo request HTTP da 504 Gateway Timeout
     * mucho antes de terminar (confirmado en produccion: se corto a las
     * ~42 paginas y se quedo ahi, no siguio en el fondo). El dashboard
     * llama esto en tandas (ver indexarProductos() en dashboard.html);
     * sin desde/hasta, procesa el catalogo entero de una (para catalogos
     * chicos, o uso directo por curl).
     */
    function indexar_productos_post($catalogo_id) {
        $catalogo = $this->m_catalogo->obtener($catalogo_id);
        if (!$catalogo) {
            $this->response(['status' => false, 'message' => 'Catálogo no encontrado'], 404);
            return;
        }
        $ruta = $this->ruta_archivos . '/catalogos_competidor/' . $catalogo->archivo;
        if (!file_exists($ruta)) {
            $this->response(['status' => false, 'message' => 'El archivo del catálogo ya no está en el servidor.'], 404);
            return;
        }
        if (mb_strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) !== 'pdf') {
            $this->response(['status' => false, 'message' => 'Este catálogo no es un PDF - no hay página que renderizar.'], 400);
            return;
        }

        $num_paginas = $this->ocr_helper->num_paginas($ruta);
        $desde = isset($_REQUEST['desde']) ? max(0, (int) $_REQUEST['desde']) : 0;
        $hasta = isset($_REQUEST['hasta']) ? min($num_paginas - 1, (int) $_REQUEST['hasta']) : $num_paginas - 1;
        if ($desde > $hasta) {
            $this->response(['status' => false, 'message' => "Rango invalido: desde ({$desde}) > hasta ({$hasta})."], 400);
            return;
        }

        // Solo borra lo ya indexado DE ESTE RANGO (paginas son 1-based en
        // la tabla) - asi una tanda no pisa el trabajo de las demas.
        $this->m_catalogo->eliminar_productos_de($catalogo_id, $desde + 1, $hasta + 1);
        $total_productos = 0;

        for ($pno = $desde; $pno <= $hasta; $pno++) {
            $render = $this->ocr_helper->renderizar_pagina($ruta, $pno);
            $datos = $this->ocr_helper->datos_ocr($render['ruta']);
            $productos_pagina = $this->texto_util->indexar_pagina_productos($datos, $render['alto']);

            if ($productos_pagina) {
                // Recorte por producto: franjas horizontales usando el
                // punto medio entre posiciones Y de codigos consecutivos
                // (server.py lineas 2454-2465).
                $ordenados = $productos_pagina;
                usort($ordenados, fn($a, $b) => $a['y'] <=> $b['y']);
                $im = new Imagick($render['ruta']);
                $n = count($ordenados);
                foreach ($ordenados as $i => $p) {
                    $arriba = $i === 0 ? 0 : ($ordenados[$i - 1]['y'] + $p['y']) / 2;
                    $abajo = $i === $n - 1 ? $render['alto'] : ($p['y'] + $ordenados[$i + 1]['y']) / 2;
                    $y0 = max(0, (int) round($arriba - 10));
                    $y1 = min($render['alto'], (int) round($abajo + 10));
                    $recorte = clone $im;
                    $recorte->cropImage($render['ancho'], max(1, $y1 - $y0), 0, $y0);
                    $this->archivo_util->asegurar_carpeta($this->ruta_archivos . '/catalogo_paginas');
                    $recorte->writeImage($this->ruta_archivos . "/catalogo_paginas/{$catalogo_id}_" . ($pno + 1) . "_{$p['producto_codigo']}.png");
                    $recorte->clear();
                }
                $im->clear();
            }
            unlink($render['ruta']);

            foreach ($productos_pagina as $p) {
                $total_productos++;
                $this->m_catalogo->upsert_producto($catalogo_id, $pno + 1, $p['producto_codigo'], $p['texto_cercano'], $p['precio'], $p['seccion']);
            }
        }

        $completo = $hasta >= $num_paginas - 1;
        $this->response([
            'mensaje' => "Páginas " . ($desde + 1) . "-" . ($hasta + 1) . " de {$num_paginas} analizadas, "
                . "{$total_productos} productos indexados en esta tanda.",
            'pagina_desde' => $desde,
            'pagina_hasta' => $hasta,
            'num_paginas' => $num_paginas,
            'total_productos_tanda' => $total_productos,
            'completo' => $completo,
        ], 200);
    }

    /** GET /catalogos/{id}/productos-resumen (server.py lineas 2485-2495). */
    function productos_resumen_get($catalogo_id) {
        $this->response($this->m_catalogo->resumen_productos($catalogo_id), 200);
    }

    /**
     * POST /catalogos/eliminar/{id} - borra el catalogo (cata_comp), sus
     * productos/ofertas indexados (cata_prod/cata_ofer, para no chocar
     * con la llave foranea) y los archivos fisicos asociados (el PDF
     * subido y los recortes por producto en catalogo_paginas/). Usa POST
     * en vez de DELETE para no depender de que el verbo HTTP DELETE este
     * bien soportado de punta a punta (proxy/webserver) en este servidor
     * - mismo criterio pragmatico que el resto del modulo.
     */
    function eliminar_post($catalogo_id) {
        $catalogo = $this->m_catalogo->obtener($catalogo_id);
        if (!$catalogo) {
            $this->response(['status' => false, 'message' => 'Catálogo no encontrado'], 404);
            return;
        }
        $this->m_catalogo->eliminar_productos_de($catalogo_id);
        $this->m_catalogo->eliminar_ofertas_de($catalogo_id);
        $this->m_catalogo->eliminar($catalogo_id);

        $ruta_pdf = $this->ruta_archivos . '/catalogos_competidor/' . $catalogo->archivo;
        if (file_exists($ruta_pdf)) {
            unlink($ruta_pdf);
        }
        foreach (glob($this->ruta_archivos . "/catalogo_paginas/{$catalogo_id}_*.png") ?: [] as $recorte) {
            unlink($recorte);
        }

        $this->response(['mensaje' => "Catálogo '{$catalogo->archivo}' eliminado."], 200);
    }

    /**
     * TEMPORAL - SOLO DIAGNOSTICO, BORRAR DESPUES DE USAR.
     * GET /catalogos/{id}/diagnostico_pagina?pagina=N - no toca datos,
     * muestra las anclas de producto detectadas en una pagina puntual y
     * el texto que le quedo asignado a cada una, para ver si el problema
     * es que no se detecta el ancla (pocas palabras "COD"/"REF" legibles
     * por bajo contraste) o que el agrupamiento de texto sigue mal.
     */
    function diagnostico_pagina_get($catalogo_id) {
        $pagina = (int) ($_GET['pagina'] ?? 0);
        $catalogo = $this->m_catalogo->obtener($catalogo_id);
        if (!$catalogo) {
            $this->response(['status' => false, 'message' => 'Catálogo no encontrado'], 404);
            return;
        }
        $ruta = $this->ruta_archivos . '/catalogos_competidor/' . $catalogo->archivo;
        if (!file_exists($ruta)) {
            $this->response(['status' => false, 'message' => 'Archivo no encontrado'], 404);
            return;
        }
        $render = $this->ocr_helper->renderizar_pagina($ruta, $pagina);
        $datos = $this->ocr_helper->datos_ocr($render['ruta']);
        $palabras = count(array_filter($datos['text'], fn($t) => trim($t) !== ''));
        $anclas_cod = $this->texto_util->anclas_producto($datos);
        $anclas_ref = $this->texto_util->anclas_producto_retail($datos);
        $productos = $this->texto_util->indexar_pagina_productos($datos, $render['alto']);
        unlink($render['ruta']);

        $this->response([
            'pagina_probada' => $pagina,
            'render_ancho_alto' => "{$render['ancho']}x{$render['alto']}",
            'palabras_ocr_encontradas' => $palabras,
            'texto_muestra' => mb_substr(implode(' ', $datos['text']), 0, 500),
            'anclas_cod' => $anclas_cod,
            'anclas_ref' => $anclas_ref,
            'productos_resultado' => array_map(fn($p) => [
                'codigo' => $p['producto_codigo'],
                'precio' => $p['precio'],
                'seccion' => $p['seccion'],
                'texto_cercano' => mb_substr($p['texto_cercano'], 0, 200),
            ], $productos),
        ], 200);
    }
}
