<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Puerto de la extraccion de fotos ancladas a celdas/filas de un .xlsx
 * en server.py (lineas 712-938). Un .xlsx es un ZIP con XML adentro; esta
 * clase abre ese ZIP con ZipArchive y parsea a mano los mismos archivos
 * XML que el Python (no hay libreria PHP que cubra esto, ni PhpSpreadsheet).
 *
 * Dos mecanismos de foto en Excel, replicados ambos:
 *  1. Dibujo flotante ("Insertar imagen" clasico) - xl/drawings/drawingN.xml,
 *     ancla xdr:twoCellAnchor/xdr:oneCellAnchor con xdr:from/xdr:row(/xdr:col).
 *  2. "Imagen en celda" (rich value) - celda con atributo vm="N" que se
 *     resuelve via xl/metadata.xml -> xl/richData/rdrichvalue.xml ->
 *     xl/richData/richValueRel.xml -> xl/media/*.
 *
 * Se carga con $this->load->library('xlsx_image_extractor') y se usa
 * como $this->xlsx_image_extractor->metodo(...).
 */
class Xlsx_image_extractor {

    const NS_XDR = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';
    const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Puerto de _col_letras_a_numero (server.py lineas 717-721). */
    public function col_letras_a_numero($letras) {
        $n = 0;
        foreach (str_split($letras) as $ch) {
            $n = $n * 26 + (ord($ch) - ord('A') + 1);
        }
        return $n;
    }

    private function leer($zip, $path) {
        $contenido = $zip->getFromName($path);
        return $contenido === false ? null : $contenido;
    }

    private function tiene($zip, $path) {
        return $zip->locateName($path) !== false;
    }

    /** Puerto de _ruta_hoja_por_nombre (server.py lineas 724-740). */
    public function ruta_hoja_por_nombre($zip, $nombre_hoja) {
        if (!$this->tiene($zip, 'xl/workbook.xml') || !$this->tiene($zip, 'xl/_rels/workbook.xml.rels')) {
            return null;
        }
        $wb_xml = $this->leer($zip, 'xl/workbook.xml');
        preg_match_all('/<sheet name="([^"]+)"[^>]*r:id="(rId\d+)"/', $wb_xml, $m, PREG_SET_ORDER);
        $rid = null;
        foreach ($m as $par) {
            if ($par[1] === $nombre_hoja) {
                $rid = $par[2];
                break;
            }
        }
        if (!$rid) {
            return null;
        }
        $rels_xml = $this->leer($zip, 'xl/_rels/workbook.xml.rels');
        preg_match_all('/Id="(rId\d+)"[^>]*Target="([^"]+)"/', $rels_xml, $m2, PREG_SET_ORDER);
        foreach ($m2 as $par) {
            if ($par[1] === $rid) {
                return 'xl/' . $par[2];
            }
        }
        return null;
    }

    /** Puerto de _drawing_de_hoja (server.py lineas 743-755). */
    public function drawing_de_hoja($zip, $sheet_path) {
        $partes = explode('/', $sheet_path);
        $archivo = array_pop($partes);
        $dir = implode('/', $partes);
        $rels_path = "{$dir}/_rels/{$archivo}.rels";
        if (!$this->tiene($zip, $rels_path)) {
            return null;
        }
        $rels_xml = $this->leer($zip, $rels_path);
        preg_match_all('/Id="(rId\d+)"[^>]*Target="([^"]+)"/', $rels_xml, $m, PREG_SET_ORDER);
        foreach ($m as $par) {
            $target = $par[2];
            if (strpos($target, 'drawing') !== false && substr($target, -4) === '.xml') {
                return 'xl/' . str_replace('../', '', $target);
            }
        }
        return null;
    }

