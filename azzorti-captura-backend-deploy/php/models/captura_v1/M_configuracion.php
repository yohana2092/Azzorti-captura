<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Puerto de los endpoints /configuracion/* de server.py (lineas 460-530).
 * Tabla real: capt_conf (clav, valo, fact) = (clave, valor, actualizado_en). */
class M_configuracion extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->load->library('informix_util');
    }

    private function ahora_iso() {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /** server.py lineas 491-507. */
    public function actualizar_umbral_alerta($umbral_pct) {
        $valo = $this->informix_util->literal((string) $umbral_pct);
        $fact = $this->informix_util->literal($this->ahora_iso());
        $this->informix_util->upsert(
            'capt_conf',
            "clav = 'umbral_alerta_pct'",
            "UPDATE capt_conf SET valo = {$valo}, fact = {$fact} WHERE clav = 'umbral_alerta_pct'",
            "INSERT INTO capt_conf (clav, valo, fact) VALUES ('umbral_alerta_pct', {$valo}, {$fact})"
        );
    }

    /** server.py lineas 510-514: default hardcodeado 6.96 si no hay fila. */
    public function tipo_cambio_actual() {
        $fila = $this->db->query("SELECT valo AS valor FROM capt_conf WHERE clav = 'tipo_cambio'")->row();
        return $fila ? (float) $fila->valor : 6.96;
    }

    /** server.py lineas 517-530. */
    public function actualizar_tipo_cambio($tc) {
        $valo = $this->informix_util->literal((string) $tc);
        $fact = $this->informix_util->literal($this->ahora_iso());
        $this->informix_util->upsert(
            'capt_conf',
            "clav = 'tipo_cambio'",
            "UPDATE capt_conf SET valo = {$valo}, fact = {$fact} WHERE clav = 'tipo_cambio'",
            "INSERT INTO capt_conf (clav, valo, fact) VALUES ('tipo_cambio', {$valo}, {$fact})"
        );
    }
}
