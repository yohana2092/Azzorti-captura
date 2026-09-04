<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Puerto del motor de homologacion de server.py (lineas 1326-1621):
 * scoring de similitud para Retail (color/silueta/composicion/manga) y
 * homologacion por palabras clave contra el catalogo indexado para Venta
 * Directa. No compara nunca por codigo/SKU compartido con la competencia
 * (no existe tal cosa) - siempre por atributos o por texto OCR cercano.
 */
class M_homologacion extends CI_Model {

    // server.py lineas 1404-1420.
    const CATEGORIA_RETAIL_PALABRA_CLAVE = [
        'blusas femeninas' => 'BLUSA',
        'vestidos femeninos cortos' => 'VESTIDO',
        'vestidos femeninos largos' => 'VESTIDO',
        'camisetas masculinas' => 'CAMISETA',
        'polos masculinos' => 'POLO',
        'camisetas femeninas' => 'CAMISETA',
        'crop top femenino' => 'CROP',
        'jeans femeninos' => 'JEAN',
        'jeans masculinos' => 'JEAN',
        'lenceria ppp' => 'LENCERIA',
        'cubrecamas dobles' => 'CUBRECAMA',
        'sabanas dobles' => 'SABANA',
        'toallas' => 'TOALLA',
        'mochilas infantiles' => 'MOCHILA',
        'cubrecamas sencillos infantiles' => 'CUBRECAMA',
    ];

    public function __construct() {
        parent::__construct();
        $this->load->library('texto_util');
        $this->load->library('archivo_util');
        $this->load->model('captura_v1/m_catalogo');
        $this->load->model('captura_v1/m_producto');
    }

    /**
     * Puerto de _score_similitud (server.py lineas 1353-1393). Puntaje 0-100.
     * $captura y $producto son arrays asociativos (o stdClass castable a
     * array) con las claves color/silueta/composicion1+2 (o composicion)/manga.
     */
    public function score_similitud($captura, $producto) {
        $captura = (array) $captura;
        $producto = (array) $producto;
        $score = 0.0;

        $color_c = mb_strtolower(trim($captura['color'] ?? ''), 'UTF-8');
        $color_p = mb_strtolower(trim($producto['color'] ?? ''), 'UTF-8');
        if ($color_c !== '' && $color_p !== '' && $color_c === $color_p) {
            $score += 30;
        }

        $silueta_c = mb_strtolower(trim($captura['silueta'] ?? ''), 'UTF-8');
        $silueta_p = mb_strtolower(trim($producto['silueta'] ?? ''), 'UTF-8');
        if ($silueta_c !== '' && $silueta_p !== '') {
            if ($silueta_c === $silueta_p) {
                $score += 30;
            } else {
                $familia_c = $this->texto_util->familia_silueta($silueta_c);
                if ($familia_c && in_array($silueta_p, $familia_c, true)) {
                    $score += 18;
                }
            }
        }

        $comp_c = trim(($captura['composicion1'] ?? '') . ' ' . ($captura['composicion2'] ?? ''));
        $comp_p = trim($producto['composicion'] ?? '');
        if ($comp_c !== '' && $comp_p !== '') {
            $tokens_c = $this->texto_util->tokens_tela($comp_c);
            $tokens_p = $this->texto_util->tokens_tela($comp_p);
            $interseccion = array_intersect($tokens_c, $tokens_p);
            $union = array_unique(array_merge($tokens_c, $tokens_p));
            if (count($interseccion) > 0 && count($union) > 0) {
                $score += 25 * count($interseccion) / count($union);
            }
        }

        $manga_c = mb_strtolower(trim($captura['manga'] ?? ''), 'UTF-8');
        $manga_p = mb_strtolower(trim($producto['manga'] ?? ''), 'UTF-8');
        if ($manga_c !== '' && $manga_p !== '' && $manga_c === $manga_p) {
            $score += 15;
        }

        return round($score, 1);
    }

