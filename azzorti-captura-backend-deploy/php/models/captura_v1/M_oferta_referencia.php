<?php
defined('BASEPATH') or exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Puerto de oferta_referencia: parseo de "Detalle de ofertas.xlsx"
 * (_parsear_ofertas_referencia, server.py lineas 2247-2280), importar
 * (2283-2323) y listar (2326-2333).
 */
class M_oferta_referencia extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->load->library('texto_util');
        $this->load->library('informix_util');
        $this->load->library('archivo_util');
    }

    /**
     * Puerto de _parsear_ofertas_referencia (server.py lineas 2247-2280).
     * Lanza Exception si no encuentra las columnas Catalogo/Nombre oferta.
     */
    public function parsear($ruta_archivo) {
        $libro = IOFactory::load($ruta_archivo);
        $ws = $libro->getSheet(0);
        $max_row = $ws->getHighestRow();
        $max_col = Coordinate::columnIndexFromString($ws->getHighestColumn());

        $col_competidor = null;
        $col_nombre_oferta = null;
        $col_foto = null;
        $fila_encabezado = null;
        for ($r = 1; $r <= min($max_row, 5); $r++) {
            for ($c = 1; $c <= $max_col; $c++) {
                $valor = mb_strtolower($this->texto_util->texto($ws->getCellByColumnAndRow($c, $r)->getCalculatedValue()), 'UTF-8');
                if (mb_strpos($valor, 'catalogo') !== false) {
                    $col_competidor = $c;
                    $fila_encabezado = $r;
                } elseif (mb_strpos($valor, 'nombre') !== false && mb_strpos($valor, 'oferta') !== false) {
                    $col_nombre_oferta = $c;
                } elseif (mb_strpos($valor, 'visual') !== false) {
                    $col_foto = $c;
                }
            }
        }
        if ($fila_encabezado === null || $col_competidor === null || $col_nombre_oferta === null) {
            throw new Exception("No se encontraron las columnas 'Catalogo' y 'Nombre oferta'.");
        }

        $filas = [];
        for ($r = $fila_encabezado + 1; $r <= $max_row; $r++) {
            $competidor = $this->texto_util->texto($ws->getCellByColumnAndRow($col_competidor, $r)->getCalculatedValue());
            $nombre_oferta = $this->texto_util->texto($ws->getCellByColumnAndRow($col_nombre_oferta, $r)->getCalculatedValue());
            if (!$competidor || !$nombre_oferta) {
                continue;
            }
            $filas[] = [
                '_fila_excel' => $r,
                '_col_foto' => $col_foto,
                'competidor' => $competidor,
                'nombre_oferta' => $nombre_oferta,
            ];
        }
        return $filas;
    }

    private function lit($v) {
        return $this->informix_util->literal($v);
    }

    /** server.py lineas 2312-2319 (UPSERT). Tabla real: ofer_refe. */
    public function upsert($competidor, $nombre_oferta, $foto_nombre) {
        $ahora = gmdate('Y-m-d\TH:i:s\Z');
        $where_sql = 'comp = ' . $this->lit($competidor) . ' AND nomb_ofer = ' . $this->lit($nombre_oferta);

        if ($this->informix_util->existe_fila('ofer_refe', $where_sql)) {
            $foto_sql = $foto_nombre === null ? 'foto' : 'COALESCE(' . $this->lit($foto_nombre) . ', foto)';
            $sql = 'UPDATE ofer_refe SET foto = ' . $foto_sql . ', fact = ' . $this->lit($ahora) . ' WHERE ' . $where_sql;
            $this->db->query($sql);
        } else {
            $sql = 'INSERT INTO ofer_refe (comp, nomb_ofer, foto, fact) VALUES ('
                . implode(', ', [$this->lit($competidor), $this->lit($nombre_oferta), $this->lit($foto_nombre), $this->lit($ahora)]) . ')';
            $this->db->query($sql);
        }
    }

    /** server.py lineas 2326-2333. */
    public function listar($base_url) {
        $filas = $this->db->query(
            'SELECT id, comp AS competidor, nomb_ofer AS nombre_oferta, foto, fact AS actualizado_en '
            . 'FROM ofer_refe ORDER BY comp, nomb_ofer'
        )->result_array();
        foreach ($filas as &$r) {
            $r['foto_url'] = $r['foto'] ? $this->archivo_util->url_publica('ofertas_fotos/' . $r['foto'], $base_url) : null;
        }
        return $filas;
    }

    /** server.py lineas 2356-2359: ofertas de referencia de un competidor
     * (case/trim-insensitive), usado por detectar-ofertas. */
    public function por_competidor($competidor) {
        return $this->db->query(
            'SELECT id, comp AS competidor, nomb_ofer AS nombre_oferta, foto, fact AS actualizado_en '
            . 'FROM ofer_refe WHERE LOWER(TRIM(comp)) = LOWER(TRIM(' . $this->lit($competidor) . '))'
        )->result();
    }
}
