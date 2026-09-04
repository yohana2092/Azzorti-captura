<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Puerto de GET /historico (server.py lineas 1750-1819). */
class M_historico extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->load->library('texto_util');
        $this->load->model('captura_v1/m_schema');
        $this->load->model('captura_v1/m_catalogo');
        $this->load->model('captura_v1/m_producto');
    }

    public function calcular() {
        $umbral = $this->m_schema->umbral_alerta_actual();
        $deltas = [];

        $capturas = $this->db->query(
            'SELECT cana AS canal, comp AS competidor, camp AS campana, prec AS precio, '
            . 'sku_conf AS azzorti_sku_confirmado FROM capt WHERE sku_conf IS NOT NULL'
        )->result();
        foreach ($capturas as $c) {
            $precio_azzorti = null;
            if ($c->canal === 'Venta Directa') {
                $prod = $this->m_catalogo->producto_con_precio($c->azzorti_sku_confirmado);
                $precio_azzorti = $prod ? (float) $prod->precio : null;
            } else {
                $azz = $this->m_producto->por_sku($c->azzorti_sku_confirmado);
                if ($azz) {
                    $precio_azzorti = (float) $azz->precio;
                } else {
                    $prod = $this->m_catalogo->producto_con_precio($c->azzorti_sku_confirmado);
                    $precio_azzorti = $prod ? (float) $prod->precio : null;
                }
            }
            if ($precio_azzorti && $c->precio !== null) {
                $delta = ($precio_azzorti - $c->precio) / $precio_azzorti * 100;
                $deltas[] = [
                    'canal' => $c->canal !== 'Venta Directa' ? 'Retail' : 'VentaDirecta',
                    'competidor' => $c->competidor,
                    'campana' => $c->campana,
                    'delta_pct' => $delta,
                    'alerta' => abs($delta) > $umbral,
                ];
            }
        }

        $estrella = $this->db->query(
            "SELECT comp AS competidor, camp AS campana, prec_azzo AS precio_azzorti, "
            . "prec_comp AS precio_competidor FROM prod_estr WHERE modo = 'HOMOLOGO_FIJO' "
            . 'AND prec_azzo IS NOT NULL AND prec_comp IS NOT NULL'
        )->result();
        foreach ($estrella as $p) {
            $delta = ($p->precio_azzorti - $p->precio_competidor) / $p->precio_azzorti * 100;
            $deltas[] = [
                'canal' => 'VentaDirecta',
                'competidor' => $p->competidor,
                'campana' => $p->campana,
                'delta_pct' => $delta,
                'alerta' => abs($delta) > $umbral,
            ];
        }

        $grupos = [];
        $orden = [];
        foreach ($deltas as $d) {
            $key = $d['canal'] . '|' . $d['competidor'] . '|' . $d['campana'];
            if (!isset($grupos[$key])) {
                $grupos[$key] = [];
                $orden[] = $key;
            }
            $grupos[$key][] = $d;
        }

        $filas = [];
        foreach ($orden as $key) {
            $items = $grupos[$key];
            [$canal, $competidor, $campana] = explode('|', $key, 3);
            $alertas = array_filter($items, fn($i) => $i['alerta']);
            $suma = array_sum(array_column($items, 'delta_pct'));
            $filas[] = [
                'canal' => $canal,
                'competidor' => $competidor,
                'campana' => $campana,
                'anio' => $this->texto_util->anio_de_campana($campana),
                'delta_pct_promedio' => round($suma / count($items), 1),
                'cantidad_productos' => count($items),
                'cantidad_alertas' => count($alertas),
            ];
        }
        return $filas;
    }
}
