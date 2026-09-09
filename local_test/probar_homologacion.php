<?php
/**
 * Prueba de punta a punta, local: toma la captura de ejemplo (sembrada
 * por seed.php) y le pide homologacion con Azzorti, igual que hace la
 * app - pero contra la base de datos local en vez del servidor real.
 * Sirve para probar cambios en Texto_util/M_homologacion antes de
 * subirlos, sin arriesgar romper produccion (lo que paso el 8/09).
 *
 * Correr: php probar_homologacion.php
 */
require __DIR__ . '/bootstrap.php';

$capturas = cargar_controlador('Capturas');
$ci = get_instance();

$captura = $ci->m_captura->por_id(1);
if (!$captura) {
    echo "No hay captura de ejemplo - correr primero: php seed.php\n";
    exit(1);
}

echo "Captura de prueba: {$captura->descripcion} ({$captura->categoria}, {$captura->canal})\n";
echo "Composicion: {$captura->composicion1} + {$captura->composicion2}\n\n";

$resultado = $ci->m_homologacion->sugerir_retail($captura, 'http://local.test/');

echo "Criterio: {$resultado['criterio']}\n\n";
foreach ($resultado['sugerencias'] as $s) {
    echo "- {$s['sku']} | {$s['score_similitud']}% | " . mb_substr($s['descripcion'], 0, 70) . "\n";
}
if (!$resultado['sugerencias']) {
    echo "(sin sugerencias)\n";
}
