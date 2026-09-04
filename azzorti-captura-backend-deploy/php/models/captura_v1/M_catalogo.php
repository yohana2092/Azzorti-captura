<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Puerto de las tablas catalogo_competidor (real: cata_comp),
 * catalogo_oferta (real: cata_ofer) y catalogo_producto (real: cata_prod)
 * de server.py (esquema lineas 156-243; endpoints /catalogos*, lineas
 * 542-588 y 2336-2495).
 *
 * Los SELECT usan alias (col AS nombre_legible) para que el resto del
 * codigo PHP (y el JSON que consume la app Flutter) siga viendo los
 * nombres originales (competidor, archivo, producto_codigo, etc.) sin
 * enterarse de que en Informix los nombres de columna son abreviados.
 *
 * Todas las consultas se arman como un solo string con los valores ya
 * escapados (ver Informix_util::literal()) - no se usa el mecanismo de
 * bind de CodeIgniter ($this->db->query($sql, $params)), igual
 * convencion que el resto del backend real (ver M_inscripcion.php:
 * arma el SQL completo con los valores interpolados y llama
 * $this->db->query($query) con un solo argumento).
 */
class M_catalogo extends CI_Model {

    const COLUMNAS_CATALOGO = 'id, comp AS competidor, camp AS campana, arch AS archivo, fsub AS subido_en';
    const COLUMNAS_PRODUCTO = 'id, cata_id AS catalogo_id, pagi AS pagina, prod_codi AS producto_codigo, '
        . 'txt_cerc AS texto_cercano, prec AS precio, secc AS seccion, fcre AS creado_en';

    public function __construct() {
        parent::__construct();
        $this->load->library('informix_util');
    }

