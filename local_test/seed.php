<?php
/**
 * Crea (si no existen) las tablas locales y carga datos de ejemplo,
 * tomados de los diagnosticos REALES que ya sacamos del catalogo de
 * verdad (pagina 17, 21, etc. del catalogo C10 2026) - asi las pruebas
 * de homologacion usan texto_cercano parecido al real, no datos
 * inventados de la nada.
 *
 * Correr una sola vez (o cuando se quiera reiniciar la base local):
 *   php seed.php
 */
require __DIR__ . '/bootstrap.php';

if (file_exists(DB_SQLITE_PATH)) {
    unlink(DB_SQLITE_PATH);
    echo "Base local anterior borrada, empezando de cero.\n";
}

// Instanciar cualquier controller del modulo alcanza para que su
// constructor (M_schema->asegurar()) cree las 9 tablas.
$catalogos = cargar_controlador('Catalogos');
echo "Tablas creadas.\n";

$ci = get_instance();
$db = $ci->db;

// Un catalogo de Azzorti "indexado" de ejemplo.
$db->query("INSERT INTO cata_comp (comp, camp, arch, fsub) VALUES ('Azzorti', 'C10 2026', 'catalogo_prueba.pdf', '2026-09-01T00:00:00Z')");
$catalogo_id = $db->insert_id();

// 3 productos indexados reales (texto_cercano tomado de los
// diagnosticos de hoy sobre el catalogo real, paginas 17 y 7).
$productos = [
    ['6844', 17, 'lo. Blusa Ref. R6844 Femenina Bss. 20L9. 99\'-º 353581 704670', null, 'MODA'],
    ['6839', 17, 'M 794751 XL 433501 Moda Tela tipo gamuza. - Silueta semiojustodo. 5. Camisa Ref. R6839 Bss. 30L9. ?? BOTONES FUNCIONALES', null, 'MODA'],
    ['1820', 7, 'BOTONES FUNCIONALES CINTURON REMOVIBLE 5. Blusa Ref.R4874 Bs. 179.99. Tejido de punto poliester spandex ocanalado. - Silueta semiajustada.', 179.99, 'MODA'],
];
foreach ($productos as [$codigo, $pagina, $texto, $precio, $seccion]) {
    $precio_sql = $precio === null ? 'NULL' : $precio;
    $texto_esc = str_replace("'", "''", $texto);
    $db->query("INSERT INTO cata_prod (cata_id, pagi, prod_codi, txt_cerc, prec, secc, fcre) VALUES "
        . "({$catalogo_id}, {$pagina}, '{$codigo}', '{$texto_esc}', {$precio_sql}, '{$seccion}', '2026-09-01T00:00:00Z')");
}
echo count($productos) . " productos de catalogo insertados (catalogo_id={$catalogo_id}).\n";

// Una captura de ejemplo (Retail, categoria Blusas Femeninas) - los
// mismos datos que se ven hoy en la Ficha del producto de la app.
$db->query("INSERT INTO capt (comp, cana, camp, cate, nive_prec, dscr, silu, tall, tela1, tela2, mang, colo, prec, fcre) VALUES ("
    . "'Forever', 'Retail', 'C10 2026', 'Blusas Femeninas', 'Bajo', 'blusa esqueleto', 'Entallada', 'S', "
    . "'92% Nylon', '8% Spandex', 'Sin manga', 'Negro', 110, '2026-09-01T00:00:00Z')");
$captura_id = $db->insert_id();
echo "1 captura de ejemplo insertada (captura_id={$captura_id}).\n";

echo "\nListo. Base local en: " . DB_SQLITE_PATH . "\n";
