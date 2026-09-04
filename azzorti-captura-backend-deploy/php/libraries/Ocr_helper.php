<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Reemplazo de PyMuPDF (fitz) + pytesseract de server.py. PHP no tiene
 * binding nativo a ninguno de los dos, asi que:
 *  - Render de pagina PDF -> imagen: Imagick (requiere delegado Ghostscript
 *    para PDF, ver README).
 *  - OCR: binario "tesseract" por linea de comandos, pidiendo salida hocr
 *    (misma granularidad por palabra que pytesseract.image_to_data con
 *    Output.DICT: cada palabra trae left/top/width/height/text) - no tsv,
 *    ver Ocr_helper::datos_ocr() para el motivo.
 *
 * IMPORTANTE (ver plan de migracion): server.py en detectar-ofertas
 * (POST /catalogos/{id}/detectar-ofertas) solo toma "la imagen embebida
 * mas grande" de la pagina (fitz.get_page_images + max por area) antes de
 * correr OCR, como optimizacion. Imagick no tiene equivalente limpio a
 * eso, asi que aqui se renderiza la pagina COMPLETA (igual que ya hace
 * indexar-productos) para las dos operaciones - el texto de la oferta
 * esta en la pagina igual, es una simplificacion consciente y documentada,
 * no una perdida de funcionalidad.
 */
class Ocr_helper {

    const IDIOMA = 'spa';
    // Subido de 150 a 200 para intentar que el OCR lea mejor texto chico/
    // borroso (ej. digitos de precio truncados en tesseract 3.04.00,
    // confirmado en produccion). Ojo: mas DPI = pagina mas pesada = mas
    // tiempo de render+OCR por pagina - ver PAGINAS_POR_TANDA en
    // dashboard.html, bajado en la misma proporcion para no acercarse al
    // timeout del gateway.
    const DPI = 200;

    /** Numero de paginas de un PDF sin rasterizarlo completo (ping). */
    public function num_paginas($ruta_pdf) {
        $im = new Imagick();
        $im->pingImage($ruta_pdf);
        $n = $im->getNumberImages();
        $im->clear();
        return $n;
    }

    /**
     * Renderiza una pagina (0-based, igual convencion que fitz) a PNG en
     * un archivo temporal. El llamador es responsable de unlink() cuando
     * termine de usarlo.
     * @return array{ruta: string, ancho: int, alto: int}
     */
    public function renderizar_pagina($ruta_pdf, $pagina_0based, $dpi = self::DPI) {
        $im = new Imagick();
        $im->setResolution($dpi, $dpi);
        $im->readImage($ruta_pdf . '[' . $pagina_0based . ']');
        $im->setImageFormat('png');
        $im->setImageBackgroundColor('white');
        $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        // Ghostscript/Imagick en este servidor renderiza a 16-bit por
        // canal por default. Leptonica 1.72 (la que trae el tesseract
        // 3.04.00 instalado) no soporta PNG de 16-bit - lo lee como
        // ruido o directamente no lo lee, y el OCR devuelve vacio sin
        // ningun error visible. Forzar 8-bit es lo que esa version
        // vieja espera.
        $im->setImageDepth(8);
        $ancho = $im->getImageWidth();
        $alto = $im->getImageHeight();
        $tmp = tempnam(sys_get_temp_dir(), 'ocrpag') . '.png';
        $im->writeImage($tmp);
        $im->clear();
        return ['ruta' => $tmp, 'ancho' => $ancho, 'alto' => $alto];
    }

    /**
     * Puerto de pytesseract.image_to_data(img, lang="spa",
     * output_type=Output.DICT): devuelve el mismo shape de columnas
     * paralelas (arrays por indice, uno por palabra) que usa el resto
     * del codigo portado.
     *
     * Usa el formato de salida "hocr" (HTML con coordenadas por
     * palabra), NO "tsv": el config "tsv" no existe en tesseract 3.04.00
     * (version real instalada en el servidor de produccion, muy anterior
     * a "tsv" que se agrego recien en la 3.05) - "read_params_file:
     * Can't open tsv" y devuelve vacio en silencio, sin ningun error
     * visible mas alla de eso. "hocr" da la misma informacion (bbox por
     * palabra) y esta soportado desde tesseract 3.0x.
     */
    public function datos_ocr($ruta_imagen) {
        // Sin -psm: pytesseract.image_to_data() tampoco pasa config, asi
        // que usa el modo por defecto de Tesseract (psm 3, automatico) -
        // se mantiene igual aqui para no cambiar el comportamiento del OCR.
        $comando = 'tesseract ' . escapeshellarg($ruta_imagen) . ' stdout -l '
            . escapeshellarg(self::IDIOMA) . ' hocr 2>' . escapeshellarg($this->null_device());
        $salida = shell_exec($comando);
        $datos = ['text' => [], 'left' => [], 'top' => [], 'width' => [], 'height' => []];
        if ($salida === null) {
            return $datos;
        }
        // <span class='ocrx_word' ... title='bbox X0 Y0 X1 Y1; ...'>TEXTO</span>
        // (TEXTO puede traer tags como <strong> si tesseract detecto negrita).
        preg_match_all(
            '/<span class=[\'"]ocrx_word[\'"][^>]*title=[\'"]bbox\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)[^\'"]*[\'"][^>]*>(.*?)<\/span>/is',
            $salida, $m, PREG_SET_ORDER
        );
        foreach ($m as $palabra) {
            [, $x0, $y0, $x1, $y1, $texto_html] = $palabra;
            $texto = trim(html_entity_decode(strip_tags($texto_html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $datos['left'][] = (int) $x0;
            $datos['top'][] = (int) $y0;
            $datos['width'][] = (int) $x1 - (int) $x0;
            $datos['height'][] = (int) $y1 - (int) $y0;
            $datos['text'][] = $texto;
        }
        return $datos;
    }

    private function null_device() {
        return stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
    }
}
