<?php
/**
 * Arranque del entorno LOCAL de pruebas para el modulo captura_v1.
 * Esto NO es CodeIgniter real - es un reemplazo minimo que imita justo
 * lo que los archivos de azzorti-captura-backend-deploy/php/ usan
 * ($this->load->library/model/config, $this->db->query()->row(), etc.)
 * para poder correr esos mismos archivos (sin tocarlos) contra una base
 * de datos SQLite local en vez de la Informix real de produccion.
 *
 * Por que SQLite y no Informix real: Informix es una base de datos
 * empresarial pesada, dificil de instalar en una compu personal. SQLite
 * viene incluido en PHP y permite probar la LOGICA del codigo (si el
 * archivo compila, si las consultas SQL tienen errores, si los datos
 * salen como se esperaba) sin ese costo. La contra: unos pocos modismos
 * de sintaxis SQL de Informix (ver traducirSql() mas abajo) no existen
 * en SQLite y se traducen al vuelo - si algun dia se escribe una
 * consulta nueva con un modismo de Informix que no esta en esta lista,
 * hay que agregarlo aca (nunca en los archivos reales del modulo).
 *
 * Nunca se sube al servidor - vive fuera de azzorti-captura-backend-deploy/.
 */

// Los controllers/modelos reales le asignan propiedades dinamicas a
// $this (ej. $this->texto_util) sin declararlas antes - normal en el
// estilo de CodeIgniter 3, pero PHP 8.2 lo marca como "deprecated"
// (ruido, no error real). Se apaga solo para las pruebas locales.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

define('BASEPATH', true);
define('APPPATH', __DIR__ . '/application');
define('MODULE_PATH', dirname(__DIR__) . '/azzorti-captura-backend-deploy/php');

// URL publica de ESTE servidor local (para armar foto_url de catalogo/
// productos estrella - ver Config::cargar() mas abajo). Se usa el
// tunel de Cloudflare (ver iniciar_servidor.bat) en vez de la IP de
// LAN: la IP de LAN quedaba bloqueada por el Firewall de Windows (el
// usuario de esta compu no tiene permisos de administrador para
// agregar una regla) - el tunel evita el problema por completo (es
// una conexion SALIENTE, nunca entrante) y de paso deja de depender de
// estar en la misma red WiFi. Esta URL cambia cada vez que se reinicia
// el tunel - si deja de funcionar, avisarle a Claude para que la
// actualice aca y en lib/main.dart/dashboard.html.
define('LOCAL_BASE_URL', 'https://celebrity-biographies-antarctica-washer.trycloudflare.com/temporales/captura_v1/');

// PhpSpreadsheet (para probar Productosestrella::importar_post) -
// instalado localmente con Composer dentro de la carpeta del modulo
// (ver local_test/README.md). Si todavia no se instalo, simplemente no
// se carga - todo lo que no dependa de leer un Excel sigue funcionando
// igual.
$autoload_composer = MODULE_PATH . '/vendor/autoload.php';
if (file_exists($autoload_composer)) {
    require_once $autoload_composer;
}

define('RUTA_ARCHIVOS', __DIR__ . '/archivos/');
define('RUTA_TEMPORALES', __DIR__ . '/temporales/');
if (!is_dir(RUTA_TEMPORALES)) {
    mkdir(RUTA_TEMPORALES, 0775, true);
}

const DB_SQLITE_PATH = __DIR__ . '/data/local.sqlite';

function get_instance() {
    return $GLOBALS['__CI_INSTANCE'];
}

/** Reemplazo minimo de CI_Model - lo unico real que hace falta es el
 * "__get" magico que en CodeIgniter de verdad delega al objeto
 * controller/super-objeto (get_instance()) cuando el modelo usa
 * $this->db, $this->informix_util, etc. sin haberlos cargado el mismo. */
class CI_Model {
    public function __construct() {
        // vacio a proposito: los modelos reales hacen parent::__construct()
        // y PHP exige que el padre tenga ALGUN constructor declarado,
        // aunque no haga nada.
    }
    public function __get($key) {
        return get_instance()->$key;
    }
}

/** Reemplazo minimo de $this->load. Los nombres de clase de este modulo
 * siempre son "Ucfirst" exacto del nombre pasado (ej. 'texto_util' ->
 * Texto_util, 'm_catalogo' -> M_catalogo) - se verifico contra los 3
 * casos reales que usa el modulo (libraries, models y captura_v1/*). */
class Loader {
    public function library($nombre) {
        $ci = get_instance();
        $prop = strtolower(basename($nombre));
        if (isset($ci->$prop)) {
            return;
        }
        $clase = ucfirst($prop);
        if (!class_exists($clase)) {
            require_once MODULE_PATH . "/libraries/{$clase}.php";
        }
        $ci->$prop = new $clase();
    }

    public function model($nombre) {
        $ci = get_instance();
        $partes = explode('/', $nombre);
        $archivo_base = end($partes);
        $prop = strtolower($archivo_base);
        if (isset($ci->$prop)) {
            return;
        }
        $clase = ucfirst($archivo_base);
        if (!class_exists($clase)) {
            require_once MODULE_PATH . "/models/{$nombre}.php";
        }
        // Los modelos de este modulo llaman parent::__construct() (CI_Model,
        // que no tiene constructor propio) y despues $this->load->library(...)
        // - necesitan que get_instance() ya funcione DURANTE su propia
        // construccion para poder registrar esas librerias en el super-objeto.
        $ci->$prop = new $clase();
    }

