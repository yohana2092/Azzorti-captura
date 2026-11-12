<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Comparacion visual de la foto capturada en campo contra las imagenes
 * candidatas del catalogo (ya acotadas por texto - ver M_homologacion),
 * via el gateway interno de IA de la empresa (gen2-halcon.azzorti.co).
 * No usa una cuenta propia de Anthropic/OpenAI - reutiliza
 * infraestructura ya facturada por la empresa (API entregada por el
 * desarrollador, 2026-10-01).
 *
 * Confirmado a mano contra la API real antes de escribir esto:
 * - Requiere multipart/form-data (un body JSON da "user_id es
 *   obligatorio" aunque el campo venga lleno - lo ignora por completo).
 * - El modelo pedido ("model") debe ser uno de los permitidos
 *   (gpt-5.6, gpt-5.6-luna, gpt-5.6-sol, gpt-5.6-terra) - cualquier otro
 *   nombre (ej. "gpt-4o-mini") lo rechaza con 400.
 * - Las imagenes van en un campo "files" REPETIDO una vez por archivo
 *   (name="files" en cada parte) - NO "files[0]"/"files[1]": con esa
 *   notacion de array la API no reconoce ninguna imagen (confirmado:
 *   "files": [] en la respuesta y el modelo contesta "0" imagenes
 *   recibidas). CURLFile + un array asociativo de PHP no permite 2
 *   entradas con la misma clave, por eso este archivo arma el cuerpo
 *   multipart a mano en vez de usar CURLOPT_POSTFIELDS con un array.
 * - La respuesta del modelo viene como texto plano en "respuesta" (no
 *   hay modo "JSON estricto" en esta API) - se le pide JSON por prompt
 *   y se parsea tolerando que lo envuelva en una cerca ```json.
 */
class Vision_util {

    const ENDPOINT = 'https://gen2-halcon.azzorti.co/mda/prox_clau_arch/openai/files/sync/';
    const MODELO = 'gpt-5.6';
    const NOMBRE_AGENTE = 'azzorti_captura';

    const SYSTEM_PROMPT = 'Eres un asistente que compara la foto de una prenda capturada en campo contra '
        . 'imagenes de paginas de un catalogo de ropa, para encontrar el producto mas parecido '
        . 'VISUALMENTE (silueta, largo, mangas, estilo). El color puede variar entre fotos por luz o '
        . 'calidad de imagen - no lo uses como criterio principal. Respondes SOLO con el JSON pedido, '
        . 'sin texto extra, sin explicacion, sin bloque de codigo markdown.';

    /**
     * $ruta_foto_captura: ruta local (RUTA_ARCHIVOS) a la foto capturada.
     * $candidatos_rutas: array de rutas locales a las imagenes candidatas,
     *   en el mismo orden que se quiere recibir de vuelta (indice 1-based
     *   dentro del resultado = posicion en este array, 1 = primero).
     * $user_id: para auditoria del lado del gateway.
     * $codigos: (opcional) codigo de producto de cada candidato, en el
     *   mismo orden - se le pide al modelo que ubique ESE producto en la
     *   pagina (sale impreso como "COD. nnn" o "Ref. Rnnn").
     *
     * Devuelve un array [indice_1based => ['pct' => 0-100, 'caja' =>
     * [x0, y0, x1, y1] fracciones 0-1 de la pagina, o null]] o null si la
     * llamada fallo por cualquier motivo (red, formato, lo que sea) - el
     * llamador SIEMPRE debe poder seguir funcionando solo con el ranking
     * de texto si esto devuelve null, nunca romper la respuesta por esto.
     * La caja sirve para recortar la miniatura justo sobre el producto
     * (el recorte hecho al indexar, por posicion del texto, suele caer en
     * el fondo o en la modelo) - es aproximada, el llamador la ensancha.
     */
    public function comparar($ruta_foto_captura, array $candidatos_rutas, $user_id, array $codigos = []) {
        if (!$candidatos_rutas || !file_exists($ruta_foto_captura)) {
            return null;
        }
        // Sin extension curl en el servidor: sin comparacion visual, pero
        // la homologacion por texto sigue funcionando (no un error fatal).
        if (!function_exists('curl_init')) {
            return null;
        }

        $lista_codigos = '';
        foreach (array_values($codigos) as $i => $cod) {
            if ($cod !== null && $cod !== '') {
                $lista_codigos .= ' Candidato ' . ($i + 1) . ': codigo ' . $cod . '.';
            }
        }
        $prompt = 'La primera imagen adjunta es la prenda capturada en campo (foto de celular). Las '
            . 'siguientes, en orden, son candidatos de un catalogo (cada una es una pagina completa, '
            . 'puede tener mas de un producto impreso). Para CADA candidato dame un porcentaje de 0 a '
            . '100 de que tan parecida es VISUALMENTE la prenda de ese candidato a la prenda capturada '
            . '(0 = nada que ver, 100 = la misma prenda), y la ubicacion en esa pagina de la FOTO del '
            . 'producto del candidato (la foto del articulo, no su texto ni la modelo entera si se puede '
            . 'evitar), como caja [x0, y0, x1, y1] en fracciones de 0 a 1 del ancho y alto de la pagina '
            . '(0,0 = esquina superior izquierda). Indica tambien para quien es el producto del '
            . 'candidato segun como se ve y lo que dice la pagina: "femenino", "masculino" o "unisex" '
            . '(si no se puede saber, "unisex").'
            . ($lista_codigos !== '' ? ' El producto de cada candidato es el que tiene este codigo impreso '
                . '(COD. o Ref.) en la pagina:' . $lista_codigos : '')
            . ' Responde en JSON con esta forma exacta, sin nada mas: '
            . '{"candidatos":[{"indice":1,"similitud_pct":0,"caja":[0,0,1,1],"genero":"unisex"}]} - "indice" es el numero '
            . 'de orden del candidato (1 = primer candidato adjunto despues de la foto capturada, 2 = '
            . 'segundo, asi sucesivamente). Incluye un objeto por cada candidato que te adjunte.';

        $campos_simples = [
            'user_id' => (string) $user_id,
            'system_prompt' => self::SYSTEM_PROMPT,
            'prompt' => $prompt,
            'model' => self::MODELO,
            'reasoning_effort' => 'low',
            'max_tokens' => '1200',
            'nomb_agen' => self::NOMBRE_AGENTE,
        ];
        $rutas_archivos = array_merge([$ruta_foto_captura], array_values($candidatos_rutas));

        $boundary = '----AzzortiVision' . bin2hex(random_bytes(16));
        $cuerpo = $this->armar_cuerpo_multipart($boundary, $campos_simples, $rutas_archivos);
        if ($cuerpo === null) {
            return null;
        }

        $curl = curl_init(self::ENDPOINT);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data; boundary=' . $boundary],
            CURLOPT_POSTFIELDS => $cuerpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ]);
        $respuesta_cruda = curl_exec($curl);
        $fallo_curl = curl_errno($curl) !== 0;
        curl_close($curl);
        if ($fallo_curl || $respuesta_cruda === false) {
            return null;
        }

        $data = json_decode($respuesta_cruda, true);
        if (!is_array($data) || empty($data['ok']) || !isset($data['respuesta'])) {
            return null;
        }
        $texto = trim((string) $data['respuesta']);
        $texto = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $texto);
        $parsed = json_decode($texto, true);
        if (!is_array($parsed) || empty($parsed['candidatos']) || !is_array($parsed['candidatos'])) {
            return null;
        }

        $resultado = [];
        foreach ($parsed['candidatos'] as $c) {
            if (is_array($c) && isset($c['indice'], $c['similitud_pct'])) {
                $resultado[(int) $c['indice']] = [
                    'pct' => max(0, min(100, (float) $c['similitud_pct'])),
                    'caja' => $this->caja_valida($c['caja'] ?? null),
                    'genero' => in_array($c['genero'] ?? null, ['femenino', 'masculino'], true) ? $c['genero'] : 'unisex',
                ];
            }
        }
        return $resultado ?: null;
    }

    /**
     * Caja [x0, y0, x1, y1] en fracciones 0-1, o null si no sirve. Acepta
     * tambien porcentajes 0-100 (el modelo a veces los devuelve asi).
     */
    private function caja_valida($caja) {
        if (!is_array($caja) || count($caja) !== 4) {
            return null;
        }
        $v = array_map('floatval', array_values($caja));
        if (max($v) > 1.5) {
            $v = array_map(fn($n) => $n / 100, $v);
        }
        $v = array_map(fn($n) => max(0, min(1, $n)), $v);
        [$x0, $y0, $x1, $y1] = $v;
        if ($x1 - $x0 < 0.03 || $y1 - $y0 < 0.03) {
            return null;
        }
        return [$x0, $y0, $x1, $y1];
    }

    /**
     * Arma el body multipart/form-data a mano para poder repetir el
     * campo "files" una vez por archivo (ver nota de clase - con
     * CURLOPT_POSTFIELDS como array asociativo de PHP no se puede
     * repetir una misma clave).
     */
    private function armar_cuerpo_multipart($boundary, array $campos_simples, array $rutas_archivos) {
        $partes = [];
        foreach ($campos_simples as $nombre => $valor) {
            $partes[] = "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"{$nombre}\"\r\n\r\n"
                . "{$valor}\r\n";
        }
        foreach ($rutas_archivos as $i => $ruta) {
            $contenido = @file_get_contents($ruta);
            if ($contenido === false) {
                return null;
            }
            $tipo_mime = @mime_content_type($ruta) ?: 'application/octet-stream';
            $nombre_archivo = 'imagen_' . $i . '.' . (pathinfo($ruta, PATHINFO_EXTENSION) ?: 'bin');
            $partes[] = "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"files\"; filename=\"{$nombre_archivo}\"\r\n"
                . "Content-Type: {$tipo_mime}\r\n\r\n"
                . $contenido . "\r\n";
        }
        $partes[] = "--{$boundary}--\r\n";
        return implode('', $partes);
    }
}
