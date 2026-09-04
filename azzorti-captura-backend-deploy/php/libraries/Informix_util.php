<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Helpers para hablarle a Informix (conexion real de produccion, ver
 * application/config/database.php -> $db['default'], dbdriver 'odbc')
 * desde los modelos de captura_v1.
 *
 * IMPORTANTE: no se usa el mecanismo de bind de CodeIgniter
 * ($this->db->query($sql, $params)) para INSERT/UPDATE. Se observo que
 * el driver odbc de este servidor ejecuta la MISMA consulta por dos
 * caminos internos distintos (odbc_execute/SQLExecute vs
 * odbc_exec/SQLExecDirect) segun la forma exacta del SQL, de manera
 * inconsistente y sin poder inspeccionar el driver real. Para evitar esa
 * ambiguedad, cada valor se escapa (ver literal(), mas abajo) y se arma
 * el SQL ya completo, sin "?", antes de ejecutar - asi siempre se
 * ejecuta por el mismo camino.
 *
 * literal() NO usa $this->db->escape(): el driver odbc de este servidor
 * no lo implementa ("Unsupported feature of the database platform you
 * are using"), a diferencia de otros drivers de CodeIgniter. En su lugar
 * se escapa a mano duplicando comillas simples (') - el escape estandar
 * de SQL, que Informix soporta nativamente - en vez de copiar el patron
 * del backend real (M_inscripcion.php y compania interpolan variables
 * PHP sin escapar nada en absoluto, ver API_REFERENCE.md "SQL injection
 * conocida"). No hace falta tocar la base de datos para esto.
 *
 * Se carga con $this->load->library('informix_util') y se usa como
 * $this->informix_util->metodo(...).
 */
class Informix_util {

    /** Valor listo para insertar directo en el texto del SQL: 'texto',
     * 123, 12.5 o NULL, ya escapado de forma segura (comillas simples
     * duplicadas en strings). */
    public function literal($valor) {
        if ($valor === null) {
            return 'NULL';
        }
        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }
        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }
        $escapado = str_replace("'", "''", (string) $valor);
        return "'{$escapado}'";
    }

    /**
     * true si existe al menos una fila que matchee el WHERE dado.
     * $where_sql ya debe venir armado con literales (ver literal()/
     * condicion_igual()), sin "?". Va SIN la palabra "WHERE".
     */
    public function existe_fila($tabla, $where_sql) {
        $ci = &get_instance();
        $ci->load->database();
        $fila = $ci->db->query("SELECT FIRST 1 1 AS x FROM {$tabla} WHERE {$where_sql}")->row();
        return $fila !== null;
    }

    /**
     * Condicion de igualdad null-safe para un WHERE: "col IS NULL" si el
     * valor es null ("= NULL" nunca es verdadero en SQL estandar), o
     * "col = <literal escapado>" si no.
     */
    public function condicion_igual($columna, $valor) {
        if ($valor === null) {
            return "{$columna} IS NULL";
        }
        return "{$columna} = " . $this->literal($valor);
    }

    /**
     * Ejecuta $update_sql si la fila ya existe (segun $where_sql), o
     * $insert_sql si no. Ambos deben venir con literales ya embebidos
     * (incluyendo el WHERE del UPDATE).
     */
    public function upsert($tabla, $where_sql, $update_sql, $insert_sql) {
        $ci = &get_instance();
        $ci->load->database();
        if ($this->existe_fila($tabla, $where_sql)) {
            $ci->db->query($update_sql);
        } else {
            $ci->db->query($insert_sql);
        }
    }

    /**
     * Ultimo valor SERIAL insertado en ESTA conexion (debe llamarse
     * inmediatamente despues del INSERT, antes de cualquier otra
     * sentencia) - idiom real de Informix: DBINFO('sqlca.sqlerrd1').
     */
    public function ultimo_id_serial() {
        $ci = &get_instance();
        $ci->load->database();
        $fila = $ci->db->query(
            "SELECT DBINFO('sqlca.sqlerrd1') AS id FROM systables WHERE tabid = 1"
        )->row();
        return (int) $fila->id;
    }

    /** true si la tabla ya existe (catalogo del sistema systables). */
    public function tabla_existe($tabla) {
        return $this->existe_fila('systables', 'tabname = ' . $this->literal($tabla));
    }
}