    public function config($nombre) {
        get_instance()->config->cargar($nombre);
    }

    public function helper($nombre) {
        // Ninguno de los caminos que probamos localmente usa funciones
        // helper de CodeIgniter (base_url(), etc. se pasan como parametro
        // desde el controller, no se llaman como funcion global) - no
        // hace falta implementar nada por ahora.
    }

    public function database() {
        $ci = get_instance();
        if (!isset($ci->db)) {
            $ci->db = new LocalDb(DB_SQLITE_PATH);
        }
    }
}

class Config {
    private $items = [];
    public function cargar($nombre) {
        $config = [];
        require MODULE_PATH . "/config/{$nombre}.php";
        $this->items = array_merge($this->items, $config);
        // El archivo real de config trae la URL de PRODUCCION a proposito
        // (captura_v1_base_url) - no se toca ese archivo (se sube tal
        // cual al servidor real), pero para pruebas locales hace falta
        // que las fotos (productos estrella, catalogo) apunten a ESTE
        // servidor en vez de a servicioweb2bol.azzorti.co, sino el
        // navegador nunca las va a poder cargar.
        if (isset($this->items['captura_v1_base_url']) && defined('LOCAL_BASE_URL')) {
            $this->items['captura_v1_base_url'] = LOCAL_BASE_URL;
        }
    }
    public function item($clave) {
        return $this->items[$clave] ?? null;
    }
}

/** Resultado de una consulta - imita lo minimo de CI_DB_result que usa
 * el modulo: ->row() (primera fila u objeto null) y ->result() (todas). */
class LocalDbResult {
    private $filas;
    public function __construct(array $filas) {
        $this->filas = $filas;
    }
    public function row() {
        return $this->filas[0] ?? null;
    }
    public function result() {
        return $this->filas;
    }
    public function result_array() {
        return array_map(fn($f) => (array) $f, $this->filas);
    }
    public function row_array() {
        $f = $this->filas[0] ?? null;
        return $f === null ? null : (array) $f;
    }
}

/** Reemplazo LOCAL de la conexion a Informix ($this->db en produccion).
 * Ejecuta SQLite de verdad, traduciendo antes los modismos de sintaxis
 * de Informix que usa este modulo (ver Informix_util.php y schema.sql
 * en el codigo real - son los UNICOS 3 modismos que aparecen ahi). */
class LocalDb {
    private \PDO $pdo;

    public function __construct($ruta) {
        $nueva = !file_exists($ruta);
        $this->pdo = new \PDO('sqlite:' . $ruta);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function query($sql) {
        $sql = $this->traducirSql($sql);
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return new LocalDbResult([]);
        }
        $filas = $stmt->fetchAll(\PDO::FETCH_OBJ);
        return new LocalDbResult($filas ?: []);
    }

    public function insert_id() {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Los 3 modismos de Informix que usa este modulo, y su equivalente
     * en SQLite - agregar aca si aparece uno nuevo, NUNCA en los
     * archivos reales del modulo (esos son los que se suben al servidor
     * y tienen que seguir hablando Informix de verdad):
     *  1. "SELECT FIRST N ..." (limitar filas) -> SQLite usa LIMIT al
     *     final de la consulta, no una palabra despues de SELECT.
     *  2. "DBINFO('sqlca.sqlerrd1')" (ultimo id SERIAL insertado) ->
     *     SQLite usa last_insert_rowid().
     *  3. "systables"/"tabname" (catalogo de tablas del sistema) ->
     *     SQLite usa "sqlite_master"/"name".
     *  Y en el schema.sql (creacion de tablas): "SERIAL" (columna
     *  autoincremental) -> en SQLite tiene que decir literalmente
     *  "INTEGER" para que el id se autogenere solo al insertar.
     *  "LVARCHAR(n)" (texto largo) -> "TEXT" (SQLite no lo conoce, pero
     *  tampoco hace falta - es solo para que quede prolijo).
     */
    private function traducirSql($sql) {
        if (stripos($sql, "DBINFO('sqlca.sqlerrd1')") !== false) {
            return 'SELECT last_insert_rowid() AS id';
        }
        $sql = str_ireplace('FROM systables', 'FROM sqlite_master', $sql);
        $sql = preg_replace('/\btabname\b/i', 'name', $sql);
        if (preg_match('/\bFIRST\s+(\d+)\s+/i', $sql, $m)) {
            $sql = preg_replace('/\bFIRST\s+(\d+)\s+/i', '', $sql, 1);
            $sql = rtrim($sql, "; \n\t") . ' LIMIT ' . $m[1];
        }
        $sql = preg_replace('/\bSERIAL\b/i', 'INTEGER', $sql);
        $sql = preg_replace('/\bLVARCHAR\s*\([^)]*\)/i', 'TEXT', $sql);
        return $sql;
    }
}

/**
 * Instancia un controller real del modulo (sin pasar por rutas HTTP) y
 * lo devuelve listo para llamar sus metodos (ej. "indexar_productos_post").
 * $nombre_archivo es el nombre del archivo en controllers/captura_v1/
 * sin ".php" (ej. "Catalogos").
 */
function cargar_controlador($nombre_archivo) {
    require_once MODULE_PATH . "/controllers/captura_v1/{$nombre_archivo}.php";
    return new $nombre_archivo();
}