    /**
     * Puerto de _candidatos_moda_azzorti (server.py lineas 1423-1437):
     * candidatos del catalogo PDF de Azzorti dado (seccion Moda o sin
     * seccion) cuyo texto_cercano contiene la palabra clave de la
     * categoria, y que no tienen texto mezclado de varios productos.
     * $catalogo_id acota la busqueda a UN catalogo puntual (el vigente,
     * resuelto por el llamador) - antes buscaba en todos los catalogos
     * de Azzorti alguna vez subidos, lo que traia duplicados.
     */
    public function candidatos_moda_azzorti($categoria_captura, $catalogo_id) {
        $clave = mb_strtolower(trim($this->texto_util->sin_acentos($categoria_captura ?? '')), 'UTF-8');
        $palabra = self::CATEGORIA_RETAIL_PALABRA_CLAVE[$clave] ?? null;
        if (!$palabra) {
            return [];
        }
        $filas = $this->m_catalogo->candidatos_moda_azzorti($catalogo_id);
        $resultado = [];
        foreach ($filas as $f) {
            $texto = mb_strtoupper($this->texto_util->sin_acentos($f->texto_cercano ?? ''), 'UTF-8');
            if (mb_strpos($texto, $palabra) !== false && !$this->texto_util->texto_mezclado($f->texto_cercano)) {
                $resultado[] = $f;
            }
        }
        return $resultado;
    }

    /**
     * Puerto de _sugerir_homologacion_venta_directa (server.py lineas
     * 1440-1531). $base_url es el host actual de la peticion (para armar
     * las URL de foto).
     */
    public function sugerir_venta_directa($captura, $base_url) {
        $captura = (array) $captura;
        $foto_pagina_url_fn = function ($catalogo_id, $pagina, $codigo) use ($base_url) {
            $nombre = "{$catalogo_id}_{$pagina}_{$codigo}.png";
            return $this->archivo_util->url_publica('catalogo_paginas/' . $nombre, $base_url);
        };
        $catalogo = $this->m_catalogo->catalogo_azzorti_mas_reciente($captura['campana'], $captura['creada_en'] ?? null);
        if (!$catalogo) {
            return [
                'captura_id' => $captura['id'],
                'criterio' => 'No hay ningún catálogo de Azzorti subido en Competencia todavía.',
                'sugerencias' => [],
            ];
        }
        $todos = $this->m_catalogo->productos_de($catalogo->id);
        if (!$todos) {
            return [
                'captura_id' => $captura['id'],
                'criterio' => "El catálogo de Azzorti '{$catalogo->archivo}' todavía no fue indexado "
                    . "(POST /catalogos/{id}/indexar-productos) o no se encontró ningún producto en él.",
                'sugerencias' => [],
            ];
        }
        $candidatos = array_filter($todos, function ($p) use ($captura) {
            return $this->texto_util->candidato_coincide_categoria($captura['categoria'], $p->seccion)
                && !$this->texto_util->texto_mezclado($p->texto_cercano);
        });
        $texto_principal = trim(($captura['categoria'] ?? '') . ' ' . ($captura['descripcion'] ?? ''));
        $texto_secundario = trim(($captura['caracteristicas'] ?? '') . ' ' . ($captura['detalle'] ?? ''));

        $sugerencias = [];
        foreach ($candidatos as $p) {
            $score = round($this->texto_util->score_texto($texto_principal, $texto_secundario, $p->texto_cercano ?? '') * 100, 1);
            $sugerencias[] = [
                'sku' => $p->producto_codigo,
                'categoria' => $captura['categoria'],
                'descripcion' => $p->texto_cercano ? mb_substr($p->texto_cercano, 0, 80) : $p->producto_codigo,
                'color' => null,
                'composicion' => null,
                'silueta' => null,
                'manga' => null,
                'precio' => $p->precio ?: 0,
                'pagina_catalogo' => (int) $p->pagina,
                'foto_url' => $foto_pagina_url_fn($catalogo->id, $p->pagina, $p->producto_codigo),
                'score_similitud' => $score,
            ];
        }
        usort($sugerencias, fn($a, $b) => $b['score_similitud'] <=> $a['score_similitud']);
        $sugerencias = array_values(array_filter($sugerencias, fn($s) => $s['score_similitud'] > 0));

        $vistas = [];
        $por_pagina = [];
        foreach ($sugerencias as $s) {
            if (in_array($s['pagina_catalogo'], $vistas, true)) {
                continue;
            }
            $vistas[] = $s['pagina_catalogo'];
            $por_pagina[] = $s;
        }
        $sugerencias = array_slice($por_pagina, 0, 20);

        return [
            'captura_id' => $captura['id'],
            'criterio' => "Comparado por palabras clave contra el catálogo de Azzorti "
                . "'{$catalogo->archivo}' (campaña {$catalogo->campana}) — no hay ficha de "
                . 'atributos por producto en Venta Directa como sí la hay en Retail, así que la '
                . 'coincidencia es aproximada (texto OCR cercano al código de cada producto).',
            'sugerencias' => $sugerencias,
        ];
    }

