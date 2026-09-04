<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Puerto de la tabla captura (real: capt) y sus endpoints en server.py:
 * crear (lineas 1117-1176), listar (1179-1323), confirmar homologacion
 * (1624-1654) y evaluacion (1657-1735). La homologacion "sugerencias"
 * (retail vs venta directa) vive en M_homologacion; este modelo solo
 * arma/lee la fila de captura y sus enriquecimientos de lectura.
 *
 * Los SELECT usan alias (col AS nombre_legible) para que el resto del
 * codigo PHP (y el JSON que consume la app Flutter) siga viendo los
 * nombres originales sin enterarse de que en Informix son abreviados.
 */
class M_captura extends CI_Model {

    const COLUMNAS = 'id, comp AS competidor, cana AS canal, camp AS campana, cate AS categoria, '
        . 'nive_prec AS nivel_precio, dscr AS descripcion, silu AS silueta, tall AS talla, '
        . 'tela1 AS composicion1, tela2 AS composicion2, mang AS manga, colo AS color, '
        . 'deta AS detalle, cara AS caracteristicas, prec AS precio, sku_comp AS sku_competidor, '
        . 'sku_conf AS azzorti_sku_confirmado, foto_arch AS foto_archivo, cata_id AS catalogo_id, '
        . 'fcre AS creada_en';

    public function __construct() {
        parent::__construct();
        $this->load->library('informix_util');
        $this->load->library('archivo_util');
        $this->load->model('captura_v1/m_schema');
        $this->load->model('captura_v1/m_catalogo');
        $this->load->model('captura_v1/m_producto');
    }

