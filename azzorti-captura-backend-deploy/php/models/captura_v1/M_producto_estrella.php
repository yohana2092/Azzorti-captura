<?php
defined('BASEPATH') or exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Puerto de producto_estrella: parseo del Excel real de Mercadeo
 * (_parsear_productos_estrella, server.py lineas 615-709), importar
 * (941-1045) y listar con calculo de delta_pct (1048-1114).
 *
 * Requiere PhpSpreadsheet (composer) para leer valores de celda - la
 * extraccion de FOTOS ancladas/en celda es aparte (Xlsx_image_extractor),
 * PhpSpreadsheet no cubre eso.
 */
class M_producto_estrella extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->load->library('texto_util');
        $this->load->library('informix_util');
        $this->load->library('archivo_util');
    }

    private function ahora_iso() {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Puerto de _parsear_productos_estrella (server.py lineas 615-709).
     * @return array de filas con claves: competidor, categoria,
     *   descripcion_competidor, modo, azzorti_referente, precio_competidor,
     *   campana_competidor, precio_azzorti, campana_azzorti, _fila_excel,
     *   _col_foto, _col_foto_azzorti.
     */
    public function parsear($ruta_archivo) {
        $libro = IOFactory::load($ruta_archivo);
        $filas = [];
        foreach ($libro->getAllSheets() as $ws) {
            $max_row = $ws->getHighestRow();
            $max_col = Coordinate::columnIndexFromString($ws->getHighestColumn());

            $fila_encabezado = null;
            for ($r = 1; $r <= min($max_row, 15) && $fila_encabezado === null; $r++) {
                for ($c = 1; $c <= $max_col; $c++) {
                    $valor = $this->texto_util->texto($ws->getCellByColumnAndRow($c, $r)->getCalculatedValue());
                    if (mb_strpos(mb_strtolower($valor, 'UTF-8'), 'descri') === 0) {
                        $fila_encabezado = $r;
                        break;
                    }
                }
            }
            if ($fila_encabezado === null) {
                continue;
            }

            $col_descripcion = null;
            $col_referente = null;
            for ($c = 1; $c <= $max_col; $c++) {
                $texto = mb_strtolower($this->texto_util->texto($ws->getCellByColumnAndRow($c, $fila_encabezado)->getCalculatedValue()), 'UTF-8');
                if (mb_strpos($texto, 'descri') === 0) {
                    $col_descripcion = $c;
                } elseif ((mb_strpos($texto, 'referente') !== false || mb_strpos($texto, 'azzorti') !== false)
                    && mb_strpos($texto, 'precio') === false) {
                    $col_referente = $c;
                }
            }
            if (!$col_descripcion || !$col_referente) {
                continue;
            }

            $col_competidor = 1;
            $col_categoria = $col_descripcion - 1;
            $col_foto = $col_descripcion + 1;
            $col_precio_competidor = $col_descripcion + 2;
            $col_campana_competidor = $col_descripcion + 3;
            $col_foto_azzorti = $col_referente + 1;
            $col_precio_azzorti = $col_referente + 2;
            $col_campana_azzorti = $col_referente + 3;
            $tiene_bloque_azzorti = $col_campana_azzorti <= $max_col;

            $ultimo_competidor = null;
            for ($r = $fila_encabezado + 1; $r <= $max_row; $r++) {
                $valor_competidor = $this->texto_util->texto($ws->getCellByColumnAndRow($col_competidor, $r)->getCalculatedValue());
                $competidor = $valor_competidor !== '' ? $valor_competidor : $ultimo_competidor;
                if ($valor_competidor !== '') {
                    $ultimo_competidor = $competidor;
                }
                $descripcion = $this->texto_util->texto($ws->getCellByColumnAndRow($col_descripcion, $r)->getCalculatedValue());
                $referente = $this->texto_util->texto($ws->getCellByColumnAndRow($col_referente, $r)->getCalculatedValue());
                if (!$competidor || !$descripcion || !$referente) {
                    continue;
                }

                $referente_lower = mb_strtolower($referente, 'UTF-8');
                $es_vs_anterior = mb_strpos($referente_lower, 'campaña anterior') !== false
                    || mb_strpos($referente_lower, 'campana anterior') !== false;
                $valor_col_azzorti = $tiene_bloque_azzorti
                    ? $this->texto_util->numero($ws->getCellByColumnAndRow($col_precio_azzorti, $r)->getCalculatedValue())
                    : null;

                $campana_azzorti_raw = $tiene_bloque_azzorti
                    ? $this->texto_util->texto($ws->getCellByColumnAndRow($col_campana_azzorti, $r)->getCalculatedValue())
                    : '';
                $campana_azzorti = ($tiene_bloque_azzorti && preg_match(Texto_util::CAMPANA_ESTANDAR_RE, $campana_azzorti_raw))
                    ? $campana_azzorti_raw
                    : null;

                $filas[] = [
                    'competidor' => $competidor,
                    'categoria' => $col_categoria >= 1
                        ? $this->texto_util->texto($ws->getCellByColumnAndRow($col_categoria, $r)->getCalculatedValue())
                        : null,
                    'descripcion_competidor' => $descripcion,
                    'modo' => $es_vs_anterior ? 'VS_CAMPANA_ANTERIOR' : 'HOMOLOGO_FIJO',
                    'azzorti_referente' => $referente,
                    'precio_competidor' => $this->texto_util->numero($ws->getCellByColumnAndRow($col_precio_competidor, $r)->getCalculatedValue()),
                    'campana_competidor' => $this->texto_util->texto($ws->getCellByColumnAndRow($col_campana_competidor, $r)->getCalculatedValue()) ?: null,
                    'precio_azzorti' => $valor_col_azzorti,
                    'campana_azzorti' => $campana_azzorti,
                    '_fila_excel' => $r,
                    '_col_foto' => $col_foto,
                    '_col_foto_azzorti' => $col_foto_azzorti,
                ];
            }
        }
        return $filas;
    }

    private function lit($v) {
        return $this->informix_util->literal($v);
    }

    /** server.py lineas 1005-1031 (UPSERT). Tabla real: prod_estr. */
    public function upsert($f, $foto_competidor_nombre, $foto_azzorti_nombre, $campana_form) {
        $campana_azzorti = $f['campana_azzorti'] ?: ($f['modo'] === 'HOMOLOGO_FIJO' ? $campana_form : null);
        $ahora = $this->ahora_iso();
        $where_sql = 'comp = ' . $this->lit($f['competidor']) . ' AND desc_comp = ' . $this->lit($f['descripcion_competidor'])
            . ' AND camp = ' . $this->lit($campana_form);

        if ($this->informix_util->existe_fila('prod_estr', $where_sql)) {
            // COALESCE(NULL, col) = col: se escribe el literal NULL igual
            // que en cualquier otro campo (ver Informix_util::literal) -
            // el resultado semantico de "no pisar la foto existente si la
            // nueva importacion no trae una" es identico.
            $foto_competidor_sql = $foto_competidor_nombre === null ? 'foto_comp' : 'COALESCE(' . $this->lit($foto_competidor_nombre) . ', foto_comp)';
            $foto_azzorti_sql = $foto_azzorti_nombre === null ? 'foto_azzo' : 'COALESCE(' . $this->lit($foto_azzorti_nombre) . ', foto_azzo)';
            $sql = 'UPDATE prod_estr SET cate = ' . $this->lit($f['categoria']) . ', modo = ' . $this->lit($f['modo'])
                . ', azzo_refe = ' . $this->lit($f['azzorti_referente'])
                . ', prec_comp = ' . $this->lit($f['precio_competidor']) . ', prec_azzo = ' . $this->lit($f['precio_azzorti'])
                . ', camp_azzo = ' . $this->lit($campana_azzorti)
                . ', foto_comp = ' . $foto_competidor_sql . ', foto_azzo = ' . $foto_azzorti_sql
                . ', fact = ' . $this->lit($ahora)
                . ' WHERE ' . $where_sql;
            $this->db->query($sql);
        } else {
            $sql = 'INSERT INTO prod_estr '
                . '(comp, cate, desc_comp, modo, azzo_refe, '
                . 'prec_comp, prec_azzo, camp_azzo, foto_comp, foto_azzo, '
                . 'camp, fact) VALUES (' . implode(', ', [
                    $this->lit($f['competidor']), $this->lit($f['categoria']), $this->lit($f['descripcion_competidor']), $this->lit($f['modo']),
                    $this->lit($f['azzorti_referente']), $this->lit($f['precio_competidor']), $this->lit($f['precio_azzorti']), $this->lit($campana_azzorti),
                    $this->lit($foto_competidor_nombre), $this->lit($foto_azzorti_nombre), $this->lit($campana_form), $this->lit($ahora),
                ]) . ')';
            $this->db->query($sql);
        }
    }

    /** server.py lineas 1095-1101: precio de la otra campaña ya cargada
     * para el mismo producto (respaldo para archivos viejos VS_CAMPANA_ANTERIOR). */
    public function precio_otra_campana($competidor, $descripcion_competidor, $campana_actual) {
        return $this->db->query(
            'SELECT FIRST 1 prec_comp AS precio_competidor, camp AS campana FROM prod_estr '
            . 'WHERE comp = ' . $this->lit($competidor) . ' AND desc_comp = ' . $this->lit($descripcion_competidor)
            . ' AND camp != ' . $this->lit($campana_actual)
            . ' ORDER BY fact DESC'
        )->row();
    }

    /** server.py lineas 1048-1114. */
    public function listar($campana, $umbral, $base_url) {
        $sql = 'SELECT id, comp AS competidor, cate AS categoria, desc_comp AS descripcion_competidor, modo, '
            . 'azzo_refe AS azzorti_referente, prec_comp AS precio_competidor, prec_azzo AS precio_azzorti, '
            . 'foto_comp AS foto_competidor, foto_azzo AS foto_azzorti, camp AS campana, '
            . 'camp_azzo AS campana_azzorti, fact AS actualizado_en '
            . 'FROM prod_estr WHERE 1=1';
        if ($campana) {
            $sql .= ' AND camp = ' . $this->lit($campana);
        }
        $sql .= ' ORDER BY comp, desc_comp';
        $filas = $this->db->query($sql)->result_array();

        $resultado = [];
        foreach ($filas as $r) {
            $r['foto_url'] = !empty($r['foto_competidor'])
                ? $this->archivo_util->url_publica('productos_estrella_fotos/' . $r['foto_competidor'], $base_url)
                : null;
            $r['foto_azzorti_url'] = !empty($r['foto_azzorti'])
                ? $this->archivo_util->url_publica('productos_estrella_fotos/' . $r['foto_azzorti'], $base_url)
                : null;
            $r['campana_anterior'] = $r['campana_azzorti'] ?? null;
            if ($r['precio_competidor'] !== null) {
                $r['precio_competidor'] = round((float) $r['precio_competidor'], 2);
            }
            if ($r['precio_azzorti'] !== null) {
                $r['precio_azzorti'] = round((float) $r['precio_azzorti'], 2);
            }

            if ($r['precio_competidor'] !== null && !empty($r['precio_azzorti'])) {
                $delta = ($r['precio_azzorti'] - $r['precio_competidor']) / $r['precio_azzorti'] * 100;
                $r['delta_pct'] = round($delta, 2);
                $r['precio_campana_anterior'] = null;
                $r['comparacion'] = $r['modo'] === 'HOMOLOGO_FIJO'
                    ? 'vs Azzorti (' . ($r['azzorti_referente'] ?? '') . ')'
                    : 'vs campaña anterior (' . ($r['campana_azzorti'] ?? '') . ', ' . ($r['azzorti_referente'] ?? '') . ')';
            } elseif ($r['modo'] === 'HOMOLOGO_FIJO') {
                $r['delta_pct'] = null;
                $r['precio_campana_anterior'] = null;
                $r['comparacion'] = 'vs Azzorti (' . ($r['azzorti_referente'] ?? '') . ') — falta precio';
            } else {
                $anterior = $this->precio_otra_campana($r['competidor'], $r['descripcion_competidor'], $r['campana']);
                $r['precio_campana_anterior'] = ($anterior && $anterior->precio_competidor !== null)
                    ? round((float) $anterior->precio_competidor, 2) : null;
                $r['campana_anterior'] = $anterior ? $anterior->campana : $r['campana_anterior'];
                if ($anterior && $anterior->precio_competidor && $r['precio_competidor'] !== null) {
                    $delta = ($r['precio_competidor'] - $anterior->precio_competidor) / $anterior->precio_competidor * 100;
                    $r['delta_pct'] = round($delta, 2);
                    $r['comparacion'] = 'vs campaña anterior (Bs ' . $anterior->precio_competidor . ')';
                } else {
                    $r['delta_pct'] = null;
                    $r['comparacion'] = 'vs campaña anterior — sin dato previo o precio actual pendiente';
                }
            }
            $r['alerta'] = ($r['delta_pct'] !== null) && (abs($r['delta_pct']) > $umbral);
            $resultado[] = $r;
        }
        return $resultado;
    }
}
