<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Puerto de los helpers de texto/OCR de server.py (lineas 1330-1420 y
 * 1848-2246): tokenizacion, deteccion de ofertas por palabras clave,
 * anclas de producto por codigo/referencia, y el score de similitud de
 * texto libre usado en la homologacion de Venta Directa.
 *
 * Se carga con $this->load->library('texto_util') y se usa como
 * $this->texto_util->metodo(...).
 *
 * NOTA sobre _REF_RE: en el Python original esta regex se define DOS
 * VECES a nivel de modulo (lineas 1958 y 2146) con patrones distintos.
 * Como Python ejecuta el archivo completo antes de atender ninguna
 * peticion, en tiempo de ejecucion SIEMPRE gana la segunda definicion
 * (REF[.\-]?\s*R?\d{3,}), incluso en el codigo que aparece ANTES en el
 * archivo fuente (_anclas_producto_retail, linea 1961). Aqui se usa una
 * sola constante (REF_RE) con ese valor final para calzar el
 * comportamiento real, no el que "parece" tener el codigo fuente.
 */
class Texto_util {

    // server.py linea 1932.
    const CODIGO_RE = '/^[A-Z]?\d{3,7}$/';

    // server.py linea 2146 (valor que gana en tiempo de ejecucion - ver
    // nota de clase). Se usa tanto en _anclas_producto_retail como en
    // _texto_mezclado. "RE[FI]" (no solo "REF") tolera que el OCR lea
    // "Ref." como "ReÍ." (confusion real f/i en esta fuente, confirmada
    // en produccion) cuando el token queda pegado en uno solo.
    const REF_RE = '/RE[FI][.\-]?\s*R?(\d{3,})/';

    // server.py linea 2041. Dos tolerancias sobre el original, ambas
    // confirmadas en produccion:
    // 1. El separador decimal acepta tambien un espacio ("BS. 349 99"),
    //    no solo "." o "," - el punto decimal de esta fuente se lee
    //    seguido como espacio en blanco.
    // 2. La "B" de "Bs." es opcional ("B?") - el OCR a veces la pierde
    //    del todo y deja solo "s. 349.99". Se exige el "." despues de la
    //    "S" (no solo "S" sola) para no matchear cualquier "S" suelta en
    //    el texto (ej. una talla "S" de una tabla de tamaños).
    const PRECIO_RE = '/B?S\.\s*(\d+)[.,\s](\d{2})/i';

    // server.py linea 2148 ("3. ", "4. " - numeros de item de lista).
    const ITEM_NUM_RE = '/\b([1-9])\.\s/';

    // server.py linea 604.
    const CAMPANA_ESTANDAR_RE = '/^C\d{2} \d{4}$/';

    // server.py linea 1853.
    const UNIDADES_TAMANO = ['ML', 'MI', 'OZ', 'GR', 'G', 'CM', 'KG', 'FL', 'LB', 'MG'];

    // server.py lineas 1902.
    const UMBRAL_SCORE_OFERTA = 0.5;

    // server.py lineas 1835-1845.
    const STOPWORDS_OFERTA = [
        'MAS', 'DEL', 'DE', 'LA', 'EL', 'LOS', 'LAS', 'UN', 'UNA', 'Y', 'O',
        'A', 'AL', 'OFERTA', 'OFERTAS', 'EN', 'CON', 'SIN', 'POR', 'PARA',
        'QUE', 'SE', 'ES', 'SU', 'SUS', 'MUY', 'ESTE', 'ESTA', 'ESTOS',
        'ESTAS', 'LE', 'LES', 'COMO',
    ];

    // server.py linea 1330-1334.
    const FAMILIAS_SILUETA = [
        ['entallada', 'semiajustada', 'ajustada', 'ceñida'],
        ['suelta', 'oversize', 'holgada', 'amplia'],
        ['recta'],
    ];

    // server.py linea 2183 ("GAFAS" -> "LENTES").
    const SINONIMOS_PRODUCTO = ['GAFAS' => 'LENTES'];