    private function ahora_iso() {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function lit($v) {
        return $this->informix_util->literal($v);
    }

    // ---------- cata_comp (catalogo_competidor) ----------

    /** server.py lineas 563-568. */
    public function crear($competidor, $campana, $archivo_nombre) {
        $sql = 'INSERT INTO cata_comp (comp, camp, arch, fsub) VALUES ('
            . implode(', ', [$this->lit($competidor), $this->lit($campana), $this->lit($archivo_nombre), $this->lit($this->ahora_iso())])
            . ')';
        $this->db->query($sql);
        return $this->informix_util->ultimo_id_serial();
    }

    /** server.py lineas 572-588. */
    public function listar($competidor = null, $campana = null) {
        $sql = 'SELECT ' . self::COLUMNAS_CATALOGO . ' FROM cata_comp WHERE 1=1';
        if ($competidor) {
            $sql .= ' AND comp = ' . $this->lit($competidor);
        }
        if ($campana) {
            $sql .= ' AND camp = ' . $this->lit($campana);
        }
        $sql .= ' ORDER BY id DESC';
        return $this->db->query($sql)->result();
    }

    public function obtener($id) {
        return $this->db->query('SELECT ' . self::COLUMNAS_CATALOGO . ' FROM cata_comp WHERE id = ' . $this->lit($id))->row();
    }

    /**
     * Borra el catalogo en si. El llamador (Catalogos::eliminar_post) es
     * responsable de borrar antes las filas hijas (cata_prod, cata_ofer
     * via eliminar_productos_de()/eliminar_ofertas_de() sin rango) para
     * no chocar con la llave foranea, y de limpiar los archivos fisicos
     * (PDF + recortes) del disco.
     */
    public function eliminar($id) {
        $this->db->query('DELETE FROM cata_comp WHERE id = ' . $this->lit($id));
    }

    /**
     * server.py lineas 1448-1457 y 1563-1565, 1235-1237, 1283-1286: el
     * catalogo de Azzorti (competidor='azzorti', sin distinguir mayusculas
     * ni espacios) mas reciente, opcionalmente de una campana especifica.
     *
     * La comparacion de campana es TOLERANTE, no exacta: en la practica
     * la misma campana se escribe distinto segun quien la carga
     * ("C-10", "C10", "C10 2026") - se compara solo el NUMERO de
     * campana, ignorando guiones/espacios, y completando el año con
     * $fecha_referencia (la fecha del registro que se esta buscando,
     * ej. creada_en de la captura) cuando el texto no trae año propio.
     * Informix no tiene funciones de regex utilizables aca, asi que el
     * filtrado tolerante se hace en PHP sobre la lista de catalogos de
     * Azzorti (siempre chica) en vez de en el SQL.
     */
    public function catalogo_azzorti_mas_reciente($campana = null, $fecha_referencia = null) {
        if ($campana !== null) {
            $clave_buscada = $this->normalizar_campana($campana, $fecha_referencia);
            if ($clave_buscada !== null) {
                $candidatos = $this->db->query(
                    "SELECT " . self::COLUMNAS_CATALOGO . " FROM cata_comp WHERE LOWER(TRIM(comp)) = 'azzorti' ORDER BY id DESC"
                )->result();
                foreach ($candidatos as $c) {
                    if ($this->normalizar_campana($c->campana, $c->subido_en) === $clave_buscada) {
                        return $c;
                    }
                }
            }
        }
        return $this->db->query(
            "SELECT FIRST 1 " . self::COLUMNAS_CATALOGO . " FROM cata_comp WHERE LOWER(TRIM(comp)) = 'azzorti' ORDER BY id DESC"
        )->row();
    }

    /**
     * "C-10", "C10", "C10 2026" -> "10-2026" (numero de campana + año).
     * $fecha_referencia (ISO, ej. "2026-08-31T...") solo se usa si el
     * texto de campana no trae año propio. Devuelve null si no se
     * encuentra ni siquiera el numero de campana (texto irreconocible).
     */
    private function normalizar_campana($texto, $fecha_referencia) {
        if (!$texto || !preg_match('/C\s*-?\s*(\d{1,3})/i', $texto, $m)) {
            return null;
        }
        $numero = (int) $m[1];
        if (preg_match('/(20\d{2})/', $texto, $m_anio)) {
            $anio = $m_anio[1];
        } elseif ($fecha_referencia) {
            $anio = substr($fecha_referencia, 0, 4);
        } else {
            $anio = null;
        }
        return $numero . '-' . $anio;
    }

    // ---------- cata_ofer (catalogo_oferta, cache de OCR, ver detectar-ofertas) ----------

    public function eliminar_ofertas_de($catalogo_id) {
        $this->db->query('DELETE FROM cata_ofer WHERE cata_id = ' . $this->lit($catalogo_id));
    }

    /** server.py lineas 2391-2397. */
    public function upsert_oferta($catalogo_id, $pagina, $producto_codigo, $oferta_detectada, $score, $texto_ocr) {
        $score = round($score, 2);
        $texto_ocr = mb_substr((string) $texto_ocr, 0, 2000);
        $ahora = $this->ahora_iso();

        $where_sql = 'cata_id = ' . $this->lit($catalogo_id)
            . ' AND pagi = ' . $this->lit($pagina)
            . ' AND ofer_dete = ' . $this->lit($oferta_detectada)
            . ' AND ' . $this->informix_util->condicion_igual('prod_codi', $producto_codigo);

        if ($this->informix_util->existe_fila('cata_ofer', $where_sql)) {
            $sql = 'UPDATE cata_ofer SET scor = ' . $this->lit($score) . ', txt_ocr = ' . $this->lit($texto_ocr)
                . ', fcre = ' . $this->lit($ahora) . ' WHERE ' . $where_sql;
            $this->db->query($sql);
        } else {
            $sql = 'INSERT INTO cata_ofer '
                . '(cata_id, pagi, prod_codi, ofer_dete, scor, txt_ocr, fcre) '
                . 'VALUES (' . implode(', ', [
                    $this->lit($catalogo_id), $this->lit($pagina), $this->lit($producto_codigo), $this->lit($oferta_detectada),
                    $this->lit($score), $this->lit($texto_ocr), $this->lit($ahora),
                ]) . ')';
            $this->db->query($sql);
        }
    }

    /** server.py lineas 2409-2417. */
    public function listar_ofertas_de($catalogo_id) {
        return $this->db->query(
            'SELECT pagi AS pagina, prod_codi AS producto_codigo, ofer_dete AS oferta_detectada, scor AS score '
            . 'FROM cata_ofer WHERE cata_id = ' . $this->lit($catalogo_id) . ' ORDER BY pagi'
        )->result();
    }

    // ---------- cata_prod (catalogo_producto, cache de OCR, ver indexar-productos) ----------

    /**
     * $pagina_desde/$pagina_hasta (1-based, inclusive) acotan el borrado a
     * solo esas paginas - usado por indexar-productos por tandas, para no
     * pisar el trabajo ya guardado de otras tandas del mismo catalogo al
     * reprocesar un rango puntual. Sin rango, borra todo (comportamiento
     * de siempre, usado cuando se pide el catalogo completo en un solo
     * request).
     */
    public function eliminar_productos_de($catalogo_id, $pagina_desde = null, $pagina_hasta = null) {
        $sql = 'DELETE FROM cata_prod WHERE cata_id = ' . $this->lit($catalogo_id);
        if ($pagina_desde !== null && $pagina_hasta !== null) {
            $sql .= ' AND pagi BETWEEN ' . $this->lit($pagina_desde) . ' AND ' . $this->lit($pagina_hasta);
        }
        $this->db->query($sql);
    }

    /** server.py lineas 2468-2476. */
    public function upsert_producto($catalogo_id, $pagina, $producto_codigo, $texto_cercano, $precio, $seccion) {
        $ahora = $this->ahora_iso();
        $where_sql = 'cata_id = ' . $this->lit($catalogo_id) . ' AND pagi = ' . $this->lit($pagina)
            . ' AND prod_codi = ' . $this->lit($producto_codigo);

        if ($this->informix_util->existe_fila('cata_prod', $where_sql)) {
            $sql = 'UPDATE cata_prod SET txt_cerc = ' . $this->lit($texto_cercano) . ', prec = ' . $this->lit($precio)
                . ', secc = ' . $this->lit($seccion) . ', fcre = ' . $this->lit($ahora)
                . ' WHERE ' . $where_sql;
            $this->db->query($sql);
        } else {
            $sql = 'INSERT INTO cata_prod '
                . '(cata_id, pagi, prod_codi, txt_cerc, prec, secc, fcre) '
                . 'VALUES (' . implode(', ', [
                    $this->lit($catalogo_id), $this->lit($pagina), $this->lit($producto_codigo),
                    $this->lit($texto_cercano), $this->lit($precio), $this->lit($seccion), $this->lit($ahora),
                ]) . ')';
            $this->db->query($sql);
        }
    }

    /** server.py lineas 2485-2495. */
    public function resumen_productos($catalogo_id) {
        $id_lit = $this->lit($catalogo_id);
        $total = (int) $this->db->query("SELECT COUNT(*) AS n FROM cata_prod WHERE cata_id = {$id_lit}")->row()->n;
        $con_precio = (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM cata_prod WHERE cata_id = {$id_lit} AND prec IS NOT NULL"
        )->row()->n;
        return ['total_productos' => $total, 'con_precio' => $con_precio];
    }

    /** server.py lineas 1464-1466: todos los productos indexados de un catalogo. */
    public function productos_de($catalogo_id) {
        return $this->db->query(
            'SELECT ' . self::COLUMNAS_PRODUCTO . ' FROM cata_prod WHERE cata_id = ' . $this->lit($catalogo_id)
        )->result();
    }

    /** server.py lineas 1674-1677, 1767-1771, 1780-1785, 1227-1231, 1276-1280:
     * precio mas reciente con dato de un producto_codigo ya indexado. */
    public function producto_con_precio($producto_codigo) {
        return $this->db->query(
            'SELECT FIRST 1 prod_codi AS sku, prec AS precio, pagi AS pagina, txt_cerc AS texto_cercano FROM cata_prod '
            . 'WHERE prod_codi = ' . $this->lit($producto_codigo) . ' AND prec IS NOT NULL ORDER BY id DESC'
        )->row();
    }

    /** server.py linea 1635: existencia simple (Venta Directa, confirmar homologacion). */
    public function existe_producto_codigo($producto_codigo) {
        return (bool) $this->db->query(
            'SELECT FIRST 1 1 AS x FROM cata_prod WHERE prod_codi = ' . $this->lit($producto_codigo)
        )->row();
    }

    /**
     * server.py lineas 1427-1432: candidatos "moda" para homologacion
     * Retail (seccion Moda o sin seccion identificada) DEL catalogo de
     * Azzorti dado por $catalogo_id.
     *
     * Antes esto buscaba en TODOS los catalogos que alguna vez se
     * subieron con competidor "azzorti" (via JOIN), sin importar cual
     * era el vigente - si se resubia un catalogo de prueba varias veces,
     * o se subia uno nuevo para otra campana, los viejos seguian
     * apareciendo mezclados (duplicados) en las sugerencias. Ahora el
     * llamador resuelve primero cual es el catalogo de Azzorti vigente
     * (M_catalogo::catalogo_azzorti_mas_reciente()) y lo pasa aca -
     * mismo patron que ya usa sugerir_venta_directa.
     */
    public function candidatos_moda_azzorti($catalogo_id) {
        return $this->db->query(
            "SELECT id, cata_id AS catalogo_id, pagi AS pagina, prod_codi AS producto_codigo, "
            . "txt_cerc AS texto_cercano, prec AS precio, secc AS seccion, fcre AS creado_en "
            . "FROM cata_prod "
            . "WHERE cata_id = " . $this->lit($catalogo_id) . " "
            . "AND (secc IS NULL OR UPPER(secc) LIKE '%MODA%')"
        )->result();
    }
}
