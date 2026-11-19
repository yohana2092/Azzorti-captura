<?php
defined('BASEPATH') or exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Puerto de producto_estrella: parseo del Excel real de Mercadeo
 * (_parsear_productos_estrella, server.py lineas 615-709), importar
 * (941-1045) y listar con calculo de delta_pct (1048-1114).
 *
 * Requiere PhpSpreadsheet (composer) para leer valores de celda - la
 * extraccion de FOTOS ancladas/en celda es aparte (Xlsx_image_extractor),
 * PhpSpreadsheet no cubre eso.
 */
class M_producto_estrella extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->load->library('texto_util');
        $this->load->library('informix_util');
        $this->load->library('archivo_util');
    }

    private function ahora_iso() {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Puerto de _parsear_productos_estrella (server.py lineas 615-709).
     * @return array de filas con claves: competidor, categoria,
     *   descripcion_competidor, modo, azzorti_referente, precio_competidor,
     *   campana_competidor, precio_azzorti, campana_azzorti, _fila_excel,
     *   _col_foto, _col_foto_azzorti.
     */
    public function parsear($ruta_archivo) {
        if (!$this->archivo_util->cargar_phpspreadsheet()) {
            throw new Exception('falta la libreria PhpSpreadsheet en el servidor (carpeta vendor/).');
        }
        $libro = IOFactory::load($ruta_archivo);
        $filas = [];
        foreach ($libro->getAllSheets() as $ws) {
            $max_row = $ws->getHighestRow();
            $max_col = Coordinate::columnIndexFromString($ws->getHighestColumn());

            $fila_encabezado = null;
            for ($r = 1; $r <= min($max_row, 15) && $fila_encabezado === null; $r++) {
                for ($c = 1; $c <= $max_col; $c++) {
                    $valor = $this->texto_util->texto($ws->getCellByColumnAndRow($c, $r)->getCalculatedValue());
                    if (mb_strpos(mb_strtolower($valor, 'UTF-8'), 'descri') === 0) {
                        $fila_encabezado = $r;
                        break;
                    }
                }
            }
            if ($fila_encabezado === null) {
                continue;
            }

            $col_descripcion = null;
            $col_referente = null;
            for ($c = 1; $c <= $max_col; $c++) {
                $texto = mb_strtolower($this->texto_util->texto($ws->getCellByColumnAndRow($c, $fila_encabezado)->getCalculatedValue()), 'UTF-8');
                if (mb_strpos($texto, 'descri') === 0) {
                    $col_descripcion = $c;
                } elseif ((mb_strpos($texto, 'referente') !== false || mb_strpos($texto, 'azzorti') !== false)
                    && mb_strpos($texto, 'precio') === false) {
                    $col_referente = $c;
                }
            }
            if (!$col_descripcion || !$col_referente) {
                continue;
            }

            $col_competidor = 1;
            $col_categoria = $col_descripcion - 1;
            $col_foto = $col_descripcion + 1;
            $col_precio_competidor = $col_descripcion + 2;
            $col_campana_competidor = $col_descripcion + 3;
            $col_foto_azzorti = $col_referente + 1;
            $col_precio_azzorti = $col_referente + 2;
            $col_campana_azzorti = $col_referente + 3;
            $tiene_bloque_azzorti = $col_campana_azzorti <= $max_col;

            $ultimo_competidor = null;
            for ($r = $fila_encabezado + 1; $r <= $max_row; $r++) {
                $valor_competidor = $this->texto_util->texto($ws->getCellByColumnAndRow($col_competidor, $r)->getCalculatedValue());
                $competidor = $valor_competidor !== '' ? $valor_competidor : $ultimo_competidor;
                if ($valor_competidor !== '') {
                    $ultimo_competidor = $competidor;
                }
                $descripcion = $this->texto_util->texto($ws->getCellByColumnAndRow($col_descripcion, $r)->getCalculatedValue());
                $referente = $this->texto_util->texto($ws->getCellByColumnAndRow($col_referente, $r)->getCalculatedValue());
                if (!$competidor || !$descripcion || !$referente) {
                    continue;
                }

                $referente_lower = mb_strtolower($referente, 'UTF-8');
                $es_vs_anterior = mb_strpos($referente_lower, 'campaña anterior') !== false
                    || mb_strpos($referente_lower, 'campana anterior') !== false;
                $valor_col_azzorti = $tiene_bloque_azzorti
                    ? $this->texto_util->numero($ws->getCellByColumnAndRow($col_precio_azzorti, $r)->getCalculatedValue())
                    : null;

                $campana_azzorti_raw = $tiene_bloque_azzorti
                    ? $this->texto_util->texto($ws->getCellByColumnAndRow($col_campana_azzorti, $r)->getCalculatedValue())
                    : '';
                $campana_azzorti = ($tiene_bloque_azzorti && preg_match(Texto_util::CAMPANA_ESTANDAR_RE, $campana_azzorti_raw))
                    ? $campana_azzorti_raw
                    : null;

                $filas[] = [
                    'competidor' => $competidor,
                    'categoria' => $col_categoria >= 1
                        ? $this->texto_util->texto($ws->getCellByColumnAndRow($col_categoria, $r)->getCalculatedValue())
                        : null,
                    'descripcion_competidor' => $descripcion,
                    'modo' => $es_vs_anterior ? 'VS_CAMPANA_ANTERIOR' : 'HOMOLOGO_FIJO',
                    'azzorti_referente' => $referente,
                    'precio_competidor' => $this->texto_util->numero($ws->getCellByColumnAndRow($col_precio_competidor, $r)->getCalculatedValue()),
                    'campana_competidor' => $this->texto_util->texto($ws->getCellByColumnAndRow($col_campana_competidor, $r)->getCalculatedValue()) ?: null,
                    'precio_azzorti' => $valor_col_azzorti,
                    'campana_azzorti' => $campana_azzorti,
                    '_fila_excel' => $r,
                    '_col_foto' => $col_foto,
                    '_col_foto_azzorti' => $col_foto_azzorti,
                ];
            }
        }
        return $filas;
    }

    private function lit($v) {
        return $this->informix_util->literal($v);
    }

    /** server.py lineas 1005-1031 (UPSERT). Tabla real: capt_prod_estr
     * (renombrada de "prod_estr" - ese nombre choca con una tabla real
     * de manufactura que ya existe en la base compartida, ver schema.sql). */
    public function upsert($f, $foto_competidor_nombre, $foto_azzorti_nombre, $campana_form) {
        $campana_azzorti = $f['campana_azzorti'] ?: ($f['modo'] === 'HOMOLOGO_FIJO' ? $campana_form : null);
        $ahora = $this->ahora_iso();
        $where_sql = 'comp = ' . $this->lit($f['competidor']) . ' AND desc_comp = ' . $this->lit($f['descripcion_competidor'])
            . ' AND camp = ' . $this->lit($campana_form);

        if ($this->informix_util->existe_fila('capt_prod_estr', $where_sql)) {
            // COALESCE(NULL, col) = col: se escribe el literal NULL igual
            // que en cualquier otro campo (ver Informix_util::literal) -
            // el resultado semantico de "no pisar la foto existente si la
            // nueva importacion no trae una" es identico.
            $foto_competidor_sql = $foto_competidor_nombre === null ? 'foto_comp' : 'COALESCE(' . $this->lit($foto_competidor_nombre) . ', foto_comp)';
            $foto_azzorti_sql = $foto_azzorti_nombre === null ? 'foto_azzo' : 'COALESCE(' . $this->lit($foto_azzorti_nombre) . ', foto_azzo)';
            $sql = 'UPDATE capt_prod_estr SET cate = ' . $this->lit($f['categoria']) . ', modo = ' . $this->lit($f['modo'])
                . ', azzo_refe = ' . $this->lit($f['azzorti_referente'])
                . ', prec_comp = ' . $this->lit($f['precio_competidor']) . ', prec_azzo = ' . $this->lit($f['precio_azzorti'])
                . ', camp_azzo = ' . $this->lit($campana_azzorti)
                . ', foto_comp = ' . $foto_competidor_sql . ', foto_azzo = ' . $foto_azzorti_sql
                . ', fact = ' . $this->lit($ahora)
                . ' WHERE ' . $where_sql;
            $this->db->query($sql);
        } else {
            $sql = 'INSERT INTO capt_prod_estr '
                . '(comp, cate, desc_comp, modo, azzo_refe, '
                . 'prec_comp, prec_azzo, camp_azzo, foto_comp, foto_azzo, '
                . 'camp, fact) VALUES (' . implode(', ', [
                    $this->lit($f['competidor']), $this->lit($f['categoria']), $this->lit($f['descripcion_competidor']), $this->lit($f['modo']),
                    $this->lit($f['azzorti_referente']), $this->lit($f['precio_competidor']), $this->lit($f['precio_azzorti']), $this->lit($campana_azzorti),
                    $this->lit($foto_competidor_nombre), $this->lit($foto_azzorti_nombre), $this->lit($campana_form), $this->lit($ahora),
                ]) . ')';
            $this->db->query($sql);
        }
    }

    // ================= Busqueda automatica de precios en catalogos =================
    // Piloto (2026-11-13): en vez de llenar precios a mano en el Excel, se
    // busca cada producto estrella en el catalogo PDF de su competidor (y su
    // referente en el catalogo Azzorti) de la misma campana: primero por
    // nombre (texto OCR de las paginas) y despues la IA mira esas paginas,
    // confirma cual es el producto (comparando con la foto del Excel) y lee
    // el codigo y el precio de venta.

    // Palabras que no identifican al producto (tipo de producto, medidas,
    // conectores) - se buscan las que quedan ("EXPRESSION", "PRIM ROSE").
    const PALABRAS_GENERICAS_ESTRELLA = ['FRAGANCIA', 'FRAGANCIAS', 'PERFUME', 'EAU', 'DE', 'DEL', 'LA', 'EL',
        'PARFUM', 'COLOGNE', 'TOILETTE', 'COLONIA', 'ML', 'GR', 'PARA', 'CON', 'Y', 'EDP', 'EDT', 'SET'];

    // Con este % de parecido (o mas) y precio leido, el precio se actualiza
    // solo. Por debajo queda "revisar" con la propuesta guardada.
    const PCT_AUTOMATICO = 60;
    // Maximo de paginas que se le mandan a la IA por producto.
    const MAX_PAGINAS_BUSQUEDA = 5;

    public function obtener($id) {
        return $this->db->query(
            'SELECT id, comp AS competidor, desc_comp AS descripcion_competidor, modo, azzo_refe AS azzorti_referente, '
            . 'foto_comp AS foto_competidor, foto_azzo AS foto_azzorti, camp AS campana FROM capt_prod_estr WHERE id = '
            . $this->lit($id)
        )->row();
    }

    private function palabras_clave($texto) {
        $norm = mb_strtoupper($this->texto_util->sin_acentos((string) $texto), 'UTF-8');
        $palabras = [];
        foreach (preg_split('/[^A-Z0-9]+/', $norm) as $p) {
            if ($p === '' || preg_match('/^\d+(ML|G|GR)?$/', $p) || in_array($p, self::PALABRAS_GENERICAS_ESTRELLA, true)) {
                continue;
            }
            if (mb_strlen($p) >= 3) {
                $palabras[] = $p;
            }
        }
        return array_values(array_unique($palabras));
    }

    /**
     * Paginas del catalogo donde aparece el nombre: tienen que tener la
     * PRIMERA palabra clave (la marca/linea, ej. "VIBRANZA") y se ordenan
     * por cuantas de las demas tienen ("BLANC"). Devuelve [pagina => puntos].
     */
    private function paginas_candidatas($catalogo_id, array $palabras) {
        if (!$palabras) {
            return [];
        }
        $texto_por_pagina = [];
        foreach ($this->m_catalogo->productos_de($catalogo_id) as $p) {
            $texto_por_pagina[(int) $p->pagina] = ($texto_por_pagina[(int) $p->pagina] ?? '') . ' ' . ($p->texto_cercano ?? '');
        }
        $puntos = [];
        foreach ($texto_por_pagina as $pagina => $texto) {
            $norm = mb_strtoupper($this->texto_util->sin_acentos($texto), 'UTF-8');
            if (!preg_match('/\b' . preg_quote($palabras[0], '/') . '\b/u', $norm)) {
                continue;
            }
            $n = 1;
            foreach (array_slice($palabras, 1) as $palabra) {
                if (preg_match('/\b' . preg_quote($palabra, '/') . '\b/u', $norm)) {
                    $n++;
                }
            }
            $puntos[$pagina] = $n;
        }
        arsort($puntos);
        $puntos = array_slice($puntos, 0, self::MAX_PAGINAS_BUSQUEDA, true);
        // Paginas vecinas: las colecciones ocupan dos paginas y el nombre
        // de la linea puede salir solo en una ("Coleccion You" en la 18,
        // el frasco "You 90 ml" en la 19 sin la palabra legible por OCR).
        foreach (array_keys($puntos) as $pagina) {
            foreach ([$pagina + 1, $pagina - 1] as $vecina) {
                if (count($puntos) >= self::MAX_PAGINAS_BUSQUEDA + 2) {
                    break 2;
                }
                if (!isset($puntos[$vecina]) && isset($texto_por_pagina[$vecina])) {
                    $puntos[$vecina] = 0;
                }
            }
        }
        return $puntos;
    }

    /**
     * Busca UN producto (nombre + foto opcional) en un catalogo. Devuelve
     * ['estado' => encontrado|no_encontrado|sin_catalogo|sin_ia, y si se
     * encontro: pag, pag_impresa, cod, nom, pct, prec].
     */
    private function buscar_en_catalogo($catalogo, $nombre, $foto_archivo, $marca) {
        if (!$catalogo) {
            return ['estado' => 'sin_catalogo'];
        }
        $paginas = $this->paginas_candidatas($catalogo->id, $this->palabras_clave($nombre));
        $rutas = [];
        foreach (array_keys($paginas) as $pagina) {
            $ruta = RUTA_ARCHIVOS . "captura_v1/catalogo_paginas/{$catalogo->id}_{$pagina}_completa.png";
            if (file_exists($ruta)) {
                $rutas[$pagina] = $ruta;
            }
        }
        if (!$rutas) {
            return ['estado' => 'no_encontrado'];
        }
        $ruta_foto = $foto_archivo ? RUTA_ARCHIVOS . 'captura_v1/productos_estrella_fotos/' . $foto_archivo : null;
        $resultado = $this->vision_util->buscar_producto_en_paginas($ruta_foto, $nombre, $marca, array_values($rutas), 'productos_estrella');
        if ($resultado === null) {
            return ['estado' => 'sin_ia'];
        }
        $mejor = null;
        foreach (array_keys($rutas) as $i => $pagina) {
            $r = $resultado[$i + 1] ?? null;
            if (!$r || !$r['esta']) {
                continue;
            }
            // Gana el mas parecido; a igual parecido, el que trae precio.
            $clave = $r['pct'] * 10 + ($r['precio'] !== null ? 1 : 0);
            if ($mejor === null || $clave > $mejor['_clave']) {
                $mejor = $r + ['pag' => $pagina, '_clave' => $clave];
            }
        }
        if ($mejor === null) {
            return ['estado' => 'no_encontrado'];
        }
        return [
            'estado' => 'encontrado',
            'pag' => $mejor['pag'],
            'pag_impresa' => $this->m_catalogo->pagina_impresa($catalogo->id, $mejor['pag']),
            'cod' => $mejor['codigo'],
            'nom' => $mejor['nombre'] !== null ? mb_substr($mejor['nombre'], 0, 60) : null,
            'pct' => round($mejor['pct']),
            'prec' => $mejor['precio'],
        ];
    }

    /**
     * Busca precio del competidor (y del referente Azzorti si es homologo
     * fijo) para un producto estrella, guarda lo encontrado y, si es
     * confiable, actualiza los precios. Devuelve el detalle.
     */
    public function buscar_precio($id) {
        $this->load->model('captura_v1/m_catalogo');
        $this->load->library('vision_util');
        $f = $this->obtener($id);
        if (!$f) {
            return null;
        }
        $nombre_comp = trim(preg_replace('/^FRAGANCIAS?\s+/i', '', $f->descripcion_competidor));
        $comp = $this->buscar_en_catalogo(
            $this->m_catalogo->catalogo_por_competidor($f->competidor, $f->campana),
            $nombre_comp, $f->foto_competidor, $f->competidor
        );
        $azzo = null;
        if ($f->modo === 'HOMOLOGO_FIJO' && $f->azzorti_referente) {
            $azzo = $this->buscar_en_catalogo(
                $this->m_catalogo->catalogo_por_competidor('Azzorti', $f->campana),
                $f->azzorti_referente, $f->foto_azzorti, 'Azzorti'
            );
        }

        $confiable = fn($r) => $r && $r['estado'] === 'encontrado' && $r['prec'] !== null && $r['pct'] >= self::PCT_AUTOMATICO;
        $lados = array_filter([$comp, $azzo]);
        if (array_filter($lados, fn($r) => $r['estado'] === 'sin_catalogo')) {
            $estado = 'sin_catalogo';
        } elseif (array_filter($lados, fn($r) => $r['estado'] === 'sin_ia')) {
            $estado = 'sin_ia';
        } elseif (count(array_filter($lados, $confiable)) === count($lados)) {
            $estado = 'auto';
        } elseif (array_filter($lados, fn($r) => $r['estado'] === 'encontrado')) {
            $estado = 'revisar';
        } else {
            $estado = 'no_encontrado';
        }

        $sets = [
            'bus_esta = ' . $this->lit($estado),
            'bus_fech = ' . $this->lit($this->ahora_iso()),
            'bus_comp = ' . $this->lit(json_encode($comp, JSON_UNESCAPED_UNICODE)),
            'bus_azzo = ' . $this->lit($azzo ? json_encode($azzo, JSON_UNESCAPED_UNICODE) : null),
        ];
        // Precio automatico solo del lado que es confiable; el resto queda
        // como propuesta en bus_comp/bus_azzo para que Mercadeo la acepte.
        if ($confiable($comp)) {
            $sets[] = 'prec_comp = ' . $this->lit($comp['prec']);
        }
        if ($confiable($azzo)) {
            $sets[] = 'prec_azzo = ' . $this->lit($azzo['prec']);
            $sets[] = 'camp_azzo = ' . $this->lit($f->campana);
        }
        $this->db->query('UPDATE capt_prod_estr SET ' . implode(', ', $sets) . ' WHERE id = ' . $this->lit($id));
        return ['id' => (int) $id, 'estado' => $estado, 'competidor' => $comp, 'azzorti' => $azzo];
    }

    /** Mercadeo acepta la propuesta de un producto "revisar": copia los
     * precios propuestos (los que haya) a los precios del producto. */
    public function aceptar_propuesta($id) {
        $fila = $this->db->query('SELECT bus_comp, bus_azzo, camp FROM capt_prod_estr WHERE id = ' . $this->lit($id))->row();
        if (!$fila) {
            return false;
        }
        $comp = json_decode((string) $fila->bus_comp, true);
        $azzo = json_decode((string) $fila->bus_azzo, true);
        $sets = ["bus_esta = 'aceptado'"];
        if (!empty($comp['prec'])) {
            $sets[] = 'prec_comp = ' . $this->lit($comp['prec']);
        }
        if (!empty($azzo['prec'])) {
            $sets[] = 'prec_azzo = ' . $this->lit($azzo['prec']);
            $sets[] = 'camp_azzo = ' . $this->lit($fila->camp);
        }
        $this->db->query('UPDATE capt_prod_estr SET ' . implode(', ', $sets) . ' WHERE id = ' . $this->lit($id));
        return true;
    }

    /** "C17 2026" / "C-17 2026" -> 202617, para ordenar campañas en el
     * tiempo (el texto no ordena bien: "C9" > "C17"). null si no se entiende. */
    private function orden_campana($campana) {
        if (preg_match('/C\s*-?\s*(\d{1,2})\D+(\d{4})/i', (string) $campana, $m)) {
            return (int) $m[2] * 100 + (int) $m[1];
        }
        return null;
    }

    /** Precios de todas las campañas, agrupados por producto (competidor +
     * descripción), para comparar cada fila contra sus campañas anteriores. */
    private function historial_por_producto() {
        $filas = $this->db->query(
            'SELECT comp, desc_comp, camp, prec_comp, prec_azzo FROM capt_prod_estr'
        )->result_array();
        $hist = [];
        foreach ($filas as $f) {
            $orden = $this->orden_campana($f['camp']);
            if ($orden === null) {
                continue;
            }
            $hist[$f['comp'] . '|' . $f['desc_comp']][] = [
                'orden' => $orden,
                'campana' => $f['camp'],
                'precio_competidor' => $f['prec_comp'] !== null ? round((float) $f['prec_comp'], 2) : null,
                'precio_azzorti' => $f['prec_azzo'] !== null ? round((float) $f['prec_azzo'], 2) : null,
            ];
        }
        foreach ($hist as &$lista) {
            usort($lista, function ($a, $b) { return $a['orden'] - $b['orden']; });
        }
        unset($lista);
        return $hist;
    }

    /** Ultima campaña ANTERIOR a $orden con dato en $campo, o null. */
    private function ultimo_anterior(array $historial, $orden, $campo) {
        $encontrado = null;
        foreach ($historial as $h) {
            if ($orden !== null && $h['orden'] < $orden && $h[$campo] !== null) {
                $encontrado = $h;
            }
        }
        return $encontrado;
    }

    /** server.py lineas 1048-1114. */
    public function listar($campana, $umbral, $base_url) {
        $sql = 'SELECT id, comp AS competidor, cate AS categoria, desc_comp AS descripcion_competidor, modo, '
            . 'azzo_refe AS azzorti_referente, prec_comp AS precio_competidor, prec_azzo AS precio_azzorti, '
            . 'foto_comp AS foto_competidor, foto_azzo AS foto_azzorti, camp AS campana, '
            . 'camp_azzo AS campana_azzorti, fact AS actualizado_en, '
            . 'bus_esta AS busqueda_estado, bus_fech AS busqueda_fecha, bus_comp, bus_azzo '
            . 'FROM capt_prod_estr WHERE 1=1';
        if ($campana) {
            $sql .= ' AND camp = ' . $this->lit($campana);
        }
        $sql .= ' ORDER BY comp, desc_comp';
        $filas = $this->db->query($sql)->result_array();

        $historial = $this->historial_por_producto();
        $resultado = [];
        foreach ($filas as $r) {
            $r['foto_url'] = !empty($r['foto_competidor'])
                ? $this->archivo_util->url_publica('productos_estrella_fotos/' . $r['foto_competidor'], $base_url)
                : null;
            $r['foto_azzorti_url'] = !empty($r['foto_azzorti'])
                ? $this->archivo_util->url_publica('productos_estrella_fotos/' . $r['foto_azzorti'], $base_url)
                : null;
            $r['busqueda_competidor'] = $r['bus_comp'] ? json_decode($r['bus_comp'], true) : null;
            $r['busqueda_azzorti'] = $r['bus_azzo'] ? json_decode($r['bus_azzo'], true) : null;
            unset($r['bus_comp'], $r['bus_azzo']);
            $r['campana_anterior'] = $r['campana_azzorti'] ?? null;
            if ($r['precio_competidor'] !== null) {
                $r['precio_competidor'] = round((float) $r['precio_competidor'], 2);
            }
            if ($r['precio_azzorti'] !== null) {
                $r['precio_azzorti'] = round((float) $r['precio_azzorti'], 2);
            }

            // Comparacion (pedido de Mercadeo 2026-11-18):
            //  1. Con homologo Azzorti y los dos precios de esta campaña:
            //     contra Azzorti, como siempre.
            //  2. Homologo Azzorti sin precio en esta campaña (no salio en el
            //     catalogo): contra el ULTIMO precio Azzorti conocido, y se
            //     dice de que campaña es.
            //  3. Sin homologo (VS_CAMPANA_ANTERIOR), o sin ningun precio
            //     Azzorti: contra el precio del MISMO producto del competidor
            //     en su campaña anterior.
            $hist = $historial[$r['competidor'] . '|' . $r['descripcion_competidor']] ?? [];
            $orden = $this->orden_campana($r['campana']);
            $r['historial'] = array_values(array_map(function ($h) {
                return ['campana' => $h['campana'], 'precio_competidor' => $h['precio_competidor'], 'precio_azzorti' => $h['precio_azzorti']];
            }, array_filter($hist, function ($h) use ($orden) { return $orden === null || $h['orden'] <= $orden; })));
            $ant_comp = $this->ultimo_anterior($hist, $orden, 'precio_competidor');
            $ant_azzo = $this->ultimo_anterior($hist, $orden, 'precio_azzorti');
            $r['delta_pct'] = null;
            $r['tipo_comparacion'] = null;
            $r['precio_campana_anterior'] = null;
            $r['precio_azzorti_ultimo'] = null;
            $r['campana_azzorti_ultimo'] = null;
            $pc = $r['precio_competidor'];
            $ref = $r['azzorti_referente'] ?? '';

            if ($r['modo'] === 'HOMOLOGO_FIJO' && $pc !== null && !empty($r['precio_azzorti'])) {
                $r['delta_pct'] = round(($r['precio_azzorti'] - $pc) / $r['precio_azzorti'] * 100, 2);
                $r['tipo_comparacion'] = 'azzorti';
                $r['comparacion'] = 'vs Azzorti (' . $ref . ')';
            } elseif ($r['modo'] === 'HOMOLOGO_FIJO' && $pc !== null && $ant_azzo && $ant_azzo['precio_azzorti'] > 0) {
                $pa = $ant_azzo['precio_azzorti'];
                $r['delta_pct'] = round(($pa - $pc) / $pa * 100, 2);
                $r['tipo_comparacion'] = 'azzorti_ultimo';
                $r['precio_azzorti_ultimo'] = $pa;
                $r['campana_azzorti_ultimo'] = $ant_azzo['campana'];
                $r['comparacion'] = 'vs Azzorti (' . $ref . ') — último precio conocido, ' . $ant_azzo['campana'];
            } elseif ($pc !== null && $ant_comp && $ant_comp['precio_competidor'] > 0) {
                $pant = $ant_comp['precio_competidor'];
                $r['delta_pct'] = round(($pc - $pant) / $pant * 100, 2);
                $r['tipo_comparacion'] = 'campana_anterior';
                $r['precio_campana_anterior'] = $pant;
                $r['campana_anterior'] = $ant_comp['campana'];
                $r['comparacion'] = 'vs su precio en ' . $ant_comp['campana'] . ' (Bs ' . $pant . ')';
            } elseif ($r['modo'] !== 'HOMOLOGO_FIJO' && $pc !== null && !empty($r['precio_azzorti'])) {
                // Archivos viejos: el Excel traia el precio anterior en la
                // columna de Azzorti.
                $r['delta_pct'] = round(($r['precio_azzorti'] - $pc) / $r['precio_azzorti'] * 100, 2);
                $r['tipo_comparacion'] = 'excel';
                $r['comparacion'] = 'vs campaña anterior (' . ($r['campana_azzorti'] ?? '') . ', ' . $ref . ')';
            } else {
                $r['comparacion'] = $r['modo'] === 'HOMOLOGO_FIJO'
                    ? 'vs Azzorti (' . $ref . ') — falta precio'
                    : 'vs campaña anterior — sin dato previo o precio actual pendiente';
            }
            $r['alerta'] = ($r['delta_pct'] !== null) && (abs($r['delta_pct']) > $umbral);
            $resultado[] = $r;
        }
        return $resultado;
    }
}
