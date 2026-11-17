<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Crea, si hace falta, la carpeta donde se va a escribir un archivo -
 * incluida la carpeta base RUTA_ARCHIVOS . 'captura_v1' (no solo la
 * subcarpeta), ya que mkdir(..., recursive: true) crea toda la cadena de
 * padres faltantes de una sola vez. Sin esto, move_uploaded_file()/
 * file_put_contents() fallan en silencio (devuelven false, sin
 * excepcion) si esa ruta todavia no existe en el disco.
 *
 * Ademas resuelve como se consulta un archivo ya guardado: RUTA_ARCHIVOS
 * es una ruta sensible sin acceso HTTP directo, asi que cada vez que hay
 * que devolver una URL para verlo, primero se copia a RUTA_TEMPORALES
 * (esa si publica) y se arma la URL apuntando ahi - mismo patron que
 * M_panel.php::pedi_cons_lide en el mirror (RUTA_ARCHIVOS/ruta original
 * -> system("cp $origen $destino") con $destino = RUTA_TEMPORALES), solo
 * que con copy() de PHP en vez de un system("cp ...").
 */
class Archivo_util {

    /**
     * Carga PhpSpreadsheet (composer, carpeta vendor/) si nadie la cargo
     * antes. En produccion el CodeIgniter compartido no carga el autoload
     * de composer, asi que sin esto IOFactory no existe y la importacion
     * de Excel muere con un 500 en blanco. Busca vendor/ en APPPATH y al
     * lado de este modulo. Devuelve false si no esta instalada.
     */
    public function cargar_phpspreadsheet() {
        $clase = 'PhpOffice\\PhpSpreadsheet\\IOFactory';
        if (class_exists($clase)) {
            return true;
        }
        foreach ([APPPATH . 'vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
                if (class_exists($clase)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ===== Subida por partes =====
    // El servidor de produccion solo acepta 4 MB por archivo / 8 MB por
    // envio y no se puede subir ese limite (confirmado con el
    // desarrollador, 2026-11-17). El dashboard parte los archivos grandes
    // (catalogos PDF ~30-80 MB, Excel de productos estrella ~6 MB) en
    // pedazos de 3 MB, los manda uno por uno a catalogos/subir_parte, y
    // despues llama al endpoint de siempre con "id_subida" en vez del
    // archivo - archivo_recibido() los vuelve a unir.

    const MAX_PARTES = 80; // 80 x 3 MB = 240 MB, de sobra

    private function carpeta_subida($id_subida) {
        if (!is_string($id_subida) || !preg_match('/^[a-z0-9-]{8,64}$/', $id_subida)) {
            return null;
        }
        return RUTA_ARCHIVOS . 'captura_v1/subidas_tmp/' . $id_subida;
    }

    /** Guarda la parte $indice (0-based) de una subida. Devuelve null si
     * todo bien, o el mensaje de error. */
    public function guardar_parte($id_subida, $indice, $total, $ruta_tmp) {
        $carpeta = $this->carpeta_subida($id_subida);
        $indice = (int) $indice;
        $total = (int) $total;
        if ($carpeta === null || $total < 1 || $total > self::MAX_PARTES || $indice < 0 || $indice >= $total) {
            return 'Datos de la parte invalidos.';
        }
        if (!$ruta_tmp || !is_uploaded_file($ruta_tmp)) {
            return 'No llego el contenido de la parte.';
        }
        $this->limpiar_subidas_viejas();
        $this->asegurar_carpeta($carpeta);
        if (!move_uploaded_file($ruta_tmp, $carpeta . '/' . sprintf('%03d', $indice) . '.part')) {
            return 'No se pudo guardar la parte en el servidor.';
        }
        return null;
    }

    /**
     * El archivo que mando el dashboard, venga entero ($_FILES['archivo'])
     * o por partes ($_POST id_subida + partes + nombre_archivo). Devuelve
     * ['ruta' => ..., 'nombre' => nombre original, 'id_subida' => ...|null]
     * o null si no vino nada / faltan partes.
     */
    public function archivo_recibido() {
        if (!empty($_POST['id_subida'])) {
            $carpeta = $this->carpeta_subida($_POST['id_subida']);
            $partes = (int) ($_POST['partes'] ?? 0);
            if ($carpeta === null || $partes < 1 || $partes > self::MAX_PARTES) {
                return null;
            }
            $completo = $carpeta . '/completo.bin';
            $salida = @fopen($completo, 'wb');
            if (!$salida) {
                return null;
            }
            for ($i = 0; $i < $partes; $i++) {
                $parte = $carpeta . '/' . sprintf('%03d', $i) . '.part';
                if (!is_file($parte)) {
                    fclose($salida);
                    return null; // falta una parte: el dashboard la reintenta
                }
                $entrada = fopen($parte, 'rb');
                stream_copy_to_stream($entrada, $salida);
                fclose($entrada);
            }
            fclose($salida);
            return [
                'ruta' => $completo,
                'nombre' => basename((string) ($_POST['nombre_archivo'] ?? 'archivo')),
                'id_subida' => $_POST['id_subida'],
            ];
        }
        if (!empty($_FILES['archivo']['tmp_name'])) {
            return ['ruta' => $_FILES['archivo']['tmp_name'], 'nombre' => $_FILES['archivo']['name'], 'id_subida' => null];
        }
        return null;
    }

    /** Lleva el archivo recibido a su lugar definitivo. */
    public function mover_recibido(array $archivo, $destino) {
        $ok = $archivo['id_subida'] === null
            ? move_uploaded_file($archivo['ruta'], $destino)
            : rename($archivo['ruta'], $destino);
        $this->limpiar_subida($archivo);
        return $ok;
    }

    /** Borra las partes temporales de una subida (si fue por partes). */
    public function limpiar_subida(array $archivo) {
        if (empty($archivo['id_subida'])) {
            return;
        }
        $carpeta = $this->carpeta_subida($archivo['id_subida']);
        if ($carpeta && is_dir($carpeta)) {
            foreach (glob($carpeta . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($carpeta);
        }
    }

    /** Subidas que quedaron a medias hace mas de un dia (pestaña cerrada,
     * sin señal...) - para no ir llenando el disco del servidor. */
    private function limpiar_subidas_viejas() {
        foreach (glob(RUTA_ARCHIVOS . 'captura_v1/subidas_tmp/*', GLOB_ONLYDIR) ?: [] as $carpeta) {
            if (filemtime($carpeta) < time() - 86400) {
                $this->limpiar_subida(['id_subida' => basename($carpeta)]);
            }
        }
    }

    public function asegurar_carpeta($ruta) {
        if (!is_dir($ruta)) {
            mkdir($ruta, 0775, true);
        }
    }

    /**
     * $ruta_relativa es relativa a "captura_v1/" tanto en RUTA_ARCHIVOS
     * (origen, sensible) como en RUTA_TEMPORALES (destino, publico) - ej.
     * "catalogos_competidor/azzorti_c10-2026.pdf". Devuelve null si el
     * archivo origen no existe (nunca se copia nada inexistente), o la
     * URL publica ($base_url . $ruta_relativa) si la copia salio bien.
     */
    public function url_publica($ruta_relativa, $base_url) {
        $origen = RUTA_ARCHIVOS . 'captura_v1/' . $ruta_relativa;
        if (!is_file($origen)) {
            return null;
        }
        $destino = RUTA_TEMPORALES . 'captura_v1/' . $ruta_relativa;
        $this->asegurar_carpeta(dirname($destino));
        copy($origen, $destino);
        return $base_url . $ruta_relativa;
    }
}
