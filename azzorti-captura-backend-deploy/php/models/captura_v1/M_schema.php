<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Crea las 9 tablas de captura_v1 en Informix (conexion real de
 * produccion, ver application/config/database.php -> $db['default']) si
 * todavia no existen. Informix no tiene "CREATE TABLE IF NOT EXISTS" -
 * se chequea primero contra el catalogo del sistema (systables) antes de
 * crear cada tabla.
 *
 * No siembra datos de politica_precio/azzorti_producto: esos eran datos
 * de MUESTRA del prototipo Python original (demo), no corresponde
 * insertarlos en la base real - se removieron a proposito (ver git log).
 *
 * Se invoca en el constructor de cada controller del modulo (no hay
 * MY_Controller comun en este backend, ver README).
 */
class M_schema extends CI_Model {

    const UMBRAL_ALERTA_GENERICA_PCT = 10.0;

    public function __construct() {
        parent::__construct();
        $this->load->library('informix_util');
    }

    /** Punto de entrada unico. Idempotente, se puede llamar en cada request. */
    public function asegurar() {
        $this->crear_tablas_faltantes();
        $this->sembrar_config_default();
    }

    /**
     * Lee schema.sql, y por cada CREATE TABLE que traiga, la crea SOLO si
     * todavia no existe (systables) - Informix no soporta
     * "CREATE TABLE IF NOT EXISTS".
     */
    private function crear_tablas_faltantes() {
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            if (!preg_match('/^CREATE TABLE\s+(\w+)/i', $statement, $m)) {
                continue;
            }
            $tabla = $m[1];
            if (!$this->informix_util->tabla_existe($tabla)) {
                $this->db->query($statement);
            }
        }
    }

    /** Unico dato que se siembra: el umbral de alerta default (10%), un
     * valor operativo de configuracion, no dato de negocio/prueba. */
    private function sembrar_config_default() {
        if (!$this->informix_util->existe_fila('capt_conf', "clav = 'umbral_alerta_pct'")) {
            $valo = $this->informix_util->literal((string) self::UMBRAL_ALERTA_GENERICA_PCT);
            $fact = $this->informix_util->literal(gmdate('Y-m-d\TH:i:s\Z'));
            $this->db->query("INSERT INTO capt_conf (clav, valo, fact) VALUES ('umbral_alerta_pct', {$valo}, {$fact})");
        }
    }

    /** Puerto de _umbral_alerta_actual(conn) en server.py (lineas 273-280). */
    public function umbral_alerta_actual() {
        $fila = $this->db->query(
            "SELECT valo AS valor FROM capt_conf WHERE clav = 'umbral_alerta_pct'"
        )->row();
        if ($fila === null || !is_numeric($fila->valor)) {
            return self::UMBRAL_ALERTA_GENERICA_PCT;
        }
        return (float) $fila->valor;
    }
}