    private function ahora_iso() {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function lit($v) {
        return $this->informix_util->literal($v);
    }

    public function por_id($id) {
        return $this->db->query('SELECT ' . self::COLUMNAS . ' FROM capt WHERE id = ' . $this->lit($id))->row();
    }

    /** server.py lineas 1127-1144: evita duplicar si el cliente reintento
     * tras un timeout mientras el servidor si habia guardado la captura. */
    public function duplicado_reciente($c) {
        $hace_5_min = gmdate('Y-m-d\TH:i:s\Z', time() - 300);
        return $this->db->query(
            'SELECT FIRST 1 id FROM capt WHERE comp = ' . $this->lit($c['competidor'])
            . ' AND cana = ' . $this->lit($c['canal']) . ' AND camp = ' . $this->lit($c['campana'])
            . ' AND cate = ' . $this->lit($c['categoria']) . ' AND ' . $this->informix_util->condicion_igual('dscr', $c['descripcion'])
            . ' AND prec = ' . $this->lit($c['precio']) . ' AND fcre > ' . $this->lit($hace_5_min)
            . ' ORDER BY id DESC'
        )->row();
    }

    /** server.py lineas 1169-1174 (UNIQUE competidor+campana+sku_competidor
     * en el diseno original de SQLite). Informix no permite mas de un NULL
     * por combinacion en un UNIQUE, y la mayoria de las capturas no traen
     * sku_competidor - por eso esa restriccion NO se declara en la tabla
     * real (ver schema.sql) y el chequeo de duplicado vive solo aca, en
     * la app, exactamente igual que hacia el UNIQUE en SQLite (solo se
     * activa si vino sku_competidor). */
    public function existe_duplicado($c) {
        if (empty($c['sku_competidor'])) {
            return false;
        }
        return (bool) $this->db->query(
            'SELECT 1 AS x FROM capt WHERE comp = ' . $this->lit($c['competidor'])
            . ' AND camp = ' . $this->lit($c['campana']) . ' AND sku_comp = ' . $this->lit($c['sku_competidor'])
        )->row();
    }

    /**
     * server.py lineas 1153-1176.
     * @return int id de la captura creada
     */
    public function crear($c, $foto_archivo) {
        $sql = 'INSERT INTO capt '
            . '(comp, cana, camp, cate, nive_prec, dscr, silu, tall, '
            . 'tela1, tela2, mang, colo, deta, cara, prec, '
            . 'sku_comp, foto_arch, fcre, cata_id) '
            . 'VALUES (' . implode(', ', [
                $this->lit($c['competidor']), $this->lit($c['canal']), $this->lit($c['campana']), $this->lit($c['categoria']), $this->lit($c['nivel_precio']),
                $this->lit($c['descripcion']), $this->lit($c['silueta']), $this->lit($c['talla']), $this->lit($c['composicion1']), $this->lit($c['composicion2']),
                $this->lit($c['manga']), $this->lit($c['color']), $this->lit($c['detalle']), $this->lit($c['caracteristicas']), $this->lit($c['precio']),
                $this->lit($c['sku_competidor']), $this->lit($foto_archivo), $this->lit($this->ahora_iso()), $this->lit($c['catalogo_id']),
            ]) . ')';
        $this->db->query($sql);
        return $this->informix_util->ultimo_id_serial();
    }

    /** server.py lineas 1711-1716 y 1308-1313 (misma consulta, dos lugares
     * distintos: evaluacion "VS_CAMPANA_ANTERIOR" y listar_capturas). */
    public function captura_anterior($competidor, $descripcion, $campana_actual, $id_actual) {
        return $this->db->query(
            'SELECT FIRST 1 prec AS precio, camp AS campana FROM capt WHERE comp = ' . $this->lit($competidor)
            . ' AND ' . $this->informix_util->condicion_igual('dscr', $descripcion)
            . ' AND camp != ' . $this->lit($campana_actual) . ' AND id != ' . $this->lit($id_actual)
            . ' ORDER BY fcre DESC'
        )->row();
    }

    /** server.py lineas 1179-1323. */
    public function listar($competidor, $campana, $base_url) {
        $sql = 'SELECT ' . self::COLUMNAS . ' FROM capt WHERE 1=1';
        if ($competidor) {
            $sql .= ' AND comp = ' . $this->lit($competidor);
        }
        if ($campana) {
            $sql .= ' AND camp = ' . $this->lit($campana);
        }
        $sql .= ' ORDER BY id DESC';

        $umbral = $this->m_schema->umbral_alerta_actual();
        $filas = $this->db->query($sql)->result_array();
        $resultado = [];
        foreach ($filas as $d) {
            $d['foto_url'] = !empty($d['foto_archivo'])
                ? $this->archivo_util->url_publica('capturas_fotos/' . $d['foto_archivo'], $base_url)
                : null;

            if (!empty($d['catalogo_id'])) {
                $catalogo = $this->m_catalogo->obtener($d['catalogo_id']);
                if ($catalogo) {
                    $d['catalogo'] = [
                        'id' => $catalogo->id,
                        'competidor' => $catalogo->competidor,
                        'campana' => $catalogo->campana,
                        'archivo_url' => $this->archivo_util->url_publica('catalogos_competidor/' . $catalogo->archivo, $base_url),
                    ];
                }
            }

            if (!empty($d['azzorti_sku_confirmado']) && $d['canal'] === 'Venta Directa') {
                $prod = $this->m_catalogo->producto_con_precio($d['azzorti_sku_confirmado']);
                if ($prod) {
                    $delta = ($prod->precio - $d['precio']) / $prod->precio * 100;
                    $catalogo_azz = $this->m_catalogo->catalogo_azzorti_mas_reciente();
                    $nombre_foto = $catalogo_azz ? "{$catalogo_azz->id}_{$prod->pagina}_{$prod->sku}.png" : null;
                    $d['homologacion'] = [
                        'azzorti_sku' => $prod->sku,
                        'azzorti_descripcion' => ($prod->texto_cercano ? mb_substr($prod->texto_cercano, 0, 80) : '') ?: $prod->sku,
                        'pagina_catalogo' => (int) $prod->pagina,
                        'precio_azzorti' => (float) $prod->precio,
                        'delta_pct' => round($delta, 1),
                        'alerta_generica' => abs($delta) > $umbral,
                        'foto_url' => $nombre_foto
                            ? $this->archivo_util->url_publica('catalogo_paginas/' . $nombre_foto, $base_url)
                            : null,
                    ];
                }
            } elseif (!empty($d['azzorti_sku_confirmado'])) {
                $azzorti = $this->m_producto->por_sku($d['azzorti_sku_confirmado']);
                if ($azzorti) {
                    $delta = ($azzorti->precio - $d['precio']) / $azzorti->precio * 100;
                    $d['homologacion'] = [
                        'azzorti_sku' => $azzorti->sku,
                        'azzorti_descripcion' => $azzorti->descripcion,
                        'pagina_catalogo' => $azzorti->pagina_catalogo,
                        'precio_azzorti' => (float) $azzorti->precio,
                        'delta_pct' => round($delta, 1),
                        'alerta_generica' => abs($delta) > $umbral,
                        'foto_url' => $azzorti->foto_archivo
                            ? $this->archivo_util->url_publica($azzorti->foto_archivo, $base_url)
                            : null,
                    ];
                } else {
                    $prod = $this->m_catalogo->producto_con_precio($d['azzorti_sku_confirmado']);
                    if ($prod) {
                        $delta = ($prod->precio - $d['precio']) / $prod->precio * 100;
                        $catalogo_azz = $this->m_catalogo->catalogo_azzorti_mas_reciente();
                        $nombre_foto = $catalogo_azz ? "{$catalogo_azz->id}_{$prod->pagina}_{$prod->sku}.png" : null;
                        $d['homologacion'] = [
                            'azzorti_sku' => $prod->sku,
                            'azzorti_descripcion' => ($prod->texto_cercano ? mb_substr($prod->texto_cercano, 0, 80) : '') ?: $prod->sku,
                            'pagina_catalogo' => (int) $prod->pagina,
                            'precio_azzorti' => (float) $prod->precio,
                            'delta_pct' => round($delta, 1),
                            'alerta_generica' => abs($delta) > $umbral,
                            'foto_url' => $nombre_foto
                                ? $this->archivo_util->url_publica('catalogo_paginas/' . $nombre_foto, $base_url)
                                : null,
                        ];
                    }
                }
            } elseif (!empty($d['descripcion'])) {
                $anterior = $this->captura_anterior($d['competidor'], $d['descripcion'], $d['campana'], $d['id']);
                if ($anterior) {
                    $delta = ($d['precio'] - $anterior->precio) / $anterior->precio * 100;
                    $d['vs_campana_anterior'] = [
                        'campana_anterior' => $anterior->campana,
                        'precio_anterior' => (float) $anterior->precio,
                        'delta_pct' => round($delta, 1),
                        'alerta_generica' => abs($delta) > $umbral,
                    ];
                }
            }
            $resultado[] = $d;
        }
        return $resultado;
    }

    /** server.py lineas 1624-1654. Devuelve true si el SKU es valido para
     * el canal de la captura, false si no existe en el catalogo correspondiente. */
    public function sku_valido_para_canal($captura, $azzorti_sku) {
        if ($captura->canal === 'Venta Directa') {
            return $this->m_catalogo->existe_producto_codigo($azzorti_sku);
        }
        $sku_lit = $this->lit($azzorti_sku);
        return (bool) $this->db->query(
            "SELECT 1 AS x FROM azzo_prod WHERE sku = {$sku_lit} "
            . "UNION SELECT 1 AS x FROM cata_prod WHERE prod_codi = {$sku_lit}"
        )->row();
    }

    public function confirmar_homologacion($id, $azzorti_sku) {
        $this->db->query('UPDATE capt SET sku_conf = ' . $this->lit($azzorti_sku) . ' WHERE id = ' . $this->lit($id));
    }

    /**
     * server.py lineas 1657-1735. Devuelve un array con la evaluacion, o
     * ['error' => mensaje] si el SKU confirmado ya no existe (404 en el
     * controller).
     */
    public function evaluar($id) {
        $captura = $this->por_id($id);
        $umbral = $this->m_schema->umbral_alerta_actual();
        $precio_competencia = (float) $captura->precio;

        if (!empty($captura->azzorti_sku_confirmado)) {
            if ($captura->canal === 'Venta Directa') {
                $azzorti = $this->m_catalogo->producto_con_precio($captura->azzorti_sku_confirmado);
                if (!$azzorti) {
                    return ['error' => 'El código Azzorti confirmado ya no existe en el catálogo indexado, o no '
                        . 'se le pudo leer un precio por OCR.'];
                }
            } else {
                $azzorti = $this->m_producto->por_sku($captura->azzorti_sku_confirmado);
                if (!$azzorti) {
                    $prod = $this->m_catalogo->producto_con_precio($captura->azzorti_sku_confirmado);
                    if (!$prod) {
                        return ['error' => 'El SKU Azzorti confirmado ya no existe en el catálogo.'];
                    }
                    $azzorti = $prod;
                }
            }
            $delta_pct = ($azzorti->precio - $precio_competencia) / $azzorti->precio * 100;
            return [
                'captura_id' => $id,
                'modo' => 'HOMOLOGO',
                'azzorti_sku' => $azzorti->sku,
                'precio_azzorti' => (float) $azzorti->precio,
                'precio_competencia' => $precio_competencia,
                'delta_pct' => round($delta_pct, 1),
                'umbral_pct' => $umbral,
                'alerta' => abs($delta_pct) > $umbral,
            ];
        }

        $anterior = $this->captura_anterior($captura->competidor, $captura->descripcion, $captura->campana, $id);
        if (!$anterior) {
            return [
                'captura_id' => $id,
                'modo' => 'SIN_DATO',
                'precio_competencia' => $precio_competencia,
                'umbral_pct' => $umbral,
                'mensaje' => 'Sin homologación y sin captura previa del mismo producto para comparar.',
            ];
        }
        $delta_pct = ($precio_competencia - $anterior->precio) / $anterior->precio * 100;
        return [
            'captura_id' => $id,
            'modo' => 'VS_CAMPANA_ANTERIOR',
            'campana_anterior' => $anterior->campana,
            'precio_anterior' => (float) $anterior->precio,
            'precio_competencia' => $precio_competencia,
            'delta_pct' => round($delta_pct, 1),
            'umbral_pct' => $umbral,
            'alerta' => abs($delta_pct) > $umbral,
        ];
    }
}
