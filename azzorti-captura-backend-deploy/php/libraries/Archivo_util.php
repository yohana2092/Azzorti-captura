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