    // server.py lineas 2132-2143.
    const CATEGORIA_VD_A_SECCION = [
        'fragancias' => ['FRAGANCIA', 'FRAGANCIAS'],
        'maquillaje' => ['BELLEZA', 'MAQUILLAJE', 'ROSTRO', 'VOGUE'],
        'rostro' => ['BELLEZA', 'MAQUILLAJE', 'ROSTRO', 'VOGUE'],
        'cabello' => ['BELLEZA', 'CABELLO', 'CUIDADO'],
        'cuidado diario' => ['BELLEZA', 'CUIDADO'],
        'joyeria' => ['JOYERIA', 'ACCESORIOS'],
        'hogar' => ['HOGAR'],
    ];

    /** Puerto de _texto (server.py lineas 591-599): solo strings reales,
     * cualquier otra cosa (None, errores de formula tipo #VALUE!) se trata
     * como vacio en vez de reventar la importacion. */
    public function texto($valor) {
        if (!is_string($valor)) {
            return '';
        }
        return trim($valor);
    }

    /** Puerto de _numero (server.py lineas 607-612): solo numeros reales,
     * nunca inventa un 0 para una celda vacia o con error de formula. */
    public function numero($valor) {
        if (is_bool($valor)) {
            return null;
        }
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        return null;
    }

    /** Puerto de _sin_acentos (server.py lineas 1848-1850). */
    public function sin_acentos($texto) {
        $normalizado = Normalizer::normalize((string) $texto, Normalizer::FORM_D);
        return preg_replace('/\p{Mn}/u', '', $normalizado);
    }

    /** Puerto de _limpiar_palabra (server.py lineas 1927-1929): sin
     * acentos, mayusculas, solo alfanumerico. */
    public function limpiar_palabra($palabra) {
        $mayus = mb_strtoupper($this->sin_acentos((string) $palabra), 'UTF-8');
        return preg_replace('/[^A-Z0-9]/', '', $mayus);
    }

    /** Puerto de _tokens_significativos (server.py lineas 1856-1872). */
    public function tokens_significativos($texto) {
        $mayus = mb_strtoupper($this->sin_acentos((string) $texto), 'UTF-8');
        // Cualquier caracter no alfanumerico -> espacio.
        $limpio = preg_replace('/[^A-Z0-9]/u', ' ', $mayus);
        $palabras = array_values(array_filter(preg_split('/\s+/', trim($limpio))));

        $tokens = [];
        $n = count($palabras);
        for ($i = 0; $i < $n; $i++) {
            $palabra = $palabras[$i];
            if (mb_strlen($palabra) < 2) {
                continue;
            }
            if (in_array($palabra, self::STOPWORDS_OFERTA, true)) {
                continue;
            }
            if (ctype_digit($palabra) && $i + 1 < $n && in_array($palabras[$i + 1], self::UNIDADES_TAMANO, true)) {
                continue;
            }
            $tokens[$palabra] = true;
        }
        return array_keys($tokens);
    }

