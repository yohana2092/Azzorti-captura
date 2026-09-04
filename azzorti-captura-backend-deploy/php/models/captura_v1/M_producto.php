<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Consultas sobre azzo_prod (tabla real; nombre logico "azzorti_producto",
 * los 19 productos de muestra sembrados por M_schema, campanas "C10 2026"/
 * "C05 2026" - ver server.py lineas 288-346).
 *
 * Los SELECT usan alias (col AS nombre_legible) para que el resto del
 * codigo PHP siga leyendo ->sku, ->categoria, ->precio, etc. sin
 * enterarse de que en la base los nombres de columna son abreviados. */
class M_producto extends CI_Model {

    const COLUMNAS = 'sku, cate AS categoria, dscr AS descripcion, colo AS color, tela AS composicion, '
        . 'silu AS silueta, mang AS manga, prec AS precio, camp AS campana, '
        . 'pagi_cata AS pagina_catalogo, foto_arch AS foto_archivo';

    public function __construct() {
        parent::__construct();
        $this->load->library('informix_util');
    }

    public function por_sku($sku) {
        $sku_lit = $this->informix_util->literal($sku);
        return $this->db->query('SELECT ' . self::COLUMNAS . " FROM azzo_prod WHERE sku = {$sku_lit}")->row();
    }

    /** server.py linea 1548-1551: candidatos "muestra" para homologacion Retail. */
    public function por_categoria($categoria) {
        $cate_lit = $this->informix_util->literal($categoria);
        return $this->db->query('SELECT ' . self::COLUMNAS . " FROM azzo_prod WHERE cate = {$cate_lit}")->result();
    }
}
