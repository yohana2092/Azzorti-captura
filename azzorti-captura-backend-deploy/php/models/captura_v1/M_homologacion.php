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
        'camisetas masculinas' => 'CAMISETA|POLERA',
        'polos masculinos' => 'POLO',
        'camisetas femeninas' => 'CAMISETA|POLERA',
        'crop top femenino' => 'CROP',
        'jeans femeninos' => 'JEAN',
        'jeans masculinos' => 'JEAN',
        'lenceria ppp' => 'LENCERIA',
        'cubrecamas dobles' => 'CUBRECAMA',
        'sabanas dobles' => 'SABANA',
        'toallas' => 'TOALLA',
        'mochilas infantiles' => 'MOCHILA',
        'cubrecamas sencillos infantiles' => 'CUBRECAMA',
        'chaquetas femeninas' => 'CHAQUETA',
        'chaquetas masculinas' => 'CHAQUETA',
        // Agregadas revisando el catalogo real completo - ver
        // Texto_util::CATEGORIAS_PRENDA (mismo hallazgo, usado ahi para
        // la descripcion y aca para filtrar candidatos por categoria).
        'babydoll' => 'BABYDOLL',
        'bermuda' => 'BERMUDA',
        'bluson' => 'BLUSON',
        'bustier' => 'BUSTIER',
        'buzo' => 'BUZO',
        'camibuzo' => 'CAMIBUZO',
        'leggins' => 'LEGGIN',
        'panty' => 'PANTY',
    ];

    /**
     * Conectores y sinonimos de genero que NO cuentan como "palabra
     * disparadora" al extraerla de la descripcion escrita a mano (ver
     * sugerir_venta_directa()) - el genero ya se evalua aparte
     * ($pide_femenino/$pide_masculino).
     */
    const PALABRAS_VACIAS_DISPARADOR = [
        'DE', 'LA', 'EL', 'LOS', 'LAS', 'UN', 'UNA', 'Y', 'O', 'CON', 'SIN', 'PARA',
        'DAMA', 'CABALLERO', 'FEMENINO', 'FEMENINA', 'MASCULINO', 'MASCULINA',
        'NINO', 'NINA', 'UNISEX', 'NUEVO', 'NUEVA', 'ADULTO', 'ADULTA', 'MUJER', 'HOMBRE',
        'NEGRO', 'NEGRA', 'BLANCO', 'BLANCA', 'AZUL', 'ROJO', 'ROJA', 'VERDE', 'ROSADO', 'ROSADA',
        'GRIS', 'CAFE', 'BEIGE', 'DORADO', 'DORADA', 'PLATEADO', 'PLATEADA',
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

    // Cuantos candidatos (ya acotados por texto) se le mandan a comparar
    // visualmente - no "todo el catalogo", para no gastar de mas en
    // tokens/llamadas. Y cuantos quedan en el resultado final cuando la
    // comparacion visual si funciono (pedido explicito: "las 5 imagenes
    // que mas se parezcan").
    const VISION_MAX_CANDIDATOS = 6;
    const VISION_MAX_RESULTADO = 5;
    // Por debajo de este % visual el candidato no se muestra.
    const VISION_MIN_PARECIDO = 25;

    /**
     * Re-rankea $sugerencias (ya ordenados y acotados por texto) usando
     * comparacion visual real contra la foto capturada (Vision_util,
     * API interna de la empresa - ver libraries/Vision_util.php). Solo
     * sobre los primeros VISION_MAX_CANDIDATOS - son candidatos que YA
     * pasaron el filtro de texto, la comparacion visual es para elegir
     * CUAL de esos pocos es el correcto, no para buscar entre todo el
     * catalogo de nuevo.
     *
     * Si falla por cualquier motivo (sin foto, archivo faltante, la API
     * no responde, respuesta rara) devuelve $sugerencias SIN TOCAR - la
     * homologacion por texto sigue funcionando igual que antes, nunca se
     * rompe la respuesta por esto.
     */
    private function aplicar_comparacion_visual(array $sugerencias, array $captura, $base_url = null) {
        if (empty($captura['foto_archivo']) || !$sugerencias) {
            return $sugerencias;
        }
        $ruta_foto = RUTA_ARCHIVOS . 'captura_v1/capturas_fotos/' . $captura['foto_archivo'];
        if (!file_exists($ruta_foto)) {
            return $sugerencias;
        }

        $candidatos_vision = array_slice($sugerencias, 0, self::VISION_MAX_CANDIDATOS);
        $rutas_candidatos = [];
        foreach ($candidatos_vision as $s) {
            if (empty($s['_ruta_local']) || !file_exists($s['_ruta_local'])) {
                // Falta la imagen local de algun candidato - no arriesgar
                // un ranking a medias/desalineado, se sigue solo con texto.
                return $sugerencias;
            }
            $rutas_candidatos[] = $s['_ruta_local'];
        }

        $this->load->library('vision_util');
        $resultado_visual = $this->vision_util->comparar(
            $ruta_foto, $rutas_candidatos, $captura['competidor'] ?? 'captura_v1',
            array_map(fn($s) => $s['sku'] ?? null, $candidatos_vision)
        );
        if ($resultado_visual === null) {
            return $sugerencias;
        }

        foreach ($candidatos_vision as $i => &$s) {
            $r = $resultado_visual[$i + 1] ?? null;
            $s['similitud_visual_pct'] = $r['pct'] ?? null;
            $s['_genero_visual'] = $r['genero'] ?? 'unisex';
            // Miniatura recortada sobre el producto segun la ubicacion
            // que devolvio la IA (el recorte hecho al indexar, por
            // posicion del texto, solia caer en el fondo o en la modelo).
            // Si no hay ubicacion o el recorte falla, queda la de antes.
            if (!empty($r['caja']) && $base_url !== null) {
                $url = $this->miniatura_desde_caja($s['_ruta_local'], $r['caja'], $s['sku'] ?? 'x', $base_url);
                if ($url !== null) {
                    $s['foto_url'] = $url;
                }
            }
        }
        unset($s);
        usort($candidatos_vision, fn($a, $b) => ($b['similitud_visual_pct'] ?? -1) <=> ($a['similitud_visual_pct'] ?? -1));
        // Solo se muestran los que de verdad se parecen a la captura
        // (pedido explicito: "solo debes traer imagenes acordes a la
        // captura") - siempre queda al menos el mejor, para no dejar la
        // lista vacia.
        // Genero segun la IA (el nombre impreso no siempre lo dice: "Reloj
        // Lumin" es de hombre, "Set reloj mas pulsera" lo corto el OCR):
        // si la captura pide dama, se descarta lo que la IA ve como de
        // caballero, y viceversa.
        $texto_genero = mb_strtoupper($this->texto_util->sin_acentos(trim(
            ($captura['descripcion'] ?? '') . ' ' . ($captura['caracteristicas'] ?? '') . ' ' . ($captura['detalle'] ?? '')
            . ' ' . ($captura['genero'] ?? '')
        )), 'UTF-8');
        $pide_fem = preg_match('/\b(FEMENIN|DAMA|MUJER)/u', $texto_genero);
        $pide_mas = preg_match('/\b(MASCULIN|CABALLERO|HOMBRE)/u', $texto_genero);
        $parecidos = array_values(array_filter($candidatos_vision, function ($s) use ($pide_fem, $pide_mas) {
            if ($pide_fem && !$pide_mas && ($s['_genero_visual'] ?? '') === 'masculino') {
                return false;
            }
            if ($pide_mas && !$pide_fem && ($s['_genero_visual'] ?? '') === 'femenino') {
                return false;
            }
            // Si su nombre impreso coincide con lo que escribio la
            // promotora ("Lentes" -> "Lentes de sol femenino Kiva"), es
            // del mismo tipo de producto: se muestra aunque la IA le de
            // poco parecido (lentes de oficina vs. de sol) y solo se
            // ordena por %. El corte visual queda para lo que entro por
            // otras palabras. Antes con "Lentes" Dama salia solo Brizza
            // (28%) y se ocultaban Kiva y Loira.
            return !empty($s['_coincide_nombre'])
                || ($s['similitud_visual_pct'] ?? 0) >= self::VISION_MIN_PARECIDO;
        }));
        if (!$parecidos) {
            // Ninguno paso el corte de parecido (no hay uno igual en el
            // catalogo, ej. lentes de oficina vs lentes de sol): se
            // muestran los mas cercanos que no sean del genero contrario,
            // ordenados por parecido, para que la promotora elija. Antes
            // quedaba uno solo y parecia que el catalogo no tenia mas.
            $parecidos = array_values(array_filter($candidatos_vision, function ($s) use ($pide_fem, $pide_mas) {
                $g = $s['_genero_visual'] ?? 'unisex';
                return !($pide_fem && !$pide_mas && $g === 'masculino') && !($pide_mas && !$pide_fem && $g === 'femenino');
            }));
        }
        return array_slice($parecidos, 0, self::VISION_MAX_RESULTADO);
    }

    /**
     * Recorta $ruta_pagina segun $caja ([x0, y0, x1, y1] en fracciones,
     * aproximada - se ensancha un 6% por lado para no cortar el producto)
     * y lo guarda en catalogo_paginas/ como "<pagina>_<sku>_vis.png".
     * Devuelve la URL publica, o null si algo falla.
     */
    private function miniatura_desde_caja($ruta_pagina, array $caja, $sku, $base_url) {
        if (!class_exists('Imagick') || !file_exists($ruta_pagina)) {
            return null;
        }
        try {
            $im = new Imagick($ruta_pagina);
            $ancho = $im->getImageWidth();
            $alto = $im->getImageHeight();
            [$x0, $y0, $x1, $y1] = $caja;
            $margen = 0.06;
            $x0 = max(0, $x0 - $margen);
            $y0 = max(0, $y0 - $margen);
            $x1 = min(1, $x1 + $margen);
            $y1 = min(1, $y1 + $margen);
            $im->cropImage((int) (($x1 - $x0) * $ancho), (int) (($y1 - $y0) * $alto), (int) ($x0 * $ancho), (int) ($y0 * $alto));
            $im->setImagePage(0, 0, 0, 0);
            $im->thumbnailImage(400, 400, true);
            $base = pathinfo($ruta_pagina, PATHINFO_FILENAME);
            $nombre = preg_replace('/[^A-Za-z0-9_]/', '', $base . '_' . $sku) . '_vis.png';
            $im->writeImage(dirname($ruta_pagina) . '/' . $nombre);
            $im->clear();
            return $this->archivo_util->url_publica('catalogo_paginas/' . $nombre, $base_url);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Palabras utiles de la descripcion escrita a mano ("Billetera dama"
     * -> ["BILLETERA"]): mayusculas sin acentos, sin conectores, genero,
     * colores ni palabras de menos de 4 letras.
     */
    private function palabras_de_descripcion($descripcion) {
        $palabras = [];
        $norm = mb_strtoupper($this->texto_util->sin_acentos(trim((string) $descripcion)), 'UTF-8');
        foreach (preg_split('/[^\p{L}]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) as $palabra) {
            if (mb_strlen($palabra) >= 4 && !in_array($palabra, self::PALABRAS_VACIAS_DISPARADOR, true)) {
                $palabras[] = $palabra;
            }
        }
        return array_values(array_unique($palabras));
    }

    /** _ruta_local es un campo interno (para aplicar_comparacion_visual) - nunca debe llegar a la respuesta JSON. */
    /** true si el nombre real impreso trae alguna de las palabras que
     * escribio la promotora (mismo criterio que el filtro de candidatos). */
    private function nombre_coincide($nombre_real, array $palabras) {
        if (!$nombre_real || !$palabras) {
            return false;
        }
        $texto = mb_strtoupper($this->texto_util->sin_acentos($nombre_real), 'UTF-8');
        foreach ($palabras as $palabra) {
            if (preg_match('/\b' . preg_quote($palabra, '/') . '/u', $texto)) {
                return true;
            }
        }
        return false;
    }

    private function quitar_ruta_local(array $sugerencias) {
        foreach ($sugerencias as &$s) {
            unset($s['_ruta_local'], $s['_genero_visual'], $s['_coincide_nombre']);
        }
        unset($s);
        return $sugerencias;
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
    public function candidatos_moda_azzorti($categoria_captura, $catalogo_id, $descripcion = null) {
        $clave = mb_strtolower(trim($this->texto_util->sin_acentos($categoria_captura ?? '')), 'UTF-8');
        $palabras = [];
        if (isset(self::CATEGORIA_RETAIL_PALABRA_CLAVE[$clave])) {
            $palabras = explode('|', self::CATEGORIA_RETAIL_PALABRA_CLAVE[$clave]);
        }
        // Ademas de la categoria del desplegable, el NOMBRE escrito por la
        // promotora ("Polera dama") - el catalogo de Bolivia no siempre
        // usa la misma palabra que la categoria (no hay "camisetas", son
        // "poleras"). Basta con que el producto calce con UNA de las dos.
        $palabras = array_merge($palabras, $this->palabras_de_descripcion($descripcion));
        if (!$palabras) {
            return [];
        }
        $palabra = implode('|', array_unique($palabras));
        $filas = $this->m_catalogo->candidatos_moda_azzorti($catalogo_id);
        $resultado = [];
        foreach ($filas as $f) {
            $texto = mb_strtoupper($this->texto_util->sin_acentos($f->texto_cercano ?? ''), 'UTF-8');
            // Antes era un simple mb_strpos (substring, sin limite de
            // palabra) - con "Buzo" y "Camibuzo" como categorias
            // separadas eso hacia que buscar "Buzo" tambien trajera
            // cualquier "Camibuzo" (la palabra "BUZO" esta contenida
            // adentro de "CAMIBUZO"). El limite de palabra solo va
            // ADELANTE (no \b...\b completo) para seguir tolerando el
            // plural del catalogo ("PANTY" calza con "PANTYS").
            // "|" separa sinonimos: en el catalogo de Bolivia la camiseta se
            // llama "Polera" (no hay ningun producto que diga "camiseta").
            $alternativas = implode('|', array_map(fn($w) => preg_quote($w, '/'), explode('|', $palabra)));
            if (preg_match('/\b(?:' . $alternativas . ')/u', $texto) && !$this->texto_util->texto_mezclado($f->texto_cercano)) {
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
        // Vista previa grande = pagina completa (no un recorte propio) -
        // a pedido explicito, el recorte automatico seguia sin mostrar
        // la prenda en casos reales. Ver Catalogos::indexar_productos_post.
        $foto_pagina_completa_url_fn = function ($catalogo_id, $pagina) use ($base_url) {
            $nombre = "{$catalogo_id}_{$pagina}_completa.png";
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
        // Texto que la promotora escribio a mano (no la categoria del
        // dropdown) - de ahi se saca el genero pedido, para que
        // funcione sin importar si eligio "Hogar" o "Joyería" para algo
        // que en realidad es, por ejemplo, un reloj.
        $texto_escrito_norm = mb_strtoupper($this->texto_util->sin_acentos(
            trim(($captura['descripcion'] ?? '') . ' ' . ($captura['caracteristicas'] ?? '')
                . ' ' . ($captura['detalle'] ?? '') . ' ' . ($captura['genero'] ?? ''))
        ), 'UTF-8');
        // Palabra(s) disparadora(s): se sacan DIRECTO de lo que la
        // promotora escribio en descripcion - no de una lista fija que
        // habria que ir completando a mano por cada tipo de producto
        // nuevo (reloj, billetera, secador, tapete...). Si escribe
        // "Billetera dama", "BILLETERA" ya es suficiente para buscar
        // ese mismo texto en el catalogo (OCR) directamente - se
        // descartan conectores y sinonimos de genero (el genero se
        // evalua aparte, ver $pide_femenino/$pide_masculino mas abajo)
        // y palabras muy cortas (ruido de OCR/tipeo).
        $palabras_disparadoras = [];
        $descripcion_norm = mb_strtoupper($this->texto_util->sin_acentos(trim($captura['descripcion'] ?? '')), 'UTF-8');
        foreach (preg_split('/\s+/u', $descripcion_norm) as $palabra) {
            if (mb_strlen($palabra) >= 4 && !in_array($palabra, self::PALABRAS_VACIAS_DISPARADOR, true)) {
                $palabras_disparadoras[] = $palabra;
            }
        }
        // "Dama"/"caballero" son sinonimos comunes que la promotora usa
        // en vez de "femenino"/"masculino" (ej. "reloj dama").
        $pide_femenino = preg_match('/\b(FEMENIN|DAMA)/u', $texto_escrito_norm);
        $pide_masculino = preg_match('/\b(MASCULIN|CABALLERO)/u', $texto_escrito_norm);
        // Nombre real de cada producto sacado del texto de TODA su pagina
        // (no solo del suyo): en paginas con varios productos juntos el
        // titulo impreso ("3. Billetera masculina Onyx") queda en el
        // texto de un vecino, y el producto en si solo conserva su
        // "CÓD." y sus bolsillos/dimensiones - confirmado con la
        // billetera 243820, que por eso nunca se encontraba buscando
        // "billetera". Los textos van en el orden en que se indexaron
        // (= orden de lectura de la pagina).
        // productos_de() no trae ORDER BY: segun el motor/indice devuelve
        // por codigo y no en orden de indexacion, lo que desordena el
        // emparejamiento titulo<->codigo. Se ordena por id (orden de
        // lectura) antes de armar el texto de cada pagina.
        $todos_ordenados = $todos;
        usort($todos_ordenados, fn($a, $b) => ((int) $a->id) <=> ((int) $b->id));
        $texto_por_pagina = [];
        foreach ($todos_ordenados as $p) {
            $texto_por_pagina[(int) $p->pagina][] = $p->texto_cercano ?? '';
        }
        foreach ($texto_por_pagina as $pag => $textos) {
            $texto_por_pagina[$pag] = implode(' ', $textos);
        }
        $nombres_reales = [];
        foreach ($todos as $p) {
            $nombres_reales[$p->producto_codigo] =
                $this->texto_util->nombre_producto($texto_por_pagina[(int) $p->pagina] ?? '', $p->producto_codigo)
                ?? $this->texto_util->nombre_producto($p->texto_cercano, $p->producto_codigo);
        }
        $candidatos = array_filter($todos, function ($p) use ($captura, $palabras_disparadoras, $pide_femenino, $pide_masculino, $nombres_reales) {
            if ($this->texto_util->texto_mezclado($p->texto_cercano)) {
                return false;
            }
            if (!$palabras_disparadoras) {
                // La descripcion no dejo ninguna palabra util (vacia, o
                // solo conectores/genero): se usa el filtro viejo por
                // seccion amplia de la pagina (funciona bien para
                // fragancias/maquillaje/etc., secciones bastante
                // homogeneas).
                return $this->texto_util->candidato_coincide_categoria($captura['categoria'], $p->seccion);
            }
            // CON palabra(s) disparadora(s): esto reemplaza el filtro
            // por seccion en vez de sumarse a el. La seccion impresa no
            // calza con la categoria elegida en el dropdown (ej. los
            // relojes reales quedan bajo secciones "Hogar...", sin
            // importar si la promotora eligio "Joyería" o "Hogar") -
            // confirmado con casos reales donde elegir "Joyería" o
            // "Hogar" traia productos sin relacion (secadores, tapetes,
            // billeteras) mezclados. Basta con que coincida UNA de las
            // palabras (no todas) - el texto OCR es parcial/ruidoso.
            // Si el producto tiene nombre real (sacado de la pagina), la
            // palabra se busca SOLO en el nombre: el texto propio suele
            // mencionar productos vecinos ("3. Set reloj mas pulsera 4.
            // Set de billetera") y hacia que un reloj saliera al buscar
            // "billetera". Sin nombre, se usa el texto propio.
            $nombre_real_p = $nombres_reales[$p->producto_codigo] ?? null;
            $texto = mb_strtoupper($this->texto_util->sin_acentos(
                $nombre_real_p ?: ($p->texto_cercano ?? '')
            ), 'UTF-8');
            $coincide_alguna = false;
            foreach ($palabras_disparadoras as $palabra) {
                if (preg_match('/\b' . preg_quote($palabra, '/') . '/u', $texto)) {
                    $coincide_alguna = true;
                    break;
                }
            }
            if (!$coincide_alguna) {
                return false;
            }
            // Filtro de genero: si lo escrito pide un genero especifico
            // y el candidato menciona EXPLICITAMENTE el genero
            // contrario, se descarta. Si no menciona genero (unisex o
            // sin dato en el OCR), se deja pasar - mejor mostrar algo
            // sin certeza que ocultar un candidato valido.
            // El genero se revisa en nombre + texto propio: el OCR a veces
            // corta el nombre ("Set de billetera") y el "masculino" queda
            // solo en el texto del producto.
            $texto_genero = mb_strtoupper($this->texto_util->sin_acentos(
                ($nombre_real_p ?? '') . ' ' . ($p->texto_cercano ?? '')
            ), 'UTF-8');
            if ($pide_femenino && preg_match('/\bMASCULIN/u', $texto_genero) && !preg_match('/\bFEMENIN/u', $texto_genero)) {
                return false;
            }
            if ($pide_masculino && preg_match('/\bFEMENIN/u', $texto_genero) && !preg_match('/\bMASCULIN/u', $texto_genero)) {
                return false;
            }
            return true;
        });
        $texto_principal = trim(($captura['categoria'] ?? '') . ' ' . ($captura['descripcion'] ?? ''));
        // Se agrega composicion1/2 al texto secundario (antes solo
        // caracteristicas+detalle) para que la tela que se cargo en la
        // app tambien cuente al buscar - Venta Directa no tiene ficha de
        // atributos estructurados del lado del catalogo (todo es texto
        // OCR cercano al codigo), asi que la unica forma de que la
        // composicion "cuente" es buscar sus palabras (poliester,
        // algodon, spandex...) como cualquier otra palabra clave.
        $texto_secundario = trim(($captura['caracteristicas'] ?? '') . ' ' . ($captura['detalle'] ?? '')
            . ' ' . ($captura['composicion1'] ?? '') . ' ' . ($captura['composicion2'] ?? ''));

        $sugerencias = [];
        foreach ($candidatos as $p) {
            // Texto propio + nombre real de la pagina: si el titulo esta
            // impreso en el texto de un vecino, el score de texto del
            // producto daba 0 y el filtro "score > 0" lo descartaba
            // (confirmado con la billetera Onyx 243820).
            $score = round($this->texto_util->score_texto(
                $texto_principal, $texto_secundario,
                ($p->texto_cercano ?? '') . ' ' . ($nombres_reales[$p->producto_codigo] ?? '')
            ) * 100, 1);
            $sugerencias[] = [
                'sku' => $p->producto_codigo,
                'categoria' => $captura['categoria'],
                // Nombre real impreso en el catalogo ("Bolso femenino
                // Loriet - Ref. 838390"); si no se puede sacar, cae a la
                // lista de categorias conocidas ("Producto" si ni eso).
                'descripcion' => ($nombre_real = $nombres_reales[$p->producto_codigo] ?? null)
                    ? "{$nombre_real} - Ref. {$p->producto_codigo}"
                    : $this->texto_util->descripcion_minima($p->texto_cercano, $p->producto_codigo),
                'color' => null,
                'composicion' => null,
                'silueta' => null,
                'manga' => null,
                'precio' => $p->precio ?: 0,
                'pagina_catalogo' => $this->m_catalogo->pagina_impresa($catalogo->id, (int) $p->pagina),
                // Se volvio a mostrar la foto (recorte automatico de la
                // pagina) a pedido explicito - a veces no muestra la
                // prenda exacta (cara/piernas/precio en vez de la
                // prenda), pero se prefiere mostrar algo antes que nada.
                'foto_url' => $foto_pagina_url_fn($catalogo->id, $p->pagina, $p->producto_codigo),
                'foto_grande_url' => $foto_pagina_completa_url_fn($catalogo->id, $p->pagina),
                'score_similitud' => $score,
                '_coincide_nombre' => $this->nombre_coincide($nombres_reales[$p->producto_codigo] ?? null, $palabras_disparadoras),
                '_ruta_local' => RUTA_ARCHIVOS . 'captura_v1/catalogo_paginas/' . "{$catalogo->id}_{$p->pagina}_completa.png",
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
        $sugerencias = $this->quitar_ruta_local($this->aplicar_comparacion_visual($sugerencias, $captura, $base_url));

        return [
            'captura_id' => $captura['id'],
            'criterio' => "Comparado por palabras clave (categoría, descripción, características, "
                . "detalle y composición) contra el catálogo de Azzorti '{$catalogo->archivo}' "
                . "(campaña {$catalogo->campana}) — no hay ficha de atributos por producto en "
                . 'Venta Directa como sí la hay en Retail, así que la coincidencia es aproximada '
                . '(texto OCR cercano al código de cada producto). La foto es un recorte '
                . 'automático de la página — a veces no muestra la prenda exacta, revisar '
                . 'contra "Pág. N del catálogo" si hay dudas.',
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
                $this->candidatos_moda_azzorti($captura['categoria'], $catalogo_azzorti->id, $captura['descripcion'] ?? null),
                fn($p) => !in_array($p->producto_codigo, $skus_muestra, true)
            ))
            : [];

        // Igual que en candidatos_moda mas abajo: el nombre/descripcion
        // cargado en la app tambien debe contar aca, no solo los 4
        // atributos estructurados (color/silueta/composicion/manga).
        // Se suma como un bono chico (hasta 20 puntos) en vez de pesar
        // igual que en candidatos_moda: aca SI hay atributos
        // estructurados confiables (cargados a mano en el catalogo de
        // muestra), asi que son la base del puntaje: un producto con los
        // 4 atributos exactos ya llega a 100 igual que antes; el nombre
        // sirve para desempatar/mejorar coincidencias PARCIALES de
        // atributos, no para reemplazarlos.
        $texto_principal_muestra = trim(($captura['categoria'] ?? '') . ' ' . ($captura['descripcion'] ?? ''));
        $texto_secundario_muestra = trim(($captura['caracteristicas'] ?? '') . ' ' . ($captura['detalle'] ?? ''));

        $sugerencias_muestra = [];
        foreach ($candidatos as $p) {
            $score_atributos = $this->score_similitud($captura, $p);
            $score_nombre = round($this->texto_util->score_texto($texto_principal_muestra, $texto_secundario_muestra, $p->descripcion ?? '') * 20, 1);
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
                'score_similitud' => round(min(100, $score_atributos + $score_nombre), 1),
                '_ruta_local' => $p->foto_archivo ? RUTA_ARCHIVOS . 'captura_v1/' . $p->foto_archivo : null,
            ];
        }

        // Los productos indexados del PDF (candidatos_moda) no tienen
        // color/silueta/manga estructurados - antes SOLO puntuaban por
        // composicion de tela (score_similitud da como maximo 25 de 100
        // si esos 3 campos quedan vacios), asi que aunque la tela
        // coincidiera perfecto el score nunca pasaba de 25%, y con
        // texto_cercano incompleto (ver fix de indexar_pagina_productos)
        // terminaba mas cerca de 0%. Se agrega un componente de
        // coincidencia por nombre/descripcion (mismo score_texto que ya
        // usa Venta Directa) sobre el texto OCR cercano al codigo, para
        // que un producto que realmente se parece por nombre y tela
        // pueda puntuar mas alto, no solo por tela.
        $texto_principal_moda = trim(($captura['categoria'] ?? '') . ' ' . ($captura['descripcion'] ?? ''));
        $texto_secundario_moda = trim(($captura['caracteristicas'] ?? '') . ' ' . ($captura['detalle'] ?? ''));

        $sugerencias_moda = [];
        foreach ($candidatos_moda as $p) {
            $foto_url = null;
            $foto_grande_url = null;
            if ($catalogo_azzorti) {
                $nombre = "{$catalogo_azzorti->id}_{$p->pagina}_{$p->producto_codigo}.png";
                $foto_url = $this->archivo_util->url_publica('catalogo_paginas/' . $nombre, $base_url);
                // Vista previa grande = pagina completa (no un recorte
                // propio) - ver Catalogos::indexar_productos_post.
                $nombre_completa = "{$catalogo_azzorti->id}_{$p->pagina}_completa.png";
                $foto_grande_url = $this->archivo_util->url_publica('catalogo_paginas/' . $nombre_completa, $base_url);
            }
            $pseudo_producto = ['color' => null, 'silueta' => null, 'composicion' => $p->texto_cercano, 'manga' => null];
            $score_composicion = $this->score_similitud($captura, $pseudo_producto);
            $score_nombre = round($this->texto_util->score_texto($texto_principal_moda, $texto_secundario_moda, $p->texto_cercano ?? '') * 60, 1);
            $sugerencias_moda[] = [
                'sku' => $p->producto_codigo,
                'categoria' => $captura['categoria'],
                // "Ref. R4849" (con R), igual que sale impreso en el
                // catalogo de Retail.
                'descripcion' => preg_replace('/Ref\. (\d+)$/', 'Ref. R$1', $this->texto_util->descripcion_minima($p->texto_cercano, $p->producto_codigo)),
                'color' => null,
                'composicion' => $p->texto_cercano,
                'silueta' => null,
                'manga' => null,
                'precio' => $p->precio ?: 0,
                'pagina_catalogo' => $catalogo_azzorti ? $this->m_catalogo->pagina_impresa($catalogo_azzorti->id, (int) $p->pagina) : $p->pagina,
                // La miniatura (foto_url) sigue siendo el recorte chico
                // por producto - la vista previa grande (foto_grande_url)
                // ahora es la pagina completa, a pedido explicito (el
                // recorte automatico seguia sin mostrar la prenda en
                // casos reales).
                'foto_url' => $foto_url,
                'foto_grande_url' => $foto_grande_url,
                'score_similitud' => round(min(100, $score_nombre + $score_composicion), 1),
                '_ruta_local' => $catalogo_azzorti
                    ? RUTA_ARCHIVOS . 'captura_v1/catalogo_paginas/' . "{$catalogo_azzorti->id}_{$p->pagina}_completa.png"
                    : null,
            ];
        }

        $sugerencias = array_merge($sugerencias_muestra, $sugerencias_moda);
        usort($sugerencias, fn($a, $b) => $b['score_similitud'] <=> $a['score_similitud']);
        $sugerencias = $this->quitar_ruta_local($this->aplicar_comparacion_visual($sugerencias, $captura, $base_url));

        return [
            'captura_id' => $captura['id'],
            'criterio' => 'Filtrado por categoría, combinando el catálogo de muestra '
                . '(azzorti_producto) con productos reales indexados del catálogo PDF '
                . 'de Azzorti (mismo catálogo que usa Venta Directa). Ranking por '
                . 'color, silueta, composición y manga (base del puntaje en la muestra, '
                . 'ya cargados a mano) más coincidencia de nombre/descripción/características '
                . '— nunca por código, ya que la competencia no comparte SKU con Azzorti. Los '
                . 'productos de la muestra traen foto real cargada a mano; los indexados del '
                . 'PDF muestran un recorte automático de la página que a veces no corresponde '
                . 'exactamente a la prenda — revisar contra "Pág. N del catálogo" si hay dudas.',
            'sugerencias' => $sugerencias,
        ];
    }
}
