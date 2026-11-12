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
        . 'fcre AS creada_en, gene AS genero';

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

    /**
     * Nombre limpio del producto homologado para el dashboard ("Poleras",
     * "Billetera femenina Loriet") en vez de los primeros 80 caracteres
     * del texto OCR crudo ("Poleras LA QUE ELIJAS POR SOLO Bs. 179.99 4.
     * Ref.R4849 5. Ref.R4851 S | 827643..."). El codigo y la pagina ya
     * se muestran aparte.
     */
    private function nombre_producto($texto_cercano, $sku) {
        $this->load->library('texto_util');
        // Texto de TODA la pagina del producto (en orden de lectura): en
        // paginas con varios productos el titulo impreso queda en el
        // texto de un vecino - con solo el texto propio salia basura
        // ("4. cm" de "Dimensiones: 21 x 4. cm").
        $texto_pagina = $texto_cercano;
        $ref = $this->db->query(
            'SELECT FIRST 1 cata_id, pagi FROM cata_prod WHERE prod_codi = ' . $this->lit($sku) . ' ORDER BY id DESC'
        )->row();
        if ($ref) {
            $filas = $this->db->query(
                'SELECT txt_cerc FROM cata_prod WHERE cata_id = ' . $this->lit($ref->cata_id)
                . ' AND pagi = ' . $this->lit($ref->pagi) . ' ORDER BY id'
            )->result();
            if ($filas) {
                $texto_pagina = implode(' ', array_map(fn($f) => $f->txt_cerc ?? '', $filas));
            }
        }
        $nombre = $this->texto_util->nombre_producto($texto_pagina, $sku);
        if ($nombre) {
            return $nombre;
        }
        $minimo = preg_replace('/\s*-\s*Ref\..*$/u', '', $this->texto_util->descripcion_minima($texto_cercano, $sku));
        return $minimo ?: (string) $sku;
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
            . 'sku_comp, foto_arch, fcre, cata_id, gene) '
            . 'VALUES (' . implode(', ', [
                $this->lit($c['competidor']), $this->lit($c['canal']), $this->lit($c['campana']), $this->lit($c['categoria']), $this->lit($c['nivel_precio']),
                $this->lit($c['descripcion']), $this->lit($c['silueta']), $this->lit($c['talla']), $this->lit($c['composicion1']), $this->lit($c['composicion2']),
                $this->lit($c['manga']), $this->lit($c['color']), $this->lit($c['detalle']), $this->lit($c['caracteristicas']), $this->lit($c['precio']),
                $this->lit($c['sku_competidor']), $this->lit($foto_archivo), $this->lit($this->ahora_iso()), $this->lit($c['catalogo_id']),
                $this->lit($c['genero'] ?? null),
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
                    // foto_url = recorte chico (miniatura), foto_grande_url =
                    // PAGINA COMPLETA para la vista previa al hacer clic -
                    // mismo criterio que ya usa M_homologacion (sugerencias),
                    // que esta pantalla no estaba siguiendo: el recorte chico
                    // no alcanza a mostrar la prenda completa al ampliarlo.
                    $nombre_completa = $catalogo_azz ? "{$catalogo_azz->id}_{$prod->pagina}_completa.png" : null;
                    $d['homologacion'] = [
                        'azzorti_sku' => $prod->sku,
                        'azzorti_descripcion' => $this->nombre_producto($prod->texto_cercano, $prod->sku),
                        'pagina_catalogo' => $this->m_catalogo->pagina_impresa($prod->catalogo_id ?? null, (int) $prod->pagina),
                        'precio_azzorti' => (float) $prod->precio,
                        'delta_pct' => round($delta, 1),
                        'alerta_generica' => abs($delta) > $umbral,
                        'sin_precio' => false,
                        'foto_url' => $nombre_foto
                            ? $this->archivo_util->url_publica('catalogo_paginas/' . $nombre_foto, $base_url)
                            : null,
                        'foto_grande_url' => $nombre_completa
                            ? $this->archivo_util->url_publica('catalogo_paginas/' . $nombre_completa, $base_url)
                            : null,
                    ];
                } else {
                    $d['homologacion'] = $this->homologacion_sin_precio($d['azzorti_sku_confirmado'], $base_url);
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
                        'sin_precio' => false,
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
                        $nombre_completa = $catalogo_azz ? "{$catalogo_azz->id}_{$prod->pagina}_completa.png" : null;
                        $d['homologacion'] = [
                            'azzorti_sku' => $prod->sku,
                            'azzorti_descripcion' => $this->nombre_producto($prod->texto_cercano, $prod->sku),
                            'pagina_catalogo' => $this->m_catalogo->pagina_impresa($prod->catalogo_id ?? null, (int) $prod->pagina),
                            'precio_azzorti' => (float) $prod->precio,
                            'delta_pct' => round($delta, 1),
                            'alerta_generica' => abs($delta) > $umbral,
                            'sin_precio' => false,
                            'foto_url' => $nombre_foto
                                ? $this->archivo_util->url_publica('catalogo_paginas/' . $nombre_foto, $base_url)
                                : null,
                            'foto_grande_url' => $nombre_completa
                                ? $this->archivo_util->url_publica('catalogo_paginas/' . $nombre_completa, $base_url)
                                : null,
                        ];
                    } else {
                        $d['homologacion'] = $this->homologacion_sin_precio($d['azzorti_sku_confirmado'], $base_url);
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

    /**
     * Homologacion ya confirmada con un producto real del catalogo, pero
     * sin precio legible por OCR - para no mostrar "sin homologar aun"
     * cuando en realidad SI se eligio un producto, solo falta el precio.
     * Devuelve null si el codigo ya ni siquiera existe en el catalogo.
     */
    private function homologacion_sin_precio($azzorti_sku, $base_url) {
        $prod = $this->m_catalogo->producto_por_codigo($azzorti_sku);
        if (!$prod) {
            return null;
        }
        return [
            'azzorti_sku' => $prod->sku,
            'azzorti_descripcion' => $this->nombre_producto($prod->texto_cercano, $prod->sku),
            'pagina_catalogo' => $this->m_catalogo->pagina_impresa($prod->catalogo_id ?? null, (int) $prod->pagina),
            'precio_azzorti' => null,
            'delta_pct' => null,
            'alerta_generica' => false,
            'sin_precio' => true,
            'foto_url' => null,
        ];
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
            } else {
                $azzorti = $this->m_producto->por_sku($captura->azzorti_sku_confirmado);
                if (!$azzorti) {
                    $azzorti = $this->m_catalogo->producto_con_precio($captura->azzorti_sku_confirmado);
                }
            }
            if ($azzorti) {
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
            // Se confirmo la homologacion con un producto real, pero no
            // tiene precio legible por OCR (o, si es de la muestra
            // curada, nunca deberia faltar - eso si seria raro). No es
            // lo mismo que "no existe": el producto SI se eligio bien,
            // solo falta el precio para poder comparar.
            $sin_precio = $this->m_catalogo->producto_por_codigo($captura->azzorti_sku_confirmado);
            if ($sin_precio) {
                return [
                    'captura_id' => $id,
                    'modo' => 'HOMOLOGO_SIN_PRECIO',
                    'azzorti_sku' => $sin_precio->sku,
                    'azzorti_descripcion' => $this->nombre_producto($sin_precio->texto_cercano, $sin_precio->sku),
                    'pagina_catalogo' => $this->m_catalogo->pagina_impresa($sin_precio->catalogo_id ?? null, (int) $sin_precio->pagina),
                    'precio_competencia' => $precio_competencia,
                    'mensaje' => 'Se homologó con este producto, pero no se le pudo leer el precio en el catálogo '
                        . '(pág. ' . $this->m_catalogo->pagina_impresa($sin_precio->catalogo_id ?? null, (int) $sin_precio->pagina) . ') - no hay con qué comparar el precio todavía.',
                ];
            }
            return ['error' => 'El SKU Azzorti confirmado ya no existe en el catálogo.'];
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