    private function rutas_drawing($zip, $nombre_hoja) {
        if ($nombre_hoja !== null) {
            $sheet_path = $this->ruta_hoja_por_nombre($zip, $nombre_hoja);
            $drawing = $sheet_path ? $this->drawing_de_hoja($zip, $sheet_path) : null;
            return $drawing ? [$drawing] : [];
        }
        $rutas = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if (preg_match('#^xl/drawings/drawing\d+\.xml$#', $nombre)) {
                $rutas[] = $nombre;
            }
        }
        sort($rutas);
        return $rutas;
    }

    /** rId -> Target de un archivo de rels (Relationships/Relationship). */
    private function mapa_relaciones($zip, $rels_path) {
        $xml = $this->leer($zip, $rels_path);
        $mapa = [];
        if ($xml === null) {
            return $mapa;
        }
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        foreach ($dom->getElementsByTagName('Relationship') as $rel) {
            $mapa[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
        }
        return $mapa;
    }

    private function xpath_drawing($xml) {
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('xdr', self::NS_XDR);
        $xpath->registerNamespace('a', self::NS_A);
        $xpath->registerNamespace('r', self::NS_R);
        return $xpath;
    }

    /**
     * Comun a _extraer_fotos_por_fila y _extraer_fotos_ancladas_por_celda
     * (server.py lineas 758-870): recorre los anchors de los drawings
     * relevantes y devuelve [[fila, col_o_null, bytes], ...] en el orden
     * en que aparecen (el llamador decide si usa fila sola o fila+col).
     */
    private function anchors_con_imagen($contenido, $nombre_hoja) {
        $resultado = [];
        $tmp = $this->a_archivo_temporal($contenido);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            unlink($tmp);
            return $resultado;
        }
        try {
            foreach ($this->rutas_drawing($zip, $nombre_hoja) as $drawing_path) {
                $rels_path = str_replace('drawings/', 'drawings/_rels/', $drawing_path) . '.rels';
                if (!$this->tiene($zip, $rels_path)) {
                    continue;
                }
                $rel_map = $this->mapa_relaciones($zip, $rels_path);
                $drawing_xml = $this->leer($zip, $drawing_path);
                if ($drawing_xml === null) {
                    continue;
                }
                $xpath = $this->xpath_drawing($drawing_xml);
                $anchors = $xpath->query('//xdr:twoCellAnchor | //xdr:oneCellAnchor');
                foreach ($anchors as $anchor) {
                    $from = $xpath->query('xdr:from', $anchor)->item(0);
                    if (!$from) {
                        continue;
                    }
                    $fila_nodo = $xpath->query('xdr:row', $from)->item(0);
                    $col_nodo = $xpath->query('xdr:col', $from)->item(0);
                    if (!$fila_nodo || $fila_nodo->textContent === '') {
                        continue;
                    }
                    $fila_excel = ((int) $fila_nodo->textContent) + 1;
                    $col_excel = ($col_nodo && $col_nodo->textContent !== '') ? ((int) $col_nodo->textContent) + 1 : null;
                    $blip = $xpath->query('.//a:blip', $anchor)->item(0);
                    if (!$blip) {
                        continue;
                    }
                    $embed_id = $blip->getAttributeNS(self::NS_R, 'embed');
                    if (!$embed_id || !isset($rel_map[$embed_id])) {
                        continue;
                    }
                    $media_path = 'xl/' . str_replace('../', '', $rel_map[$embed_id]);
                    if ($this->tiene($zip, $media_path)) {
                        $resultado[] = [$fila_excel, $col_excel, $this->leer($zip, $media_path)];
                    }
                }
            }
        } catch (Exception $e) {
            // best-effort: si algo falla, se importa sin estas fotos (server.py 811-812, 868-869).
        } finally {
            $zip->close();
            unlink($tmp);
        }
        return $resultado;
    }

    /** ZipArchive::open necesita un archivo real (no un stream) - el
     * contenido llega en memoria (bytes del upload), asi que se vuelca a
     * un temporal y se borra apenas se termina de leer. */
    private function a_archivo_temporal($contenido) {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $contenido);
        return $tmp;
    }

    /**
     * Puerto de _extraer_fotos_por_fila (server.py lineas 758-813):
     * {fila_1based: bytes}, se queda con la primera imagen de cada fila,
     * ignora columna.
     */
    public function extraer_fotos_por_fila($contenido, $nombre_hoja = null) {
        $fotos = [];
        foreach ($this->anchors_con_imagen($contenido, $nombre_hoja) as [$fila, $col, $bytes]) {
            if (!isset($fotos[$fila])) {
                $fotos[$fila] = $bytes;
            }
        }
        return $fotos;
    }

    /**
     * Puerto de _extraer_fotos_ancladas_por_celda (server.py lineas
     * 816-870): {"fila_col": bytes}, solo cuenta anchors que SI traen
     * columna en xdr:from.
     */
    public function extraer_fotos_ancladas_por_celda($contenido, $nombre_hoja = null) {
        $fotos = [];
        foreach ($this->anchors_con_imagen($contenido, $nombre_hoja) as [$fila, $col, $bytes]) {
            if ($col === null) {
                continue;
            }
            $clave = "{$fila}_{$col}";
            if (!isset($fotos[$clave])) {
                $fotos[$clave] = $bytes;
            }
        }
        return $fotos;
    }

    /**
     * Puerto de _extraer_fotos_por_celda (server.py lineas 873-938):
     * {"fila_col": bytes} combinando el mecanismo "rich value" (imagen en
     * celda via vm=) con los dibujos flotantes que SI traen columna -
     * sin pisar un hit de rich-value si coincide la celda (setdefault).
     */
    public function extraer_fotos_por_celda($contenido, $nombre_hoja = null) {
        $fotos = [];
        $tmp = $this->a_archivo_temporal($contenido);
        $zip = new ZipArchive();
        if ($zip->open($tmp) === true) {
            try {
                if ($this->tiene($zip, 'xl/metadata.xml') && $this->tiene($zip, 'xl/richData/rdrichvalue.xml')) {
                    $meta_xml = $this->leer($zip, 'xl/metadata.xml');
                    preg_match_all('/<bk>.*?<xlrd:rvb i="(\d+)"\/>.*?<\/bk>/s', $meta_xml, $m_bk);
                    $bk_list = $m_bk[1];

                    $rv_xml = $this->leer($zip, 'xl/richData/rdrichvalue.xml');
                    preg_match_all('/<rv[^>]*><v>(\d+)<\/v>/', $rv_xml, $m_rv);
                    $rv_list = $m_rv[1];

                    $rel_rel_path = 'xl/richData/richValueRel.xml';
                    $rels_path = 'xl/richData/_rels/richValueRel.xml.rels';
                    if ($this->tiene($zip, $rel_rel_path) && $this->tiene($zip, $rels_path)) {
                        preg_match_all('/r:id="(rId\d+)"/', $this->leer($zip, $rel_rel_path), $m_relids);
                        $rel_ids = $m_relids[1];

                        preg_match_all(
                            '/Id="(rId\d+)"[^>]*Target="\.\.\/media\/([^"]+)"/',
                            $this->leer($zip, $rels_path),
                            $m_media, PREG_SET_ORDER
                        );
                        $rid_to_media = [];
                        foreach ($m_media as $par) {
                            $rid_to_media[$par[1]] = $par[2];
                        }

                        if ($nombre_hoja !== null) {
                            $sheet_path = $this->ruta_hoja_por_nombre($zip, $nombre_hoja);
                            $sheet_paths = $sheet_path ? [$sheet_path] : [];
                        } else {
                            $sheet_paths = [];
                            for ($i = 0; $i < $zip->numFiles; $i++) {
                                $nombre = $zip->getNameIndex($i);
                                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nombre)) {
                                    $sheet_paths[] = $nombre;
                                }
                            }
                            sort($sheet_paths);
                        }

                        foreach ($sheet_paths as $sheet_path) {
                            $sheet_xml = $this->leer($zip, $sheet_path);
                            preg_match_all('/<c r="([A-Z]+)(\d+)"[^>]*vm="(\d+)"/', $sheet_xml, $m_celdas, PREG_SET_ORDER);
                            foreach ($m_celdas as $celda) {
                                [$_, $letras, $fila_txt, $vm] = $celda;
                                $bk_idx = ((int) $vm) - 1;
                                if (!isset($bk_list[$bk_idx])) {
                                    continue;
                                }
                                $rvb_i = (int) $bk_list[$bk_idx];
                                if (!isset($rv_list[$rvb_i])) {
                                    continue;
                                }
                                $rel_idx = (int) $rv_list[$rvb_i];
                                if (!isset($rel_ids[$rel_idx])) {
                                    continue;
                                }
                                $rid = $rel_ids[$rel_idx];
                                $media = $rid_to_media[$rid] ?? null;
                                if (!$media) {
                                    continue;
                                }
                                $media_path = 'xl/media/' . $media;
                                if ($this->tiene($zip, $media_path)) {
                                    $clave = ((int) $fila_txt) . '_' . $this->col_letras_a_numero($letras);
                                    $fotos[$clave] = $this->leer($zip, $media_path);
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // best-effort (server.py 932-933).
            } finally {
                $zip->close();
            }
        }
        unlink($tmp);

        foreach ($this->extraer_fotos_ancladas_por_celda($contenido, $nombre_hoja) as $clave => $bytes) {
            if (!isset($fotos[$clave])) {
                $fotos[$clave] = $bytes;
            }
        }
        return $fotos;
    }
}