    /**
     * Puerto de la rama Retail de sugerir_homologacion (server.py lineas
     * 1544-1621): combina los 19 productos de muestra (azzorti_producto,
     * filtrados por categoria exacta) con productos reales indexados del
     * catalogo PDF de Azzorti (seccion Moda), sin filtrar por score
     * (a diferencia de Venta Directa) - se muestran todas las sugerencias.
     */
    public function sugerir_retail($captura, $base_url) {
        $captura = (array) $captura;
        $candidatos = $this->m_producto->por_categoria($captura['categoria']);
        $skus_muestra = array_map(fn($p) => $p->sku, $candidatos);
        // Se resuelve el catalogo de Azzorti vigente UNA vez y se usa
        // tanto para las fotos como para acotar candidatos_moda_azzorti
        // a ESE catalogo puntual (antes buscaba en todos los catalogos
        // de Azzorti alguna vez subidos, trayendo duplicados si se
        // resubio un catalogo de prueba varias veces). Se filtra por la
        // CAMPANA de la captura (mismo patron que sugerir_venta_directa)
        // - sin esto, "mas reciente" significa literalmente el ultimo
        // subido por id, que en un entorno de pruebas con catalogos
        // chicos de test subidos DESPUES del catalogo real termina
        // eligiendo el catalogo equivocado (confirmado: un catalogo de
        // hogar subido despues tapaba al catalogo real de moda).
        // catalogo_azzorti_mas_reciente() ya cae de vuelta al mas
        // reciente sin filtro si ninguno matchea esa campana.
        $catalogo_azzorti = $this->m_catalogo->catalogo_azzorti_mas_reciente($captura['campana'], $captura['creada_en'] ?? null);
        $candidatos_moda = $catalogo_azzorti
            ? array_values(array_filter(
                $this->candidatos_moda_azzorti($captura['categoria'], $catalogo_azzorti->id),
                fn($p) => !in_array($p->producto_codigo, $skus_muestra, true)
            ))
            : [];

        $sugerencias_muestra = [];
        foreach ($candidatos as $p) {
            $sugerencias_muestra[] = [
                'sku' => $p->sku,
                'categoria' => $p->categoria,
                'descripcion' => $p->descripcion,
                'color' => $p->color,
                'composicion' => $p->composicion,
                'silueta' => $p->silueta,
                'manga' => $p->manga,
                'precio' => $p->precio,
                'pagina_catalogo' => $p->pagina_catalogo,
                'foto_url' => $p->foto_archivo ? $this->archivo_util->url_publica($p->foto_archivo, $base_url) : null,
                'score_similitud' => $this->score_similitud($captura, $p),
            ];
        }

        $sugerencias_moda = [];
        foreach ($candidatos_moda as $p) {
            $foto_url = null;
            if ($catalogo_azzorti) {
                $nombre = "{$catalogo_azzorti->id}_{$p->pagina}_{$p->producto_codigo}.png";
                $foto_url = $this->archivo_util->url_publica('catalogo_paginas/' . $nombre, $base_url);
            }
            $pseudo_producto = ['color' => null, 'silueta' => null, 'composicion' => $p->texto_cercano, 'manga' => null];
            $sugerencias_moda[] = [
                'sku' => $p->producto_codigo,
                'categoria' => $captura['categoria'],
                'descripcion' => ($p->texto_cercano ? mb_substr($p->texto_cercano, 0, 80) : '') ?: $p->producto_codigo,
                'color' => null,
                'composicion' => $p->texto_cercano,
                'silueta' => null,
                'manga' => null,
                'precio' => $p->precio ?: 0,
                'pagina_catalogo' => $p->pagina,
                'foto_url' => $foto_url,
                'score_similitud' => $this->score_similitud($captura, $pseudo_producto),
            ];
        }

        $sugerencias = array_merge($sugerencias_muestra, $sugerencias_moda);
        usort($sugerencias, fn($a, $b) => $b['score_similitud'] <=> $a['score_similitud']);

        return [
            'captura_id' => $captura['id'],
            'criterio' => 'Filtrado por categoría, combinando el catálogo de muestra '
                . '(azzorti_producto) con productos reales indexados del catálogo PDF '
                . 'de Azzorti (mismo catálogo que usa Venta Directa). Ranking por '
                . 'color, silueta, composición y manga — nunca por código, ya que '
                . 'la competencia no comparte SKU con Azzorti. Los productos indexados '
                . 'del PDF no tienen color/silueta/manga estructurados, así que solo '
                . 'puntúan por composición (tela).',
            'sugerencias' => $sugerencias,
        ];
    }
}