    /** Puerto de _coincide_token (server.py lineas 1875-1888). */
    public function coincide_token(array $ocr_tokens, $ref_token) {
        if (in_array($ref_token, $ocr_tokens, true)) {
            return true;
        }
        if (ctype_digit((string) $ref_token)) {
            $len_ref = strlen((string) $ref_token);
            foreach ($ocr_tokens as $t) {
                if (ctype_digit($t) && strpos($t, (string) $ref_token) === 0 && strlen($t) <= $len_ref + 1) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Puerto de _score_oferta (server.py lineas 1891-1899). */
    public function score_oferta($texto_ocr, $nombre_oferta) {
        $ref_tokens = $this->tokens_significativos($nombre_oferta);
        if (empty($ref_tokens)) {
            return 0.0;
        }
        $ocr_tokens = $this->tokens_significativos($texto_ocr);
        $encontrados = 0;
        foreach ($ref_tokens as $rt) {
            if ($this->coincide_token($ocr_tokens, $rt)) {
                $encontrados++;
            }
        }
        return $encontrados / count($ref_tokens);
    }

    /**
     * Puerto de _mejor_oferta (server.py lineas 1905-1916).
     * @return array{0: ?string, 1: float} [nombre_oferta_o_null, score]
     */
    public function mejor_oferta($texto_ocr, array $ofertas_ref) {
        $mejor_nombre = null;
        $mejor_score = 0.0;
        foreach ($ofertas_ref as $oferta) {
            $score = $this->score_oferta($texto_ocr, $oferta);
            if ($score > $mejor_score) {
                $mejor_score = $score;
                $mejor_nombre = $oferta;
            }
        }
        if ($mejor_score < self::UMBRAL_SCORE_OFERTA) {
            return [null, $mejor_score];
        }
        return [$mejor_nombre, $mejor_score];
    }

    /** Puerto de _tokens_tela (server.py lineas 1341-1343). */
    public function tokens_tela($texto) {
        $palabras = preg_split('/\s+/', trim(str_replace('+', ' ', mb_strtolower((string) $texto, 'UTF-8'))));
        $tokens = [];
        foreach ($palabras as $p) {
            if ($p === '') {
                continue;
            }
            $sin_signos = rtrim($p, '%');
            if (ctype_digit(str_replace('.', '', $sin_signos))) {
                continue;
            }
            $tokens[trim($p, "%.,")] = true;
        }
        return array_keys($tokens);
    }

    /** Puerto de _familia_silueta (server.py lineas 1346-1350). */
    public function familia_silueta($valor) {
        $valor = mb_strtolower(trim((string) $valor), 'UTF-8');
        foreach (self::FAMILIAS_SILUETA as $familia) {
            if (in_array($valor, $familia, true)) {
                return $familia;
            }
        }
        return null;
    }

    /**
     * Puerto de _variantes_palabra (server.py lineas 2183-2201): aplica
     * sinonimo si existe, y genera variantes de plural/genero para CADA
     * variante ya generada (no solo la base).
     */
    public function variantes_palabra($token) {
        $variantes = [$token];
        if (isset(self::SINONIMOS_PRODUCTO[$token])) {
            $variantes[] = self::SINONIMOS_PRODUCTO[$token];
        }
        $resultado = $variantes;
        foreach ($variantes as $v) {
            if (mb_substr($v, -1) === 'S' && mb_strlen($v) >= 4) {
                $resultado[] = mb_substr($v, 0, -1);
            }
            if (in_array(mb_substr($v, -1), ['A', 'O'], true) && mb_strlen($v) >= 5) {
                $resultado[] = mb_substr($v, 0, -1);
            }
        }
        return array_values(array_unique($resultado));
    }

    /** Puerto de _coincide_token_texto_libre (server.py lineas 2203-2208). */
    public function coincide_token_texto_libre(array $ocr_tokens, $ref_token) {
        if ($this->coincide_token($ocr_tokens, $ref_token)) {
            return true;
        }
        $variantes_ref = $this->variantes_palabra($ref_token);
        foreach ($ocr_tokens as $ocr_token) {
            $variantes_ocr = $this->variantes_palabra($ocr_token);
            if (array_intersect($variantes_ref, $variantes_ocr)) {
                return true;
            }
        }
        return false;
    }

    /** Puerto de _score_texto (server.py lineas 2210-2244). */
    public function score_texto($texto_principal, $texto_secundario, $texto_catalogo) {
        $tokens_catalogo = $this->tokens_significativos($texto_catalogo);

        $fraccion = function ($texto) use ($tokens_catalogo) {
            $tokens = $this->tokens_significativos($texto);
            if (empty($tokens)) {
                return null;
            }
            $encontrados = 0;
            foreach ($tokens as $t) {
                if ($this->coincide_token_texto_libre($tokens_catalogo, $t)) {
                    $encontrados++;
                }
            }
            return $encontrados / count($tokens);
        };

        $frac_principal = $fraccion($texto_principal);
        $frac_secundario = $fraccion($texto_secundario);

        if ($frac_principal === null) {
            return $frac_secundario ?? 0.0;
        }
        if ($frac_secundario === null) {
            return $frac_principal;
        }

        $combinado = 0.65 * $frac_principal + 0.35 * $frac_secundario;

        $tokens_secundario = $this->tokens_significativos($texto_secundario);
        if ($frac_secundario == 0.0 && count($tokens_secundario) >= 2) {
            $combinado *= 0.3;
        }
        return $combinado;
    }

    /** Puerto de _anio_de_campana (server.py lineas 1738-1748). */
    public function anio_de_campana($campana) {
        if (preg_match('/(20\d{2})/', (string) $campana, $m)) {
            return (int) $m[1];
        }
        return (int) gmdate('Y');
    }

    /**
     * Puerto de _candidato_coincide_categoria (server.py lineas 2170-2175).
     */
    public function candidato_coincide_categoria($categoria_captura, $seccion_pagina) {
        $categoria_norm = mb_strtolower(trim($this->sin_acentos((string) $categoria_captura)), 'UTF-8');
        if (!isset(self::CATEGORIA_VD_A_SECCION[$categoria_norm]) || $seccion_pagina === null) {
            return true;
        }
        $seccion_norm = mb_strtoupper($this->sin_acentos((string) $seccion_pagina), 'UTF-8');
        foreach (self::CATEGORIA_VD_A_SECCION[$categoria_norm] as $palabra) {
            if (mb_strpos($seccion_norm, $palabra) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Puerto de _anclas_producto (server.py lineas 1935-1955): busca
     * "COD. NNNNN" palabra por palabra en $datos (shape de
     * Ocr_helper::datos_ocr) y devuelve el centro (x,y) de cada codigo.
     */
    public function anclas_producto(array $datos) {
        $palabras = array_map([$this, 'limpiar_palabra'], $datos['text']);
        $anclas = [];
        $n = count($palabras);
        for ($i = 0; $i < $n; $i++) {
            $palabra = $palabras[$i];
            if (mb_strpos($palabra, 'COD') !== 0 || mb_strlen($palabra) > 5) {
                continue;
            }
            for ($j = $i + 1; $j < min($i + 3, $n); $j++) {
                if (preg_match(self::CODIGO_RE, $palabras[$j])) {
                    $anclas[] = [
                        'codigo' => $palabras[$j],
                        'x' => $datos['left'][$j] + $datos['width'][$j] / 2,
                        'y' => $datos['top'][$j] + $datos['height'][$j] / 2,
                    ];
                    break;
                }
            }
        }
        return $anclas;
    }

    /**
     * Puerto de _anclas_producto_retail (server.py lineas 1961-1977):
     * "Nombre Ref.RNNNN". Cubre dos casos:
     * 1. El OCR junta "Ref.R1411" en un solo token al limpiar el punto
     *    (caso original del Python).
     * 2. El OCR separa "Ref." y "R1411" en DOS tokens (kerning ancho o
     *    espacio real en el render, muy comun en texto chico/claro sobre
     *    foto) - sin esto esos casos no se detectaban nunca. Misma
     *    estrategia de dos tokens que ya usa anclas_producto() con
     *    "COD."/codigo. Tolera ademas que el OCR lea "Ref." como "ReÍ."
     *    (confusion real, muy chica esta letra, entre "f" e "i/í" en esta
     *    fuente) - sin esto esos productos ni generaban ancla, y todo su
     *    texto (incluido su propio codigo) terminaba absorbido por el
     *    producto vecino en la asignacion por cercania.
     */
    public function anclas_producto_retail(array $datos) {
        $anclas = [];
        $n = count($datos['text']);
        for ($i = 0; $i < $n; $i++) {
            $limpio = $this->limpiar_palabra($datos['text'][$i]);
            if (preg_match(self::REF_RE, $limpio, $m) && $m[0] === $limpio) {
                $anclas[] = [
                    'codigo' => $m[1],
                    'x' => $datos['left'][$i] + $datos['width'][$i] / 2,
                    'y' => $datos['top'][$i] + $datos['height'][$i] / 2,
                ];
                continue;
            }
            if (preg_match('/^RE[FI]?$/', $limpio)) {
                for ($j = $i + 1; $j < min($i + 3, $n); $j++) {
                    $siguiente = $this->limpiar_palabra($datos['text'][$j]);
                    if (preg_match('/^R?(\d{3,})$/', $siguiente, $m2)) {
                        // Se ancla en la posicion de "Ref." (token $i), no
                        // en la del numero (token $j): el numero suele
                        // quedar en el extremo derecho de la etiqueta del
                        // producto, mas cerca del producto VECINO que del
                        // propio - confirmado en produccion (el nombre del
                        // producto quedaba asignado al vecino de al lado
                        // por la asignacion a la ancla mas cercana).
                        $anclas[] = [
                            'codigo' => $m2[1],
                            'x' => $datos['left'][$i] + $datos['width'][$i] / 2,
                            'y' => $datos['top'][$i] + $datos['height'][$i] / 2,
                        ];
                        break;
                    }
                }
            }
        }
        return $anclas;
    }

    /** Puerto de _ocurrencias_token (server.py lineas 1980-1992). */
    public function ocurrencias_token(array $datos, $token) {
        $ocurrencias = [];
        $n = count($datos['text']);
        for ($i = 0; $i < $n; $i++) {
            $palabra = $this->limpiar_palabra($datos['text'][$i]);
            if ($palabra !== '' && $this->coincide_token([$palabra], $token)) {
                $ocurrencias[] = [
                    'x' => $datos['left'][$i] + $datos['width'][$i] / 2,
                    'y' => $datos['top'][$i] + $datos['height'][$i] / 2,
                ];
            }
        }
        return $ocurrencias;
    }

    /** Puerto de _producto_mas_cercano (server.py lineas 1995-1998). */
    public function producto_mas_cercano(array $anclas, $x, $y) {
        if (!$anclas) {
            return null;
        }
        $mejor = null;
        $mejor_dist = null;
        foreach ($anclas as $a) {
            $dist = ($a['x'] - $x) ** 2 + ($a['y'] - $y) ** 2;
            if ($mejor_dist === null || $dist < $mejor_dist) {
                $mejor_dist = $dist;
                $mejor = $a['codigo'];
            }
        }
        return $mejor;
    }

    /**
     * Puerto de _detectar_ofertas_en_pagina (server.py lineas 2001-2031).
     * $ofertas_ref: array de objetos/arrays con propiedad nombre_oferta.
     */
    public function detectar_ofertas_en_pagina(array $datos, array $ofertas_ref) {
        $anclas = $this->anclas_producto($datos);
        $resultados = [];
        foreach ($ofertas_ref as $ref) {
            $nombre_oferta = is_array($ref) ? $ref['nombre_oferta'] : $ref->nombre_oferta;
            $ref_tokens = $this->tokens_significativos($nombre_oferta);
            if (!$ref_tokens) {
                continue;
            }
            $ocr_tokens = $this->tokens_significativos(implode(' ', $datos['text']));
            $encontrados = array_values(array_filter($ref_tokens, fn($t) => $this->coincide_token($ocr_tokens, $t)));
            $score = count($encontrados) / count($ref_tokens);
            if ($score < self::UMBRAL_SCORE_OFERTA) {
                continue;
            }
            $posiciones = [];
            foreach ($encontrados as $t) {
                foreach ($this->ocurrencias_token($datos, $t) as $p) {
                    $posiciones[] = $p;
                }
            }
            if (!$posiciones) {
                continue;
            }
            if (!$anclas) {
                $resultados[] = ['oferta' => $nombre_oferta, 'score' => $score, 'producto_codigo' => null];
                continue;
            }
            $codigos_vistos = [];
            foreach ($posiciones as $pos) {
                $codigo = $this->producto_mas_cercano($anclas, $pos['x'], $pos['y']);
                if (!in_array($codigo, $codigos_vistos, true)) {
                    $codigos_vistos[] = $codigo;
                    $resultados[] = ['oferta' => $nombre_oferta, 'score' => $score, 'producto_codigo' => $codigo];
                }
            }
        }
        return $resultados;
    }

    /** Puerto de _seccion_de_pagina (server.py lineas 2044-2058). */
    public function seccion_de_pagina(array $datos, $alto_pagina) {
        $umbral_y = $alto_pagina * 0.92;
        $palabras = [];
        $n = count($datos['text']);
        for ($i = 0; $i < $n; $i++) {
            $t = trim($datos['text'][$i]);
            if ($t !== '' && $datos['top'][$i] >= $umbral_y && preg_match('/^\p{L}+$/u', $t) && $t === mb_strtoupper($t, 'UTF-8') && mb_strlen($t) >= 3) {
                $palabras[] = $t;
            }
        }
        return $palabras ? implode(' ', $palabras) : null;
    }

    /**
     * Adaptado de _indexar_pagina_productos (server.py lineas 2061-2123):
     * agrupa el texto OCR de la pagina por producto.
     *
     * El Python original (y la primera version de este port) usaba una
     * ventana rectangular con margenes fijos por ancla, recortada contra
     * los vecinos - pero el recorte solo llega hasta la LINEA del codigo
     * del vecino, no hasta el final real de su bloque de texto (sus
     * propias viñetas/descripcion quedan DESPUES de su codigo, en el
     * hueco entre dos anclas, y terminaban colandose en la ventana del
     * producto siguiente). Confirmado en produccion: paginas con
     * productos muy juntos (varios por fila, o descripciones largas
     * entre codigos) mezclaban texto de 2-3 productos distintos en un
     * solo texto_cercano, perdiendo la palabra clave del producto real
     * (ej. "CROP" de "Crop Top") en el proceso.
     *
     * Reemplazado por asignacion a la ancla MAS CERCANA (particion tipo
     * Voronoi): cada palabra de la pagina se asigna al codigo de
     * producto cuyo centro este mas cerca, reutilizando la misma formula
     * de distancia que ya usa producto_mas_cercano() (detectar-ofertas).
     * Sin ventanas ni margenes que ajustar a mano - separa bien
     * productos apilados, en fila, o cualquier mezcla de los dos en la
     * misma pagina.
     */
    public function indexar_pagina_productos(array $datos, $alto_pagina) {
        $anclas = array_merge($this->anclas_producto($datos), $this->anclas_producto_retail($datos));
        if (!$anclas) {
            return [];
        }
        $seccion = $this->seccion_de_pagina($datos, $alto_pagina);
        $n = count($datos['text']);

        $cercanos_por_codigo = [];
        foreach ($anclas as $a) {
            $cercanos_por_codigo[$a['codigo']] = [];
        }
        for ($i = 0; $i < $n; $i++) {
            $texto = trim($datos['text'][$i]);
            if ($texto === '') {
                continue;
            }
            $wx = $datos['left'][$i] + $datos['width'][$i] / 2;
            $wy = $datos['top'][$i] + $datos['height'][$i] / 2;
            $codigo = $this->producto_mas_cercano($anclas, $wx, $wy);
            $cercanos_por_codigo[$codigo][] = $texto;
        }

        $resultados = [];
        foreach ($anclas as $ancla) {
            $texto_cercano = implode(' ', $cercanos_por_codigo[$ancla['codigo']]);
            $precio = null;
            if (preg_match(self::PRECIO_RE, mb_strtoupper($this->sin_acentos($texto_cercano), 'UTF-8'), $m)) {
                $precio = (float) ($m[1] . '.' . $m[2]);
            }
            $resultados[] = [
                'producto_codigo' => $ancla['codigo'],
                'texto_cercano' => mb_substr($texto_cercano, 0, 500),
                'precio' => $precio,
                'y' => $ancla['y'],
                'seccion' => $seccion,
            ];
        }
        return $resultados;
    }

    /** Puerto de _texto_mezclado (server.py lineas 2150-2167). */
    public function texto_mezclado($texto_cercano) {
        if ($texto_cercano === null) {
            return false;
        }
        preg_match_all(self::REF_RE, $texto_cercano, $m_ref);
        $referencias = array_unique($m_ref[0]);
        preg_match_all(self::PRECIO_RE, $texto_cercano, $m_precio);
        $precios = array_unique($m_precio[0]);
        preg_match_all(self::ITEM_NUM_RE, $texto_cercano, $m_item);
        $numeros_item = array_unique($m_item[1]);

        return count($referencias) > 1 || count($precios) > 2 || count($numeros_item) > 1;
    }
}
