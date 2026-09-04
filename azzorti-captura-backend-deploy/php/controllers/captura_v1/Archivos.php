<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * NO SE USA POR DEFAULT (ver README seccion 4): la convencion real del
 * backend es escribir los archivos bajo la constante global RUTA_ARCHIVOS
 * (definida una sola vez en el constants.php compartido de hmvc/, igual
 * que la usan vincu_v1/M_vinculacion.php y ventas_v1/M_inscripcion.php -
 * este modulo no la redefine, solo le agrega su propia subcarpeta
 * "captura_v1/"), servida por un vhost estatico aparte -
 * captura_v1_base_url ya apunta ahi directo, sin pasar por este
 * controller ni por hmvc/.
 *
 * Se deja este archivo como red de seguridad por si ese vhost dejara de
 * servir estatico en algun momento: sirve los archivos directo por PHP
 * (leyendo del disco), sin depender de que el webserver los reconozca
 * como estaticos. Para activarlo alcanza con apuntar captura_v1_base_url
 * (config/captura_v1.php) a ".../hmvc/captura_v1/" de nuevo, agregando
 * "archivos/" antes de la subcarpeta en cada URL que arma foto_url -
 * $ruta_archivos no cambia, sigue siendo RUTA_ARCHIVOS . 'captura_v1'
 * (es una ruta fisica en disco, no depende de por donde se sirva).
 *
 * URL (si se activa): /hmvc/captura_v1/archivos/<subcarpeta>/<archivo>
 * (subcarpeta: capturas_fotos, catalogos_competidor,
 * productos_estrella_fotos, ofertas_fotos o catalogo_paginas)
 *
 * Usa _remap() en vez de un metodo por subcarpeta para no tener que
 * declarar 5 metodos identicos - CodeIgniter le pasa el primer segmento
 * despues del controller como $subcarpeta y el resto como $params.
 */
class Archivos extends CI_Controller {

    private $ruta_archivos;

    const MIME_POR_EXTENSION = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls' => 'application/vnd.ms-excel',
    ];

    function __construct() {
        // Ver Capturas.php: evita que los warnings de sesion de codigo
        // compartido ensucien la respuesta (acá seria peor: corromperia
        // el binario del archivo, no solo un JSON).
        ini_set('display_errors', '0');
        parent::__construct();
        $this->ruta_archivos = RUTA_ARCHIVOS . 'captura_v1';
    }

    public function _remap($subcarpeta, $params = []) {
        $archivo = $params[0] ?? null;
        if (!$archivo) {
            $this->error_404();
            return;
        }
        // basename() corta cualquier intento de "../" - solo se sirve
        // dentro de $this->ruta_archivos, nunca fuera de ahi.
        $ruta = $this->ruta_archivos . '/' . basename($subcarpeta) . '/' . basename($archivo);
        if (!is_file($ruta)) {
            $this->error_404();
            return;
        }
        header('Content-Type: ' . $this->mime_de($ruta));
        header('Content-Length: ' . filesize($ruta));
        header('Cache-Control: public, max-age=86400');
        readfile($ruta);
    }

    private function mime_de($ruta) {
        $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
        return self::MIME_POR_EXTENSION[$ext] ?? 'application/octet-stream';
    }

    private function error_404() {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: application/json');
        echo json_encode(['status' => false, 'message' => 'Archivo no encontrado']);
    }
}
