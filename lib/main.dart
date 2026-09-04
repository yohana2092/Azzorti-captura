import 'dart:convert';
import 'dart:io';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:google_mlkit_image_labeling/google_mlkit_image_labeling.dart';
import 'package:image/image.dart' as img;

// ====================== SINCRONIZACIÓN CON EL BACKEND REAL ======================
// Reemplaza el prototipo original, que escribía un archivo capturas.json en
// un repositorio público de GitHub usando un token embebido en el código
// (hallazgo de seguridad crítico — el token quedaba expuesto en el APK y en
// el historial de git). Esto llama a un backend real (ver /backend/server.py)
// que persiste en base de datos y valida duplicados de verdad.
//
// Backend real: modulo captura_v1 (PHP), desplegado dentro de la
// instalacion hmvc/ del servidor real (ver
// C:\Trabajo\azzorti-captura-backend-deploy\php\README.md para el detalle
// completo del despliegue). Reemplaza el tunel de Cloudflare que apuntaba
// al prototipo Python en una laptop (URL temporal, se caia con cada
// reinicio de cloudflared).
const String _backendBaseUrl = 'https://servicioweb2bol.azzorti.co/hmvc/captura_v1';

class ResultadoSync {
  final bool ok;
  final bool duplicado;
  final String mensaje;
  const ResultadoSync(
      {required this.ok, required this.duplicado, required this.mensaje});
}

/// Envía el registro al backend real. Best-effort frente a fallas de red
/// (sin señal, backend apagado): el registro sigue guardado en el celular
/// y se puede reintentar. Si el backend responde 409, es porque ya existe
/// un registro de ese competidor + SKU en esa campaña (regla de negocio
/// crítica del REQ) — se lo hace explícito al usuario, no se descarta en
/// silencio.
Future<ResultadoSync> sincronizarConBackend(Captura c) async {
  final url = Uri.parse('$_backendBaseUrl/capturas');
  try {
    final resp = await http
        .post(
          url,
          headers: {'Content-Type': 'application/json'},
          body: jsonEncode({
            'competidor': c.competidor,
            'canal': c.canal,
            'campana': c.campana,
            'categoria': c.categoria,
            'nivel_precio': c.puntoPrecio,
            'descripcion': c.descripcionProducto,
            'silueta': c.silueta,
            'talla': c.talla.isEmpty ? null : c.talla,
            'composicion1': c.composicion1,
            'composicion2': c.composicion2,
            'manga': c.manga,
            'color': c.colorPrenda,
            'detalle': c.detalle,
            'caracteristicas': c.caracteristicas,
            'precio': double.tryParse(c.precioFinal) ?? 0,
            'foto_producto_b64':
                c.fotoProducto != null ? base64Encode(c.fotoProducto!) : null,
          }),
        )
        // Antes eran 8s: muy poco para subir una foto por el tunel/red
        // movil. La app avisaba "no se pudo conectar" aunque el backend
        // si terminara guardando la captura, y el analista repetia la
        // captura completa creyendo que habia fallado - eso duplicaba el
        // registro (el backend ya tiene ademas su propio bloqueo de
        // duplicados por las dudas).
        .timeout(const Duration(seconds: 40));

    if (resp.statusCode == 201) {
      final data = jsonDecode(resp.body);
      c.backendId = data['id'] as int?;
      return const ResultadoSync(
          ok: true, duplicado: false, mensaje: 'Sincronizado con el backend.');
    }
    if (resp.statusCode == 409) {
      final data = jsonDecode(resp.body);
      return ResultadoSync(
          ok: false,
          duplicado: true,
          mensaje: data['detail'] ?? 'Registro duplicado detectado.');
    }
    return ResultadoSync(
        ok: false,
        duplicado: false,
        mensaje: 'El backend respondió ${resp.statusCode}.');
  } catch (_) {
    return const ResultadoSync(
        ok: false,
        duplicado: false,
        mensaje:
            'No se pudo conectar al backend (revisa la señal o que el backend esté corriendo).');
  }
}

/// Pide al backend las sugerencias de homologación (por categoría + nivel
/// de precio + similitud de atributos — nunca por código, ver memoria del
/// proyecto sobre homologación sin SKU compartido).
Future<List<Map<String, dynamic>>> pedirSugerenciasHomologacion(
    int backendId) async {
  final url = Uri.parse(
      '$_backendBaseUrl/capturas/homologacion_sugerencias/$backendId');
  try {
    final resp = await http.get(url).timeout(const Duration(seconds: 20));
    if (resp.statusCode != 200) return [];
    final data = jsonDecode(resp.body);
    return List<Map<String, dynamic>>.from(data['sugerencias'] as List);
  } catch (_) {
    return [];
  }
}

Future<bool> confirmarHomologacion(int backendId, String azzortiSku) async {
  final url = Uri.parse(
      '$_backendBaseUrl/capturas/homologacion_confirmar/$backendId');
  try {
    final resp = await http
        .post(url,
            headers: {'Content-Type': 'application/json'},
            body: jsonEncode({'azzorti_sku': azzortiSku}))
        .timeout(const Duration(seconds: 20));
    return resp.statusCode == 200;
  } catch (_) {
    return false;
  }
}

Future<Map<String, dynamic>?> pedirEvaluacion(int backendId) async {
  final url = Uri.parse('$_backendBaseUrl/capturas/evaluacion/$backendId');
  try {
    final resp = await http.get(url).timeout(const Duration(seconds: 20));
    if (resp.statusCode != 200) return null;
    return jsonDecode(resp.body) as Map<String, dynamic>;
  } catch (_) {
    return null;
  }
}

void main() => runApp(const AzzortiApp());

// ====================== COLORES ======================
class AppColors {
  // Colores de marca Azzorti (negro + amarillo del logo) — antes era un
  // azul/navy genérico de prototipo, sin relación con la identidad real.
  static const navy = Color(0xFF000000);
  static const ink = Color(0xFF0F1C33);
  static const blue = Color(0xFFF6C500);
  static const paper = Color(0xFFF6F7FA);
  static const muted = Color(0xFF64748B);
  static const line = Color(0xFFE2E8F0);
  static const green = Color(0xFF16A34A);
  static const greenBg = Color(0xFFDCFCE7);
  static const amberTxt = Color(0xFF8A5A06);
  static const amberBg = Color(0xFFFEF3C7);
  static const visor = Color(0xFF0B1526);
}

// ====================== CÁMARA ======================
final ImagePicker _picker = ImagePicker();

Future<Uint8List?> tomarFotoReal(BuildContext context) async {
  try {
    final XFile? foto = await _picker.pickImage(
      source: ImageSource.camera,
      maxWidth: 1600,
      imageQuality: 85,
    );
    if (foto == null) return null; // la usuaria canceló
    return await foto.readAsBytes();
  } catch (e) {
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text(
              'No se pudo abrir la cámara. Revisa el permiso de cámara de la app.')));
    }
    return null;
  }
}

// ====================== LECTURA AUTOMÁTICA (Gap G4 + G5) ======================

/// Corrige la orientación real de la foto según el EXIF (si venía rotada por
/// cómo se sostuvo el celular) y la guarda a un archivo temporal ya derecha,
/// que es lo que de verdad necesitan el OCR y el detector de imágenes.
Future<String> _guardarBytesTempEnderezada(
    Uint8List bytes, String nombre) async {
  final dir = await getTemporaryDirectory();
  final file = File('${dir.path}/$nombre');
  try {
    final decodificada = img.decodeImage(bytes);
    if (decodificada != null) {
      final derecha = img.bakeOrientation(decodificada);
      await file.writeAsBytes(img.encodeJpg(derecha, quality: 90));
      return file.path;
    }
  } catch (_) {
    // Si algo falla enderezando, seguimos con la foto tal cual llegó.
  }
  await file.writeAsBytes(bytes);
  return file.path;
}

/// Lee la foto de la etiqueta con OCR (ML Kit, en el propio celular, sin costo)
/// y separa hasta 2 componentes de composición. El color YA NO se busca aquí
/// como texto (casi ninguna etiqueta real imprime la palabra del color) —
/// el color se sugiere por separado analizando los píxeles de la foto del
/// producto, ver sugerirDesdeProducto().
/// Si algo falla o no hay coincidencias, devuelve campos vacíos: el usuario
/// llena a mano, nunca se inventa un dato.
Future<Map<String, String>> leerEtiqueta(Uint8List bytes) async {
  final resultado = {'componente1': '', 'componente2': ''};
  try {
    final path = await _guardarBytesTempEnderezada(
        bytes, 'etiqueta_${DateTime.now().millisecondsSinceEpoch}.jpg');
    final recognizer = TextRecognizer(script: TextRecognitionScript.latin);
    final recognizedText =
        await recognizer.processImage(InputImage.fromFilePath(path));
    await recognizer.close();
    final texto = recognizedText.text;

    // Diccionario de telas conocidas (para emparejar con el % más cercano,
    // en vez de exigir que estén pegados en la misma línea — el OCR de
    // etiquetas angostas suele partir el texto en varias líneas cortas).
    // Muchas prendas importadas traen la etiqueta en inglés (ej.
    // "Composition 95% Polyester 5% Elastane") — antes solo se reconocían
    // los nombres en español, así que esas etiquetas no arrojaban nada.
    // El valor del mapa es el nombre canónico que se muestra siempre en
    // español, sin importar en qué idioma estaba impresa la etiqueta.
    const telas = <String, String>{
      'poliester': 'poliéster', 'poliéster': 'poliéster',
      'polyester': 'poliéster',
      'algodon': 'algodón', 'algodón': 'algodón', 'cotton': 'algodón',
      'spandex': 'spandex', 'elastano': 'elastano', 'elastán': 'elastano',
      'elastane': 'elastano', 'elastic': 'elastano',
      'lycra': 'lycra', 'licra': 'lycra',
      'nylon': 'nylon', 'nailon': 'nylon', 'polyamide': 'poliamida',
      'poliamida': 'poliamida',
      'lino': 'lino', 'linen': 'lino',
      'modal': 'modal',
      'rayon': 'rayón', 'rayón': 'rayón', 'viscosa': 'viscosa',
      'viscose': 'viscosa',
      'lana': 'lana', 'wool': 'lana',
      'seda': 'seda', 'silk': 'seda',
      'acrilico': 'acrílico', 'acrílico': 'acrílico', 'acrylic': 'acrílico',
    };

    // Paso 1: todos los porcentajes con su posición en el texto.
    final regexPorcentaje = RegExp(r'(\d{1,3})\s*%');
    final porcentajes = regexPorcentaje.allMatches(texto).toList();

    // Paso 2: todas las telas conocidas con su posición en el texto.
    final textoMin = texto.toLowerCase();
    final encontrados = <MapEntry<int, String>>[];
    for (final t in telas.keys) {
      var desde = 0;
      while (true) {
        final i = textoMin.indexOf(t, desde);
        if (i == -1) break;
        encontrados.add(MapEntry(i, t));
        desde = i + t.length;
      }
    }
    encontrados.sort((a, b) => a.key.compareTo(b.key));

    // Paso 3: empareja cada porcentaje con la tela más cercana, en
    // CUALQUIER dirección (antes solo se buscaba la tela después del
    // porcentaje). Una etiqueta angosta cosida en una costura curva (como
    // el cuello de un crop top) puede hacer que ML Kit entregue los
    // bloques de texto en un orden distinto al que se lee a simple vista
    // — bug real visto en pruebas: "95% polyester / 5% spandex" solo
    // arrojaba el primer componente, el segundo quedaba vacío. El
    // emparejamiento ahora es "greedy": se toma primero el par
    // (porcentaje, tela) más cercano de todos los posibles, se marcan
    // ambos como usados, y se repite — así una tela ya no se descarta
    // solo porque quedó "antes" del porcentaje en el texto reconocido.
    // Un 0% no es una composición real — el OCR a veces confunde "1" con
    // "0" en etiquetas borrosas o con poca luz (ej. "100%" leído "000%").
    final porcentajesValidos = porcentajes
        .where((p) => (int.tryParse(p.group(1) ?? '') ?? 0) != 0)
        .toList();

    // Pase 1: igual que antes, solo tela HACIA ADELANTE (formato real más
    // común: "95% Poliéster"), de forma greedy para que un porcentaje no
    // le quite a otro la tela que realmente le corresponde.
    final candidatosAdelante = <(int, int, int)>[];
    for (var i = 0; i < porcentajesValidos.length; i++) {
      final p = porcentajesValidos[i];
      for (var j = 0; j < encontrados.length; j++) {
        final distancia = encontrados[j].key - p.end;
        if (distancia >= -2) candidatosAdelante.add((i, j, distancia));
      }
    }
    candidatosAdelante.sort((a, b) => a.$3.compareTo(b.$3));

    final pctUsado = <int>{};
    final telaUsada = <int>{};
    final parePorPct = <int, String>{};
    for (final c in candidatosAdelante) {
      final (i, j, _) = c;
      if (pctUsado.contains(i) || telaUsada.contains(j)) continue;
      pctUsado.add(i);
      telaUsada.add(j);
      final p = porcentajesValidos[i];
      final tela = encontrados[j].value;
      parePorPct[i] = '${p.group(1)}% ${_capitalizar(telas[tela]!)}';
    }

    // Pase 2 (respaldo): para el/los porcentaje(s) que se quedaron sin
    // tela adelante, se busca la tela restante más cercana en CUALQUIER
    // dirección. Cubre el caso real visto en pruebas: una etiqueta angosta
    // cosida en una costura curva hizo que el OCR entregara "spandex" en
    // una posición del texto que ya no calificaba como "adelante" del
    // "5%" — antes eso dejaba el segundo componente vacío por completo.
    if (pctUsado.length < porcentajesValidos.length) {
      final candidatosRespaldo = <(int, int, int)>[];
      for (var i = 0; i < porcentajesValidos.length; i++) {
        if (pctUsado.contains(i)) continue;
        final p = porcentajesValidos[i];
        for (var j = 0; j < encontrados.length; j++) {
          if (telaUsada.contains(j)) continue;
          candidatosRespaldo.add((i, j, (encontrados[j].key - p.end).abs()));
        }
      }
      candidatosRespaldo.sort((a, b) => a.$3.compareTo(b.$3));
      for (final c in candidatosRespaldo) {
        final (i, j, _) = c;
        if (pctUsado.contains(i) || telaUsada.contains(j)) continue;
        pctUsado.add(i);
        telaUsada.add(j);
        final p = porcentajesValidos[i];
        final tela = encontrados[j].value;
        parePorPct[i] = '${p.group(1)}% ${_capitalizar(telas[tela]!)}';
      }
    }
    // Se mantienen en el orden en que aparecen los porcentajes en el
    // texto (no en el orden en que se emparejaron), igual que antes.
    final pares = [
      for (var i = 0; i < porcentajesValidos.length; i++)
        if (parePorPct.containsKey(i)) parePorPct[i]!
    ];

    if (pares.isNotEmpty) resultado['componente1'] = pares[0];
    if (pares.length > 1) resultado['componente2'] = pares[1];
  } catch (_) {
    // Si la foto sale ilegible o algo falla, no se autocompleta nada.
  }
  return resultado;
}

/// Analiza la foto del producto (ya enderezada) de dos formas distintas:
/// 1) Color dominante por píxeles (confiable, siempre se puede calcular).
/// 2) Manga, con el modelo genérico de ML Kit — SOLO si hay una coincidencia
///    clara y específica. Ya NO se sugiere Silueta: el modelo genérico no
///    tiene forma real de distinguir "oversize" de "entallada", así que
///    inventar esa sugerencia era engañoso. Silueta queda siempre manual.
Future<Map<String, String>> sugerirDesdeProducto(Uint8List bytes) async {
  final resultado = {'manga': '', 'color': ''};
  try {
    final path = await _guardarBytesTempEnderezada(
        bytes, 'producto_${DateTime.now().millisecondsSinceEpoch}.jpg');

    // --- Color dominante por píxeles ---
    final decodificada = img.decodeImage(await File(path).readAsBytes());
    if (decodificada != null) {
      resultado['color'] = _colorDominante(decodificada);
    }

    // --- Manga, solo si el modelo genérico da una pista clara ---
    final labeler =
        ImageLabeler(options: ImageLabelerOptions(confidenceThreshold: 0.65));
    final labels = await labeler.processImage(InputImage.fromFilePath(path));
    await labeler.close();
    final textos = labels.map((l) => l.label.toLowerCase()).toList();
    for (final t in textos) {
      if (t.contains('long sleeve')) resultado['manga'] = 'Manga larga';
      if (t.contains('short sleeve')) resultado['manga'] = 'Manga corta';
      if (t.contains('sleeveless') || t.contains('tank')) {
        resultado['manga'] = 'Sin manga';
      }
    }
  } catch (_) {
    // Si algo falla, se deja vacío para elección manual.
  }
  return resultado;
}

/// Detecta si un píxel es muy probablemente piel humana (heurística RGB
/// estándar). Las fotos de campo casi siempre incluyen brazos/cuello/cara
/// de quien modela la prenda — sin excluir esto, el promedio de color
/// terminaba mezclado con tono de piel en vez de reflejar la prenda.
bool _esPiel(int r, int g, int b) {
  final maxC = [r, g, b].reduce((a, v) => a > v ? a : v);
  final minC = [r, g, b].reduce((a, v) => a < v ? a : v);
  return r > 95 &&
      g > 40 &&
      b > 20 &&
      (maxC - minC) > 15 &&
      (r - g).abs() > 15 &&
      r > g &&
      r > b;
}

/// Convierte RGB (0-255) a HSV — matiz en grados (0-360), saturación y
/// valor en 0-1. Se usa para separar "de qué color es" (matiz) de "qué tan
/// oscuro/clarito se ve por la sombra o la luz" (valor) — una tela roja en
/// una zona de sombra sigue siendo roja, aunque su RGB absoluto (más
/// oscuro) quede numéricamente parecido a un café o negro.
(double, double, double) _rgbAHsv(int r255, int g255, int b255) {
  final r = r255 / 255, g = g255 / 255, b = b255 / 255;
  final maxC = [r, g, b].reduce((a, v) => a > v ? a : v);
  final minC = [r, g, b].reduce((a, v) => a < v ? a : v);
  final delta = maxC - minC;
  final v = maxC;
  final s = maxC == 0 ? 0.0 : delta / maxC;
  double h;
  if (delta == 0) {
    h = 0;
  } else if (maxC == r) {
    h = 60 * (((g - b) / delta) % 6);
  } else if (maxC == g) {
    h = 60 * (((b - r) / delta) + 2);
  } else {
    h = 60 * (((r - g) / delta) + 4);
  }
  if (h < 0) h += 360;
  return (h, s, v);
}

/// Color dominante de la prenda: clasifica CADA píxel muestreado por su
/// matiz (HSV) y se queda con el que gana más votos — no promedia primero
/// (eso difumina estampados) y no compara distancia RGB completa (eso
/// confunde brillo con color: una tela roja en la sombra puede quedar
/// numéricamente más cerca de "Café" que de "Rojo" si se compara en RGB,
/// aunque el matiz real siga siendo rojo).
///
/// Un píxel se considera "acromático" (negro/gris/blanco, sin matiz) según
/// su saturación — exigiendo más saturación cuanto más oscuro es el píxel,
/// porque a baja luminosidad el matiz calculado se vuelve ruido (una sombra
/// oscura puede leer con un matiz azulado sin serlo de verdad). El resto se
/// agrupa por familia de matiz, y dentro de cada familia se elige la
/// variante clara/oscura según el brillo.
String _colorDominante(img.Image imagen) {
  // Devuelve el nombre del color y si es "cromático" (con matiz real) o
  // acromático (negro/gris/blanco, decidido solo por brillo).
  (String, bool) clasificar(int r, int g, int b) {
    final (h, s, v) = _rgbAHsv(r, g, b);
    final umbralSaturacion = v >= 0.35 ? 0.18 : 0.30;
    if (s < umbralSaturacion) {
      if (v < 0.40) return ('Negro', false);
      if (v < 0.78) return ('Gris', false);
      return ('Blanco', false);
    }
    if (h < 12 || h >= 350) return (v < 0.55 ? 'Vino' : 'Rojo', true);
    if (h < 40) {
      if (v < 0.35) return ('Chocolate', true);
      if (v < 0.55) return ('Café', true);
      return (s > 0.45 ? 'Naranja' : 'Beige', true);
    }
    if (h < 65) {
      if (v < 0.55) return ('Mostaza', true);
      return (s > 0.35 ? 'Amarillo' : 'Crema', true);
    }
    if (h < 95) return (v < 0.55 ? 'Oliva' : 'Verde claro', true);
    if (h < 160) return ('Verde', true);
    if (h < 195) return ('Turquesa', true);
    if (h < 255) return (v > 0.6 && s < 0.45 ? 'Celeste' : 'Azul', true);
    if (h < 290) return (v > 0.65 && s < 0.45 ? 'Lavanda' : 'Morado', true);
    if (h < 330) return ('Fucsia', true);
    return (v > 0.55 ? 'Rosado' : 'Vino', true);
  }

  final w = imagen.width;
  final h = imagen.height;
  final cx0 = (w * 0.15).round();
  final cx1 = (w * 0.85).round();
  final cy0 = (h * 0.20).round();
  final cy1 = (h * 0.92).round();

  // _esPiel es una heurística de tono cálido/rojizo - una prenda color
  // café/durazno/beige (ej. una chaqueta camel) cae en el MISMO rango que
  // un brazo o cuello real, así que antes se excluía la prenda entera del
  // conteo, dejando solo el fondo para decidir el color (bug real: una
  // chaqueta durazno terminaba sugiriendo "Azul" porque el fondo era lo
  // único que quedaba votando). Se mide primero qué proporción del
  // recorte calificaría como "piel" - si es la mayoría, es mucho más
  // probable que sea tela color piel/durazno/beige que brazos/cuello
  // reales (una foto de producto normal no muestra tanta piel), así que
  // en ese caso no se excluye nada.
  var totalMuestra = 0, totalPiel = 0;
  for (var y = cy0; y < cy1; y += 3) {
    for (var x = cx0; x < cx1; x += 3) {
      final p = imagen.getPixel(x, y);
      totalMuestra++;
      if (_esPiel(p.r.toInt(), p.g.toInt(), p.b.toInt())) totalPiel++;
    }
  }
  final excluirPiel = totalMuestra > 0 && (totalPiel / totalMuestra) <= 0.5;

  // Cada píxel vota con más peso cuanto más cerca esté del CENTRO del
  // recorte — las fotos de producto casi siempre centran la prenda, así
  // que el fondo (que tiende a asomar en las esquinas/bordes, o por los
  // huecos de un crop top/escote) pesa menos sin necesidad de adivinar si
  // es cromático o acromático. Antes de esto, un fondo de color vivo
  // (ej. una puerta rojiza) que ocupara una porción considerable del
  // recorte le ganaba a una prenda NEGRA real, porque la regla anterior
  // priorizaba a ciegas cualquier color con matiz por encima de negro/
  // gris/blanco (bug real: top negro sobre puerta rojiza sugería "Vino").
  final ccx = (cx0 + cx1) / 2, ccy = (cy0 + cy1) / 2;
  final maxDist = math.sqrt(math.pow(cx1 - ccx, 2) + math.pow(cy1 - ccy, 2));

  final votos = <String, double>{};
  final votosCromaticos = <String, double>{};
  var total = 0.0;
  var totalCromatico = 0.0;

  for (var y = cy0; y < cy1; y += 3) {
    for (var x = cx0; x < cx1; x += 3) {
      final p = imagen.getPixel(x, y);
      final r = p.r.toInt(), g = p.g.toInt(), b = p.b.toInt();
      if (excluirPiel && _esPiel(r, g, b)) continue;
      final dist = math.sqrt(math.pow(x - ccx, 2) + math.pow(y - ccy, 2));
      final peso = maxDist > 0 ? 1.0 - 0.6 * (dist / maxDist).clamp(0.0, 1.0) : 1.0;
      final (color, esCromatico) = clasificar(r, g, b);
      votos[color] = (votos[color] ?? 0) + peso;
      total += peso;
      if (esCromatico) {
        votosCromaticos[color] = (votosCromaticos[color] ?? 0) + peso;
        totalCromatico += peso;
      }
    }
  }
  if (votos.isEmpty) return '';

  final ganadorGeneral = votos.entries.reduce((a, b) => a.value >= b.value ? a : b);

  // Un fondo GRIS/BLANCO parejo (mesa, silla, pared) puede acumular más
  // peso que la prenda si esta no llena todo el encuadre — pero si hay una
  // porción real de píxeles con color de verdad, esa prenda con color es
  // casi siempre el sujeto de la foto, no el fondo. Esto NO aplica cuando
  // el ganador general ya es NEGRO: a diferencia de gris/blanco, el negro
  // es un color de prenda real y frecuente, no solo un color de fondo -
  // preferir a ciegas cualquier matiz de fondo por encima de un negro que
  // ya ganó por mayoría clara es justo el bug real que se vio en pruebas
  // (top negro sobre una puerta rojiza sugería "Vino", con Negro ganando
  // 4 a 1 en votos reales).
  if (ganadorGeneral.key != 'Negro' && totalCromatico / total >= 0.15) {
    return votosCromaticos.entries.reduce((a, b) => a.value >= b.value ? a : b).key;
  }
  return ganadorGeneral.key;
}

String _capitalizar(String s) =>
    s.isEmpty ? s : '${s[0].toUpperCase()}${s.substring(1).toLowerCase()}';

// ====================== MODELO ======================
enum Estado { borrador, porSincronizar, sincronizada }

class Captura {
  Uint8List? fotoEtiqueta; // bytes de la foto real (null = omitida)
  Uint8List? fotoProducto;
  String competidor;
  String precioTienda; // precio opcional digitado en tienda (Momento 1)
  final DateTime creada;

  // Datos que se completan en el Momento 2:
  String canal;
  String campana;
  String categoria;
  String puntoPrecio;
  String descripcionProducto; // nombre corto del producto, ej. "Blusa floral escote redondo"
  String silueta;
  String talla;
  String composicion1;
  String composicion2;
  String manga;
  String colorPrenda;
  String detalle;
  String caracteristicas; // texto libre, respaldo si no hay etiqueta
  String precioFinal;
  Estado estado;
  int? backendId; // id asignado por el backend real al sincronizar

  Captura({
    required this.fotoEtiqueta,
    required this.fotoProducto,
    required this.competidor,
    required this.precioTienda,
    required this.creada,
    this.canal = '',
    this.campana = '',
    this.categoria = '',
    this.puntoPrecio = '',
    this.descripcionProducto = '',
    this.silueta = '',
    this.talla = '',
    this.composicion1 = '',
    this.composicion2 = '',
    this.manga = '',
    this.colorPrenda = '',
    this.detalle = '',
    this.caracteristicas = '',
    this.precioFinal = '',
    this.estado = Estado.borrador,
    this.backendId,
  });

  String get hora =>
      '${creada.hour.toString().padLeft(2, '0')}:${creada.minute.toString().padLeft(2, '0')}';
  int get numFotos =>
      (fotoEtiqueta != null ? 1 : 0) + (fotoProducto != null ? 1 : 0);

  // Persistencia en disco (ver _archivoBorradores) - antes los borradores
  // solo vivian en memoria (esta lista de Dart), asi que si Android
  // cerraba la app en segundo plano, se reiniciaba el telefono, o alguien
  // la cerraba desde "apps recientes", se perdia todo el trabajo del
  // Momento 1 sin ningun aviso (bug real reportado desde campo).
  Map<String, dynamic> toJson() => {
        'fotoEtiqueta': fotoEtiqueta != null ? base64Encode(fotoEtiqueta!) : null,
        'fotoProducto': fotoProducto != null ? base64Encode(fotoProducto!) : null,
        'competidor': competidor,
        'precioTienda': precioTienda,
        'creada': creada.toIso8601String(),
        'canal': canal,
        'campana': campana,
        'categoria': categoria,
        'puntoPrecio': puntoPrecio,
        'descripcionProducto': descripcionProducto,
        'silueta': silueta,
        'talla': talla,
        'composicion1': composicion1,
        'composicion2': composicion2,
        'manga': manga,
        'colorPrenda': colorPrenda,
        'detalle': detalle,
        'caracteristicas': caracteristicas,
        'precioFinal': precioFinal,
        'estado': estado.name,
        'backendId': backendId,
      };

  static Captura fromJson(Map<String, dynamic> j) => Captura(
        fotoEtiqueta: j['fotoEtiqueta'] != null
            ? base64Decode(j['fotoEtiqueta'] as String)
            : null,
        fotoProducto: j['fotoProducto'] != null
            ? base64Decode(j['fotoProducto'] as String)
            : null,
        competidor: j['competidor'] as String? ?? '',
        precioTienda: j['precioTienda'] as String? ?? '',
        creada: DateTime.parse(j['creada'] as String),
        canal: j['canal'] as String? ?? '',
        campana: j['campana'] as String? ?? '',
        categoria: j['categoria'] as String? ?? '',
        puntoPrecio: j['puntoPrecio'] as String? ?? '',
        descripcionProducto: j['descripcionProducto'] as String? ?? '',
        silueta: j['silueta'] as String? ?? '',
        talla: j['talla'] as String? ?? '',
        composicion1: j['composicion1'] as String? ?? '',
        composicion2: j['composicion2'] as String? ?? '',
        manga: j['manga'] as String? ?? '',
        colorPrenda: j['colorPrenda'] as String? ?? '',
        detalle: j['detalle'] as String? ?? '',
        caracteristicas: j['caracteristicas'] as String? ?? '',
        precioFinal: j['precioFinal'] as String? ?? '',
        estado: Estado.values.firstWhere(
          (e) => e.name == j['estado'],
          orElse: () => Estado.borrador,
        ),
        backendId: j['backendId'] as int?,
      );
}

/// Archivo en el almacenamiento propio de la app (sobrevive a que la app se
/// cierre o el telefono se reinicie; se borra solo si se desinstala la app).
Future<File> _archivoBorradores() async {
  final dir = await getApplicationDocumentsDirectory();
  return File('${dir.path}/borradores.json');
}

Future<void> guardarCapturasEnDisco(List<Captura> capturas) async {
  try {
    final file = await _archivoBorradores();
    final data = jsonEncode(capturas.map((c) => c.toJson()).toList());
    await file.writeAsString(data);
  } catch (_) {
    // Best-effort: si por algun motivo no se puede escribir a disco, la
    // app sigue funcionando con lo que tenga en memoria por ahora.
  }
}

Future<List<Captura>> cargarCapturasDeDisco() async {
  try {
    final file = await _archivoBorradores();
    if (!await file.exists()) return [];
    final contenido = await file.readAsString();
    final lista = jsonDecode(contenido) as List;
    return lista
        .map((j) => Captura.fromJson(j as Map<String, dynamic>))
        .toList();
  } catch (_) {
    // Archivo corrupto o inexistente - se arranca con la lista vacia en
    // vez de tumbar la app.
    return [];
  }
}

// ====================== APP ======================
class AzzortiApp extends StatelessWidget {
  const AzzortiApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Azzorti Captura V2.1',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        scaffoldBackgroundColor: AppColors.paper,
        colorScheme: ColorScheme.fromSeed(
          seedColor: AppColors.blue,
          primary: AppColors.blue,
        ),
        appBarTheme: const AppBarTheme(
          backgroundColor: AppColors.navy,
          foregroundColor: Colors.white,
          elevation: 0,
          centerTitle: false,
        ),
        filledButtonTheme: FilledButtonThemeData(
          style: FilledButton.styleFrom(
            backgroundColor: AppColors.blue,
            foregroundColor: AppColors.navy, // texto negro: el fondo ahora es amarillo, no azul
            minimumSize: const Size.fromHeight(48),
            shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10)),
          ),
        ),
        outlinedButtonTheme: OutlinedButtonThemeData(
          style: OutlinedButton.styleFrom(
            foregroundColor: AppColors.ink,
            minimumSize: const Size.fromHeight(48),
            side: const BorderSide(color: AppColors.line, width: 1.5),
            shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10)),
          ),
        ),
      ),
      home: const HomeShell(),
    );
  }
}

// ====================== SHELL CON NAVEGACIÓN ======================
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});
  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _tab = 0;
  final List<Captura> capturas = [];
  String ultimoCompetidor = ''; // se mantiene "pegado" entre capturas
  bool _cargando = true;

  @override
  void initState() {
    super.initState();
    _cargarInicial();
  }

  Future<void> _cargarInicial() async {
    final guardadas = await cargarCapturasDeDisco();
    if (!mounted) return;
    setState(() {
      capturas.addAll(guardadas);
      _cargando = false;
    });
  }

  void _persistir() => guardarCapturasEnDisco(capturas);

  void guardarBorrador(Captura c) {
    setState(() {
      capturas.insert(0, c);
      ultimoCompetidor = c.competidor;
    });
    _persistir();
  }

  void refrescar() {
    setState(() {});
    _persistir();
  }

  Future<void> sincronizarTodo() async {
    final pendientes =
        capturas.where((c) => c.estado == Estado.porSincronizar).toList();
    int exitosos = 0;
    int fallidos = 0;
    for (final c in pendientes) {
      final resultado = await sincronizarConBackend(c);
      if (!mounted) return;
      if (resultado.ok) {
        setState(() => c.estado = Estado.sincronizada);
        exitosos++;
      } else {
        // Se queda como "por sincronizar" — sea porque el backend sigue
        // sin responder o porque es un duplicado (el usuario debe corregirlo
        // a mano). No se marca como sincronizado sin haberlo logrado de verdad.
        fallidos++;
      }
    }
    if (!mounted) return;
    _persistir();
    final mensaje = fallidos == 0
        ? '✓ $exitosos registro(s) sincronizado(s) con el backend'
        : '$exitosos sincronizado(s), $fallidos siguieron sin poder — revisa conexión con el backend';
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(mensaje)));
  }

  @override
  Widget build(BuildContext context) {
    if (_cargando) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    final tabs = [
      CapturarTab(
        capturas: capturas,
        onNuevaCaptura: _iniciarCaptura,
      ),
      PendientesTab(
        capturas: capturas,
        onAbrirBorrador: _abrirBorrador,
        onSincronizar: sincronizarTodo,
      ),
      PerfilTab(capturas: capturas),
    ];
    return Scaffold(
      body: tabs[_tab],
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: const [
          NavigationDestination(
              icon: Icon(Icons.photo_camera_outlined), label: 'Capturar'),
          NavigationDestination(
              icon: Icon(Icons.pending_actions_outlined), label: 'Pendientes'),
          NavigationDestination(
              icon: Icon(Icons.person_outline), label: 'Perfil'),
        ],
      ),
    );
  }

  void _iniciarCaptura() {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => FotoEtiquetaScreen(
        competidorInicial: ultimoCompetidor,
        onGuardar: guardarBorrador,
      ),
    ));
  }

  void _abrirBorrador(Captura c) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => CompletarContextoScreen(captura: c, onFin: refrescar),
    ));
  }
}

// ====================== TAB 1: CAPTURAR ======================
class CapturarTab extends StatelessWidget {
  final List<Captura> capturas;
  final VoidCallback onNuevaCaptura;
  const CapturarTab(
      {super.key, required this.capturas, required this.onNuevaCaptura});

  @override
  Widget build(BuildContext context) {
    final borradores =
        capturas.where((c) => c.estado == Estado.borrador).length;
    return Scaffold(
      appBar: AppBar(
        title: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Captura en campo',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text('Módulo M1 · V2.1 · cámara real',
                style: TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: AppColors.line),
              ),
              child: const Row(
                children: [
                  Icon(Icons.bolt, color: AppColors.blue),
                  SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'En la tienda solo tomas las fotos, marcas el competidor y, si lo ves, el precio. Todo lo demás lo completas después desde Pendientes.',
                      style: TextStyle(fontSize: 12.5, color: AppColors.muted),
                    ),
                  ),
                ],
              ),
            ),
            const Spacer(),
            Icon(Icons.photo_camera,
                size: 72, color: AppColors.blue.withOpacity(.85)),
            const SizedBox(height: 12),
            const Text('¿Lista para capturar?',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: 4),
            Text(
              borradores == 0
                  ? 'Aún no tienes borradores hoy.'
                  : 'Tienes $borradores borrador(es) por completar.',
              style: const TextStyle(color: AppColors.muted, fontSize: 13),
            ),
            const Spacer(),
            FilledButton.icon(
              onPressed: onNuevaCaptura,
              icon: const Icon(Icons.photo_camera_outlined),
              label: const Text('Nueva captura',
                  style: TextStyle(fontWeight: FontWeight.w700)),
            ),
            const SizedBox(height: 20),
          ],
        ),
      ),
    );
  }
}

// ====================== MOMENTO 1 · FOTO ETIQUETA ======================
class FotoEtiquetaScreen extends StatefulWidget {
  final String competidorInicial;
  final ValueChanged<Captura> onGuardar;
  const FotoEtiquetaScreen(
      {super.key, required this.competidorInicial, required this.onGuardar});

  @override
  State<FotoEtiquetaScreen> createState() => _FotoEtiquetaScreenState();
}

class _FotoEtiquetaScreenState extends State<FotoEtiquetaScreen> {
  Uint8List? foto;

  Future<void> _tomar() async {
    final bytes = await tomarFotoReal(context);
    if (bytes != null) setState(() => foto = bytes);
  }

  void _continuar(Uint8List? fotoEtiqueta) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => FotoProductoScreen(
        fotoEtiqueta: fotoEtiqueta,
        competidorInicial: widget.competidorInicial,
        onGuardar: widget.onGuardar,
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Nueva captura',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text('Foto 1 · Etiqueta (si aplica)',
                style: TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            VisorFoto(
              bytes: foto,
              textoVacio:
                  'Toca el botón de la cámara para\nfotografiar la etiqueta de composición / precio',
            ),
            const SizedBox(height: 18),
            if (foto == null) ...[
              Obturador(onTap: _tomar),
              const SizedBox(height: 18),
              OutlinedButton(
                onPressed: () => _continuar(null),
                child: const Text('Omitir etiqueta'),
              ),
            ] else ...[
              FilledButton(
                onPressed: () => _continuar(foto),
                child: const Text('Usar esta foto',
                    style: TextStyle(fontWeight: FontWeight.w700)),
              ),
              const SizedBox(height: 10),
              OutlinedButton(
                onPressed: _tomar,
                child: const Text('Repetir foto'),
              ),
            ],
            const Spacer(),
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('✕ Cancelar captura',
                  style: TextStyle(color: AppColors.muted)),
            ),
          ],
        ),
      ),
    );
  }
}

// ====================== MOMENTO 1 · FOTO PRODUCTO ======================
class FotoProductoScreen extends StatefulWidget {
  final Uint8List? fotoEtiqueta;
  final String competidorInicial;
  final ValueChanged<Captura> onGuardar;
  const FotoProductoScreen(
      {super.key,
      required this.fotoEtiqueta,
      required this.competidorInicial,
      required this.onGuardar});

  @override
  State<FotoProductoScreen> createState() => _FotoProductoScreenState();
}

class _FotoProductoScreenState extends State<FotoProductoScreen> {
  Uint8List? foto;

  Future<void> _tomar() async {
    final bytes = await tomarFotoReal(context);
    if (bytes != null) setState(() => foto = bytes);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Nueva captura',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text('Foto 2 · Producto (obligatoria)',
                style: TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            VisorFoto(
              bytes: foto,
              textoVacio:
                  'Toca el botón de la cámara para\nfotografiar la prenda completa',
            ),
            const SizedBox(height: 18),
            if (foto == null)
              Obturador(onTap: _tomar)
            else ...[
              FilledButton(
                onPressed: () {
                  Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => GuardadoRapidoScreen(
                      fotoEtiqueta: widget.fotoEtiqueta,
                      fotoProducto: foto!,
                      competidorInicial: widget.competidorInicial,
                      onGuardar: widget.onGuardar,
                    ),
                  ));
                },
                child: const Text('Usar esta foto',
                    style: TextStyle(fontWeight: FontWeight.w700)),
              ),
              const SizedBox(height: 10),
              OutlinedButton(
                onPressed: _tomar,
                child: const Text('Repetir foto'),
              ),
            ],
            const Spacer(),
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('↺ Volver a la foto de etiqueta',
                  style: TextStyle(color: AppColors.muted)),
            ),
          ],
        ),
      ),
    );
  }
}

// ====================== MOMENTO 1 · GUARDADO RÁPIDO ======================
class GuardadoRapidoScreen extends StatefulWidget {
  final Uint8List? fotoEtiqueta;
  final Uint8List fotoProducto;
  final String competidorInicial;
  final ValueChanged<Captura> onGuardar;
  const GuardadoRapidoScreen(
      {super.key,
      required this.fotoEtiqueta,
      required this.fotoProducto,
      required this.competidorInicial,
      required this.onGuardar});

  @override
  State<GuardadoRapidoScreen> createState() => _GuardadoRapidoScreenState();
}

class _GuardadoRapidoScreenState extends State<GuardadoRapidoScreen> {
  // Orden alfabetico (pedido de Yohana, para ubicar mas rapido un
  // competidor en la lista) - "Otro" se deja al final a proposito, es la
  // valvula de escape para un competidor nuevo, no una opcion mas.
  static const competidores = [
    'Casa Ideas',
    'Casa In',
    'Forever',
    'Hipermaxi',
    'JYJ',
    'Lili Pink',
    'Mitsuba',
    'Roho',
    'Textilon',
    'Vog',
    'Otro',
  ];
  late String competidor;
  final precioCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    competidor = widget.competidorInicial;
  }

  Captura _crear() => Captura(
        fotoEtiqueta: widget.fotoEtiqueta,
        fotoProducto: widget.fotoProducto,
        competidor: competidor,
        precioTienda: precioCtrl.text.trim(),
        creada: DateTime.now(),
      );

  void _guardar({required bool otraCaptura}) {
    if (competidor.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Marca el competidor (un toque) antes de guardar')));
      return;
    }
    widget.onGuardar(_crear());
    if (otraCaptura) {
      Navigator.of(context).popUntil((r) => r.isFirst);
      Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => FotoEtiquetaScreen(
          competidorInicial: competidor,
          onGuardar: widget.onGuardar,
        ),
      ));
    } else {
      Navigator.of(context).popUntil((r) => r.isFirst);
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Borrador guardado. Lo completas desde Pendientes.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Guardar borrador',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text('Un toque y sigues capturando',
                style: TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Row(children: [
            MiniFoto(etiqueta: 'Etiqueta', bytes: widget.fotoEtiqueta),
            const SizedBox(width: 10),
            MiniFoto(etiqueta: 'Producto', bytes: widget.fotoProducto),
          ]),
          const SizedBox(height: 20),
          const Etiqueta('COMPETIDOR (SE MANTIENE EN LA TIENDA)'),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: competidores
                .map((c) => ChoiceChip(
                      label: Text(c),
                      selected: competidor == c,
                      selectedColor: const Color(0xFFEFF6FF),
                      labelStyle: TextStyle(
                        fontSize: 12.5,
                        fontWeight:
                            competidor == c ? FontWeight.w700 : FontWeight.w400,
                        color: competidor == c
                            ? const Color(0xFF1D4ED8)
                            : AppColors.ink,
                      ),
                      onSelected: (_) => setState(() => competidor = c),
                    ))
                .toList(),
          ),
          const SizedBox(height: 20),
          const Etiqueta('PRECIO — OPCIONAL, SI LO VES'),
          const SizedBox(height: 8),
          TextField(
            controller: precioCtrl,
            keyboardType: TextInputType.number,
            decoration: InputDecoration(
              hintText: 'Bs · ej. 219',
              filled: true,
              fillColor: Colors.white,
              border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: const BorderSide(color: AppColors.line)),
            ),
          ),
          const SizedBox(height: 6),
          const Text(
            'Si lo digitas aquí, en el Momento 2 aparecerá ya cargado: no lo vuelves a escribir.',
            style: TextStyle(fontSize: 11.5, color: AppColors.muted),
          ),
          const SizedBox(height: 24),
          FilledButton(
            onPressed: () => _guardar(otraCaptura: true),
            child: const Text('Guardar y capturar otro',
                style: TextStyle(fontWeight: FontWeight.w700)),
          ),
          const SizedBox(height: 10),
          OutlinedButton(
            onPressed: () => _guardar(otraCaptura: false),
            child: const Text('Guardar y terminar'),
          ),
        ],
      ),
    );
  }
}

// ====================== TAB 2: PENDIENTES ======================
class PendientesTab extends StatelessWidget {
  final List<Captura> capturas;
  final ValueChanged<Captura> onAbrirBorrador;
  final VoidCallback onSincronizar;
  const PendientesTab(
      {super.key,
      required this.capturas,
      required this.onAbrirBorrador,
      required this.onSincronizar});

  @override
  Widget build(BuildContext context) {
    final borradores =
        capturas.where((c) => c.estado == Estado.borrador).toList();
    final completos =
        capturas.where((c) => c.estado == Estado.porSincronizar).toList();
    final sincronizadas =
        capturas.where((c) => c.estado == Estado.sincronizada).toList();

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Mis capturas',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text(
                '${borradores.length} borrador(es) · ${completos.length} por sincronizar',
                style: const TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: capturas.isEmpty
          ? const Center(
              child: Text('Aún no hay capturas.\nEmpieza desde la cámara.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: AppColors.muted)))
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (borradores.isNotEmpty) ...[
                  const Etiqueta('BORRADORES — FALTAN DATOS'),
                  const SizedBox(height: 8),
                  ...borradores.map((c) => TarjetaCaptura(
                        captura: c,
                        onTap: () => onAbrirBorrador(c),
                      )),
                  const SizedBox(height: 18),
                ],
                if (completos.isNotEmpty) ...[
                  const Etiqueta('COMPLETOS — LISTOS PARA ENVIAR'),
                  const SizedBox(height: 8),
                  ...completos.map((c) => TarjetaCaptura(captura: c)),
                  const SizedBox(height: 10),
                  FilledButton.icon(
                    onPressed: onSincronizar,
                    icon: const Icon(Icons.sync),
                    label: const Text('Sincronizar todo'),
                  ),
                  const SizedBox(height: 18),
                ],
                if (sincronizadas.isNotEmpty) ...[
                  const Etiqueta('SINCRONIZADAS'),
                  const SizedBox(height: 8),
                  ...sincronizadas.map((c) => TarjetaCaptura(captura: c)),
                ],
              ],
            ),
    );
  }
}

class TarjetaCaptura extends StatelessWidget {
  final Captura captura;
  final VoidCallback? onTap;
  const TarjetaCaptura({super.key, required this.captura, this.onTap});

  @override
  Widget build(BuildContext context) {
    final c = captura;
    final String titulo;
    final String sub;
    if (c.estado == Estado.borrador) {
      titulo =
          '${c.competidor} · ${c.precioTienda.isEmpty ? "sin precio" : "Bs ${c.precioTienda}"}';
      sub = '${c.hora} · ${c.numFotos} foto(s)';
    } else {
      titulo = '${c.categoria} · ${c.puntoPrecio}';
      sub = c.competidor;
    }
    final chip = switch (c.estado) {
      Estado.borrador =>
        const _Chip('BORRADOR', AppColors.amberBg, AppColors.amberTxt),
      Estado.porSincronizar =>
        const _Chip('POR SINCRONIZAR', AppColors.greenBg, Color(0xFF116932)),
      Estado.sincronizada =>
        const _Chip('SINCRONIZADA', Color(0xFFE0F2FE), Color(0xFF075985)),
    };
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.line),
      ),
      child: ListTile(
        onTap: onTap,
        leading: c.fotoProducto != null
            ? ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: Image.memory(c.fotoProducto!,
                    width: 40, height: 40, fit: BoxFit.cover),
              )
            : Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: const Color(0xFFE2E8F0),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Icon(Icons.image_outlined,
                    size: 20, color: AppColors.muted),
              ),
        title: Text(titulo,
            style:
                const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700)),
        subtitle: Text(sub,
            style: const TextStyle(fontSize: 11.5, color: AppColors.muted)),
        trailing: chip,
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  final String texto;
  final Color fondo;
  final Color color;
  const _Chip(this.texto, this.fondo, this.color);
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        decoration: BoxDecoration(
            color: fondo, borderRadius: BorderRadius.circular(99)),
        child: Text(texto,
            style: TextStyle(
                fontSize: 9, fontWeight: FontWeight.w800, color: color)),
      );
}

// ====================== MOMENTO 2 · CONTEXTO ======================
class CompletarContextoScreen extends StatefulWidget {
  final Captura captura;
  final VoidCallback onFin;
  const CompletarContextoScreen(
      {super.key, required this.captura, required this.onFin});

  @override
  State<CompletarContextoScreen> createState() =>
      _CompletarContextoScreenState();
}

class _CompletarContextoScreenState extends State<CompletarContextoScreen> {
  String canal = 'Retail';
  String? campana;
  String? categoria;
  String punto = 'Bajo';

  // "C-10" es la campaña real vigente (misma campaña con la que se cargó
  // el catálogo Azzorti en el backend) — antes decían "May-26"/"C7", que
  // no coincidían con ningún dato real cargado.
  // "(activa)" era parte del VALOR guardado, no solo un texto visual -
  // las capturas quedaban con campana="C-10 (activa)" literal, que no
  // coincide con ningún filtro del dashboard (mismo bug que ya se había
  // corregido del lado del dashboard, pero no acá).
  static const cortes = [
    'C-10', 'C-11', 'C-12', 'C-13', 'C-14', 'C-15', 'C-16', 'C-17', 'C-18',
  ];
  // Venta Directa usa "C09 2026".."C18 2026" (con el año), no el formato
  // "C-9".."C-18" de Retail - eran la misma lista por error, así que las
  // capturas de Venta Directa quedaban con una campaña que no coincidía
  // con ningún filtro del dashboard (bug real detectado por Yohana).
  static const campanas = [
    'C10 2026', 'C11 2026', 'C12 2026', 'C13 2026', 'C14 2026',
    'C15 2026', 'C16 2026', 'C17 2026', 'C18 2026',
  ];
  // Subgrupos reales de REX (ropa) y HOG (hogar/infantil) — ver
  // "IPC BOLIVIA 2024_2025 - Venta retail.xlsx".
  // Orden alfabetico (pedido de Yohana).
  static const categoriasRetail = [
    'Blusas Femeninas',
    'Camisetas Femeninas',
    'Camisetas Masculinas',
    'Crop Top Femenino',
    'Cubrecamas Dobles',
    'Cubrecamas Sencillos Infantiles',
    'Jeans Femeninos',
    'Jeans Masculinos',
    'Lencería PPP',
    'Mochilas Infantiles',
    'Polos Masculinos',
    'Sábanas Dobles',
    'Toallas',
    'Vestidos Femeninos Cortos',
    'Vestidos Femeninos Largos',
  ];
  // Categorias reales de Venta Directa - ver "Comparativo fragancias
  // azzorti vs competencia.xlsx" y "INDEX PRECIO SECTOR VENTA DIRECTA".
  // Antes se mostraban mezcladas con las de Retail en una sola lista.
  // Orden alfabetico (pedido de Yohana).
  static const categoriasVentaDirecta = [
    'Cabello',
    'Cuidado Diario',
    'Cup',
    'Fragancias',
    'Hogar', // ej. Tupperware
    'Joyería',
    'Maquillaje',
    'Rostro',
  ];

  @override
  Widget build(BuildContext context) {
    final c = widget.captura;
    final opciones = canal == 'Retail' ? cortes : campanas;
    final categoriasDisponibles =
        canal == 'Retail' ? categoriasRetail : categoriasVentaDirecta;
    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Completar borrador',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text(
                '${c.competidor} · ${c.hora}${c.precioTienda.isEmpty ? "" : " · Bs ${c.precioTienda}"}',
                style: const TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Row(children: [
            MiniFoto(etiqueta: 'Etiqueta', bytes: c.fotoEtiqueta),
            const SizedBox(width: 10),
            MiniFoto(etiqueta: 'Producto', bytes: c.fotoProducto),
          ]),
          const SizedBox(height: 20),
          const Etiqueta('CANAL'),
          const SizedBox(height: 8),
          Row(
            children: ['Retail', 'Venta Directa']
                .map((op) => Expanded(
                      child: Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          label: SizedBox(
                              width: double.infinity,
                              child: Text(op, textAlign: TextAlign.center)),
                          selected: canal == op,
                          selectedColor: const Color(0xFFEFF6FF),
                          onSelected: (_) => setState(() {
                            canal = op;
                            campana = null;
                            categoria = null;
                          }),
                        ),
                      ),
                    ))
                .toList(),
          ),
          const SizedBox(height: 18),
          Etiqueta(canal == 'Retail' ? 'CORTE MENSUAL' : 'CAMPAÑA'),
          const SizedBox(height: 8),
          Selector(
            valor: campana,
            hint: canal == 'Retail' ? 'Elige el corte' : 'Elige la campaña',
            opciones: opciones,
            onChanged: (v) => setState(() => campana = v),
          ),
          const SizedBox(height: 18),
          const Etiqueta('CATEGORÍA'),
          const SizedBox(height: 8),
          Selector(
            valor: categoria,
            hint: 'Elige la categoría',
            opciones: categoriasDisponibles,
            onChanged: (v) => setState(() => categoria = v),
          ),
          const SizedBox(height: 18),
          const Etiqueta('PUNTO DE PRECIO'),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            children: ['Bajo', 'Medio', 'Alto']
                .map((p) => ChoiceChip(
                      label: Text(p),
                      selected: punto == p,
                      selectedColor: const Color(0xFFEFF6FF),
                      onSelected: (_) => setState(() => punto = p),
                    ))
                .toList(),
          ),
          const SizedBox(height: 26),
          FilledButton(
            onPressed: (campana == null || categoria == null)
                ? null
                : () {
                    c.canal = canal;
                    c.campana = campana!;
                    c.categoria = categoria!;
                    c.puntoPrecio = punto;
                    Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) =>
                          FichaPrecioScreen(captura: c, onFin: widget.onFin),
                    ));
                  },
            child: const Text('Continuar',
                style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }
}

// ====================== MOMENTO 2 · FICHA Y PRECIO ======================
class FichaPrecioScreen extends StatefulWidget {
  final Captura captura;
  final VoidCallback onFin;
  const FichaPrecioScreen(
      {super.key, required this.captura, required this.onFin});

  @override
  State<FichaPrecioScreen> createState() => _FichaPrecioScreenState();
}

class _FichaPrecioScreenState extends State<FichaPrecioScreen> {
  bool analizando = true;
  bool etiquetaDioDatos = false; // true si el OCR sí llenó composición
  bool colorSugerido = false; // true si el color vino de analizar la foto

  String? silueta;
  String? talla;
  String? manga;
  bool mangaSugerida = false;

  late final TextEditingController descripcionCtrl;
  late final TextEditingController comp1Ctrl;
  late final TextEditingController comp2Ctrl;
  late final TextEditingController colorCtrl;
  late final TextEditingController detalleCtrl;
  late final TextEditingController caracteristicasCtrl;
  late final TextEditingController precioCtrl;

  static const siluetas = ['Suelta', 'Entallada', 'Oversize', 'Recta'];
  static const tallas = ['XS', 'S', 'M', 'L', 'XL', 'Única', 'N/A (no aplica)'];
  static const mangas = [
    'Sin manga',
    'Manga corta',
    'Manga larga',
    '3/4',
    'N/A (no aplica)',
  ];

  @override
  void initState() {
    super.initState();
    descripcionCtrl = TextEditingController();
    comp1Ctrl = TextEditingController();
    comp2Ctrl = TextEditingController();
    colorCtrl = TextEditingController();
    detalleCtrl = TextEditingController();
    caracteristicasCtrl = TextEditingController();
    precioCtrl = TextEditingController(text: widget.captura.precioTienda);
    _analizarFotos();
  }

  Future<void> _analizarFotos() async {
    final c = widget.captura;

    // En Venta Directa no hay etiqueta de composición textil que leer
    // (es un perfume/maquillaje/joya, no una prenda) - se deja que
    // "características del producto" sea el dato descriptivo, como pidió
    // Yohana, en vez de forzar un OCR que no aplica a ese tipo de producto.
    if (c.fotoEtiqueta != null && c.canal != 'Venta Directa') {
      final r = await leerEtiqueta(c.fotoEtiqueta!);
      comp1Ctrl.text = r['componente1'] ?? '';
      comp2Ctrl.text = r['componente2'] ?? '';
      etiquetaDioDatos = comp1Ctrl.text.isNotEmpty;
    }

    if (c.fotoProducto != null) {
      final s = await sugerirDesdeProducto(c.fotoProducto!);
      if ((s['color'] ?? '').isNotEmpty) {
        colorCtrl.text = s['color']!;
        colorSugerido = true;
      }
      if ((s['manga'] ?? '').isNotEmpty) {
        manga = s['manga'];
        mangaSugerida = true;
      }
    }

    if (mounted) setState(() => analizando = false);
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.captura;
    final vinoDeTienda = c.precioTienda.isNotEmpty;
    final esVentaDirecta = c.canal == 'Venta Directa';

    if (analizando) {
      return Scaffold(
        appBar: AppBar(
          title: const Text('Ficha del producto',
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
        ),
        body: const Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircularProgressIndicator(color: AppColors.blue),
              SizedBox(height: 14),
              Text('Analizando fotos…',
                  style: TextStyle(color: AppColors.muted, fontSize: 13)),
            ],
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Ficha del producto',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text('${c.categoria} · ${c.puntoPrecio}',
                style: const TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Row(children: [
            MiniFoto(etiqueta: 'Etiqueta', bytes: c.fotoEtiqueta),
            const SizedBox(width: 10),
            MiniFoto(etiqueta: 'Producto', bytes: c.fotoProducto),
          ]),
          const SizedBox(height: 16),
          if (esVentaDirecta)
            const SizedBox.shrink()
          else if (etiquetaDioDatos)
            const LeyendaAuto('✓ Detectado de la etiqueta', AppColors.green)
          else if (c.fotoEtiqueta != null)
            const Text(
              'La etiqueta no arrojó texto legible: llena estos campos a mano.',
              style: TextStyle(fontSize: 11.5, color: AppColors.muted),
            )
          else
            const Text(
              'No se tomó foto de etiqueta: llena estos campos a mano.',
              style: TextStyle(fontSize: 11.5, color: AppColors.muted),
            ),
          const SizedBox(height: 14),

          const Etiqueta('DESCRIPCIÓN DEL PRODUCTO'),
          const SizedBox(height: 8),
          CampoTexto(
            controller: descripcionCtrl,
            hint: esVentaDirecta
                ? 'ej. Eau de parfum floral 50 ml'
                : 'ej. Blusa floral escote redondo manga corta',
          ),
          const SizedBox(height: 6),
          Text(
            esVentaDirecta
                ? 'Un nombre corto que identifique el producto — ayuda a reconocerlo después en el dashboard.'
                : 'Un nombre corto que identifique la prenda — ayuda a reconocerla después en el dashboard, igual que el catálogo de Azzorti nombra cada producto (ej. "Blusa Ref.R4874").',
            style: const TextStyle(fontSize: 11.5, color: AppColors.muted),
          ),
          const SizedBox(height: 16),

          if (!esVentaDirecta) ...[
            const Etiqueta('COMPOSICIÓN — COMPONENTE 1'),
            const SizedBox(height: 8),
            CampoTexto(
              controller: comp1Ctrl,
              hint: 'ej. 97% Poliéster',
              auto: comp1Ctrl.text.isNotEmpty,
            ),
            const SizedBox(height: 16),
            const Etiqueta('COMPOSICIÓN — COMPONENTE 2 (SI APLICA)'),
            const SizedBox(height: 8),
            CampoTexto(
              controller: comp2Ctrl,
              hint: 'ej. 3% Spandex',
              auto: comp2Ctrl.text.isNotEmpty,
            ),
            const SizedBox(height: 16),
          ],
          const Etiqueta('COLOR'),
          const SizedBox(height: 8),
          CampoTexto(
            controller: colorCtrl,
            hint: 'ej. Celeste',
            auto: colorSugerido,
          ),
          if (colorSugerido)
            const LeyendaAuto(
                '🔎 Sugerido de la foto del producto — revisa', AppColors.amberTxt),
          const SizedBox(height: 20),

          if (!esVentaDirecta) ...[
            const Etiqueta('SILUETA / CORTE'),
            const SizedBox(height: 8),
            Selector(
                valor: silueta,
                hint: 'Elige la silueta',
                opciones: siluetas,
                onChanged: (v) => setState(() => silueta = v)),
            const SizedBox(height: 16),
            const Etiqueta('TALLA'),
            const SizedBox(height: 8),
            Selector(
                valor: talla,
                hint: 'Elige la talla',
                opciones: tallas,
                onChanged: (v) => setState(() => talla = v)),
            const SizedBox(height: 6),
            const Text(
              'La talla real de Azzorti puede variar por talla (ej. distintos códigos S/M/L/XL) — anotarla ayuda a elegir el equivalente correcto más adelante.',
              style: TextStyle(fontSize: 11.5, color: AppColors.muted),
            ),
            const SizedBox(height: 16),
            const Etiqueta('MANGA'),
            const SizedBox(height: 8),
            Selector(
                valor: manga,
                hint: 'Elige el tipo de manga (o N/A si no aplica)',
                opciones: mangas,
                onChanged: (v) => setState(() {
                      manga = v;
                      mangaSugerida = false;
                    })),
            if (mangaSugerida)
              const LeyendaAuto('🔎 Sugerido desde la foto — revisa', AppColors.amberTxt),
            const SizedBox(height: 16),
            const Etiqueta('DETALLE'),
            const SizedBox(height: 8),
            CampoTexto(controller: detalleCtrl, hint: 'ej. Caído en el hombro'),
            const SizedBox(height: 16),
          ],

          Etiqueta((etiquetaDioDatos && !esVentaDirecta)
              ? 'CARACTERÍSTICAS DEL PRODUCTO (opcional)'
              : 'CARACTERÍSTICAS DEL PRODUCTO — DESCRÍBELO AQUÍ, NO HAY ETIQUETA'),
          const SizedBox(height: 8),
          TextField(
            controller: caracteristicasCtrl,
            maxLines: 3,
            decoration: InputDecoration(
              hintText: esVentaDirecta
                  ? 'ej. Tono Vainilla, presentación 11g, frasco dorado…'
                  : etiquetaDioDatos
                      ? 'Cualquier detalle extra que quieras anotar…'
                      : 'ej. Blusa suelta, tela gruesa tipo lino, sin etiqueta legible…',
              filled: true,
              fillColor: Colors.white,
              border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: const BorderSide(color: AppColors.line)),
            ),
          ),
          if (esVentaDirecta)
            const Padding(
              padding: EdgeInsets.only(top: 6),
              child: Text(
                'Mientras más específico (tono, tamaño/presentación, tipo exacto de producto), más precisa la homologación — el sistema compara estas palabras contra el catálogo real de Azzorti.',
                style: TextStyle(fontSize: 11.5, color: AppColors.muted),
              ),
            ),
          const SizedBox(height: 16),

          Etiqueta(vinoDeTienda
              ? 'PRECIO (BS) — PRE-CARGADO DE LA TIENDA'
              : 'PRECIO (BS)'),
          const SizedBox(height: 8),
          CampoTexto(controller: precioCtrl, hint: 'ej. 219', numerico: true),
          if (vinoDeTienda)
            const Padding(
              padding: EdgeInsets.only(top: 6),
              child: Text(
                'Lo digitaste en la tienda: no hay que repetirlo. Puedes corregirlo si hubo error.',
                style: TextStyle(fontSize: 11.5, color: AppColors.muted),
              ),
            ),
          const SizedBox(height: 26),
          FilledButton(
            onPressed: () {
              if (precioCtrl.text.trim().isEmpty ||
                  double.tryParse(precioCtrl.text.trim()) == null) {
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                    content:
                        Text('El precio es obligatorio y debe ser numérico')));
                return;
              }
              // Características libres solo es obligatorio si la etiqueta
              // no aportó ni composición ni color (o no había foto de etiqueta).
              final sinDatoEtiqueta = comp1Ctrl.text.trim().isEmpty;
              if (sinDatoEtiqueta && caracteristicasCtrl.text.trim().isEmpty) {
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                    content: Text(
                        'Como no hay datos de la etiqueta, describe las características del producto')));
                return;
              }
              c.descripcionProducto = descripcionCtrl.text.trim();
              c.silueta = silueta ?? '';
              c.talla = talla ?? '';
              c.composicion1 = comp1Ctrl.text.trim();
              c.composicion2 = comp2Ctrl.text.trim();
              c.manga = manga ?? '';
              c.colorPrenda = colorCtrl.text.trim();
              c.detalle = detalleCtrl.text.trim();
              c.caracteristicas = caracteristicasCtrl.text.trim();
              c.precioFinal = precioCtrl.text.trim();
              Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => RevisarScreen(captura: c, onFin: widget.onFin),
              ));
            },
            child: const Text('Continuar',
                style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }
}

class LeyendaAuto extends StatelessWidget {
  final String texto;
  final Color color;
  const LeyendaAuto(this.texto, this.color, {super.key});
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 5),
        child: Text(texto,
            style: TextStyle(
                fontSize: 10.5, fontWeight: FontWeight.w700, color: color)),
      );
}

// ====================== MOMENTO 2 · REVISAR ======================
class RevisarScreen extends StatefulWidget {
  final Captura captura;
  final VoidCallback onFin;
  const RevisarScreen({super.key, required this.captura, required this.onFin});

  @override
  State<RevisarScreen> createState() => _RevisarScreenState();
}

class _RevisarScreenState extends State<RevisarScreen> {
  bool sincronizando = false;

  Future<void> _guardarYSincronizar() async {
    final c = widget.captura;
    setState(() => sincronizando = true);
    final resultado = await sincronizarConBackend(c);
    if (!mounted) return;
    setState(() => sincronizando = false);

    if (resultado.duplicado) {
      // Regla de negocio crítica del REQ: no se guarda nada y se avisa
      // explícitamente — el usuario corrige el SKU/competidor/campaña.
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('⚠ ${resultado.mensaje}')),
      );
      return;
    }

    c.estado = Estado.porSincronizar;
    widget.onFin();
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(resultado.ok
          ? '✓ Sincronizado con el backend'
          : 'Guardado localmente. ${resultado.mensaje}'),
    ));

    if (resultado.ok && c.backendId != null) {
      Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => HomologacionScreen(captura: c, onFin: widget.onFin),
      ));
    } else {
      // Antes esta pantalla siempre decia "quedo sincronizado con el
      // backend" aunque la sincronizacion hubiera fallado de plano (sin
      // conexion, etc.) - se le pasa explicitamente si de verdad se
      // sincronizo, para no mostrar un exito que no ocurrio.
      Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => ConfirmacionScreen(
            captura: c, onFin: widget.onFin, sincronizado: false),
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.captura;
    return Scaffold(
      appBar: AppBar(
        title: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Revisar registro',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text('Antes de sincronizar',
                style: TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Container(
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.line),
            ),
            child: Column(children: [
              FilaResumen('Competidor', c.competidor),
              FilaResumen('Canal', '${c.canal} · ${c.campana}'),
              FilaResumen('Categoría', '${c.categoria} · ${c.puntoPrecio}'),
              if (c.descripcionProducto.isNotEmpty)
                FilaResumen('Descripción', c.descripcionProducto),
              FilaResumen('Silueta', c.silueta),
              FilaResumen('Talla', c.talla.isEmpty ? '—' : c.talla),
              FilaResumen('Composición',
                  [c.composicion1, c.composicion2]
                          .where((e) => e.isNotEmpty)
                          .join(' + ')
                          .isEmpty
                      ? '—'
                      : [c.composicion1, c.composicion2]
                          .where((e) => e.isNotEmpty)
                          .join(' + ')),
              if (c.caracteristicas.isNotEmpty)
                FilaResumen('Características', c.caracteristicas),
              FilaResumen('Precio', 'Bs ${c.precioFinal}'),
              FilaResumen('Fotos', '${c.numFotos} adjunta(s)'),
            ]),
          ),
          const SizedBox(height: 22),
          FilledButton(
            onPressed: sincronizando ? null : _guardarYSincronizar,
            child: sincronizando
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(
                        strokeWidth: 2.4, color: Colors.white),
                  )
                : const Text('Guardar y sincronizar',
                    style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }
}

// ====================== MOMENTO 2 · HOMOLOGACIÓN CONTRA AZZORTI ======================
// Nunca compara por código — no hay SKU compartido con la competencia.
// El backend ya filtró por categoría + nivel de precio (elegido a mano
// por el analista, nunca clasificado por rango en el sistema) y ordenó
// por similitud de color/silueta/composición/manga. Aquí el analista
// confirma cuál es el equivalente real antes de evaluar cumplimiento de
// política y la alerta genérica del 10%.
class HomologacionScreen extends StatefulWidget {
  final Captura captura;
  final VoidCallback onFin;
  const HomologacionScreen(
      {super.key, required this.captura, required this.onFin});

  @override
  State<HomologacionScreen> createState() => _HomologacionScreenState();
}

class _HomologacionScreenState extends State<HomologacionScreen> {
  bool cargando = true;
  bool confirmando = false;
  List<Map<String, dynamic>> sugerencias = [];
  String? skuSeleccionado;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    final r = await pedirSugerenciasHomologacion(widget.captura.backendId!);
    if (!mounted) return;
    setState(() {
      sugerencias = r;
      if (sugerencias.isNotEmpty) {
        skuSeleccionado = sugerencias.first['sku'] as String;
      }
      cargando = false;
    });
  }

  Future<void> _confirmarYEvaluar() async {
    if (skuSeleccionado == null) return;
    setState(() => confirmando = true);
    final id = widget.captura.backendId!;
    final ok = await confirmarHomologacion(id, skuSeleccionado!);
    if (!mounted) return;
    if (!ok) {
      // Antes se seguia igual a la pantalla de evaluacion aunque la
      // confirmacion fallara (ej. sin conexion, o el codigo ya no existe
      // en el catalogo indexado) - se veia como si hubiera quedado
      // homologado sin quedar guardado de verdad en el backend.
      setState(() => confirmando = false);
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text(
              '⚠ No se pudo confirmar la homologación (revisa la conexión con el backend). Intenta de nuevo.')));
      return;
    }
    final evaluacion = await pedirEvaluacion(id);
    if (!mounted) return;
    setState(() => confirmando = false);
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => ConfirmacionScreen(
        captura: widget.captura,
        onFin: widget.onFin,
        evaluacion: evaluacion,
      ),
    ));
  }

  Future<void> _omitir() async {
    // Antes esto nunca pedia evaluacion (mostraba siempre el aviso "se
    // guardo sin homologar"). Desde el Pendiente 3, aunque no haya
    // homologacion, el backend igual puede comparar contra la misma
    // captura de la campana anterior - se pide igual, puede volver
    // VS_CAMPANA_ANTERIOR o SIN_DATO segun si existe ese dato previo.
    final evaluacion = await pedirEvaluacion(widget.captura.backendId!);
    if (!mounted) return;
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => ConfirmacionScreen(
        captura: widget.captura,
        onFin: widget.onFin,
        evaluacion: evaluacion,
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Homologación con Azzorti',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text('¿Cuál es tu producto equivalente?',
                style: TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: cargando
          ? const Center(
              child: CircularProgressIndicator(color: AppColors.blue))
          : sugerencias.isEmpty
              ? Padding(
                  padding: const EdgeInsets.all(20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'No se encontraron productos Azzorti de esta categoría y nivel de precio en el catálogo.',
                        style: TextStyle(color: AppColors.muted),
                      ),
                      const SizedBox(height: 20),
                      FilledButton(
                        onPressed: _omitir,
                        child: const Text('Continuar sin homologar'),
                      ),
                    ],
                  ),
                )
              : ListView(
                  padding: const EdgeInsets.all(20),
                  children: [
                    const Text(
                      'El sistema comparó color, silueta, composición y manga con el catálogo Azzorti (nunca por código — la competencia no comparte SKU). Confirma cuál es el equivalente real:',
                      style: TextStyle(fontSize: 12.5, color: AppColors.muted),
                    ),
                    const SizedBox(height: 14),
                    ...sugerencias.map((s) {
                      final sku = s['sku'] as String;
                      final score = (s['score_similitud'] as num).toDouble();
                      final seleccionado = skuSeleccionado == sku;
                      final fotoUrl = s['foto_url'] as String?;
                      final pagina = s['pagina_catalogo'];
                      return Container(
                        margin: const EdgeInsets.only(bottom: 10),
                        decoration: BoxDecoration(
                          color: seleccionado
                              ? const Color(0xFFEFF6FF)
                              : Colors.white,
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(
                              color: seleccionado
                                  ? AppColors.blue
                                  : AppColors.line,
                              width: seleccionado ? 1.6 : 1),
                        ),
                        child: InkWell(
                          borderRadius: BorderRadius.circular(12),
                          onTap: () => setState(() => skuSeleccionado = sku),
                          child: Padding(
                            padding: const EdgeInsets.all(10),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Radio<String>(
                                  value: sku,
                                  groupValue: skuSeleccionado,
                                  onChanged: (v) =>
                                      setState(() => skuSeleccionado = v),
                                ),
                                ClipRRect(
                                  borderRadius: BorderRadius.circular(8),
                                  child: fotoUrl != null
                                      ? Image.network(fotoUrl,
                                          width: 56,
                                          height: 56,
                                          fit: BoxFit.cover,
                                          errorBuilder: (_, __, ___) =>
                                              _SinFoto())
                                      : _SinFoto(),
                                ),
                                const SizedBox(width: 10),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                          '${s['descripcion'] ?? sku} · Bs ${s['precio']}',
                                          style: const TextStyle(
                                              fontWeight: FontWeight.w700,
                                              fontSize: 13.5)),
                                      const SizedBox(height: 2),
                                      Text(
                                          '${s['color'] ?? '—'} · ${s['silueta'] ?? '—'} · ${s['composicion'] ?? '—'}',
                                          style: const TextStyle(
                                              fontSize: 11.5,
                                              color: AppColors.muted)),
                                      if (pagina != null)
                                        Padding(
                                          padding:
                                              const EdgeInsets.only(top: 2),
                                          child: Text('Pág. $pagina del catálogo',
                                              style: const TextStyle(
                                                  fontSize: 10.5,
                                                  color: AppColors.muted,
                                                  fontStyle:
                                                      FontStyle.italic)),
                                        ),
                                    ],
                                  ),
                                ),
                                const SizedBox(width: 6),
                                _ScoreBadge(score: score),
                              ],
                            ),
                          ),
                        ),
                      );
                    }),
                    const SizedBox(height: 10),
                    FilledButton(
                      onPressed: confirmando ? null : _confirmarYEvaluar,
                      child: confirmando
                          ? const SizedBox(
                              height: 20,
                              width: 20,
                              child: CircularProgressIndicator(
                                  strokeWidth: 2.4, color: Colors.white))
                          : const Text('Confirmar y evaluar precio',
                              style: TextStyle(fontWeight: FontWeight.w700)),
                    ),
                    const SizedBox(height: 8),
                    OutlinedButton(
                      onPressed: _omitir,
                      child:
                          const Text('Ninguno de estos — continuar sin homologar'),
                    ),
                  ],
                ),
    );
  }
}

/// Puntaje de similitud (0-100%): qué tan parecidos son los atributos
/// (color/silueta/composición/manga) de este producto Azzorti frente a lo
/// que el analista capturó de la competencia. NO es una probabilidad de
/// que sea el producto correcto — es solo una ayuda para ordenar las
/// sugerencias; la confirmación final siempre la hace la persona.
class _ScoreBadge extends StatelessWidget {
  final double score;
  const _ScoreBadge({required this.score});
  @override
  Widget build(BuildContext context) {
    final color = score >= 60
        ? AppColors.green
        : (score >= 30 ? AppColors.amberTxt : AppColors.muted);
    final bg = score >= 60
        ? AppColors.greenBg
        : (score >= 30 ? AppColors.amberBg : const Color(0xFFF1F5F9));
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
          decoration:
              BoxDecoration(color: bg, borderRadius: BorderRadius.circular(8)),
          child: Text('${score.toStringAsFixed(0)}%',
              style: TextStyle(
                  fontSize: 11, fontWeight: FontWeight.w800, color: color)),
        ),
        const SizedBox(height: 2),
        const Text('similitud',
            style: TextStyle(fontSize: 9, color: AppColors.muted)),
      ],
    );
  }
}

class _SinFoto extends StatelessWidget {
  @override
  Widget build(BuildContext context) => Container(
        width: 56,
        height: 56,
        color: const Color(0xFFF1F5F9),
        child: const Icon(Icons.image_outlined,
            size: 20, color: AppColors.muted),
      );
}

// ====================== MOMENTO 2 · CONFIRMACIÓN ======================
class ConfirmacionScreen extends StatelessWidget {
  final Captura captura;
  final VoidCallback onFin;
  final Map<String, dynamic>? evaluacion;
  final bool sincronizado;
  const ConfirmacionScreen(
      {super.key,
      required this.captura,
      required this.onFin,
      this.evaluacion,
      this.sincronizado = true});

  @override
  Widget build(BuildContext context) {
    final c = captura;
    final ev = evaluacion;
    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: false,
        title: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Listo',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text('Registro procesado',
                style: TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 14),
          Center(
            child: Container(
              width: 72,
              height: 72,
              decoration: const BoxDecoration(
                  color: AppColors.greenBg, shape: BoxShape.circle),
              child:
                  const Icon(Icons.check, color: AppColors.green, size: 38),
            ),
          ),
          const SizedBox(height: 16),
          Center(
            child: Text(sincronizado ? '¡Guardado!' : '⚠ Guardado localmente',
                style: const TextStyle(
                    fontSize: 20, fontWeight: FontWeight.w800)),
          ),
          const SizedBox(height: 6),
          Text(
            sincronizado
                ? '${c.competidor} · ${c.categoria} · ${c.puntoPrecio} quedó sincronizado con el backend.'
                : '${c.competidor} · ${c.categoria} · ${c.puntoPrecio} NO se pudo sincronizar con el backend (sin conexión). Queda guardado en el teléfono — vuelve a intentar "Guardar y sincronizar" desde el borrador cuando tengas señal.',
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 12.5, color: AppColors.muted),
          ),
          const SizedBox(height: 16),
          // _TarjetaEvaluacion ya maneja sola los 3 casos (homologado,
          // vs. campaña anterior, o sin ningún dato para comparar) - solo
          // si la llamada al backend falló de plano (ev == null) se avisa
          // aparte, sin fingir un motivo especifico que no se conoce.
          if (ev != null) _TarjetaEvaluacion(ev: ev) else
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppColors.amberBg,
                borderRadius: BorderRadius.circular(10),
              ),
              child: const Text(
                'No se pudo obtener la evaluación de precio — revisa la conexión con el backend.',
                style: TextStyle(fontSize: 11.5, color: AppColors.amberTxt),
              ),
            ),
          const SizedBox(height: 26),
          FilledButton(
            onPressed: () => Navigator.of(context).popUntil((r) => r.isFirst),
            child: const Text('Completar otro borrador',
                style: TextStyle(fontWeight: FontWeight.w700)),
          ),
          const SizedBox(height: 10),
          OutlinedButton(
            onPressed: () => Navigator.of(context).popUntil((r) => r.isFirst),
            child: const Text('Ver mis pendientes'),
          ),
        ],
      ),
    );
  }
}

/// Muestra el resultado real del motor de precios: contra Azzorti si ya hay
/// homologación, o contra la misma captura de la campaña anterior si no la
/// hay — un solo umbral de alerta editable (ya no hay política puntual por
/// categoría/competidor, esa se retiró en el Pendiente 3; ver memoria del
/// proyecto).
class _TarjetaEvaluacion extends StatelessWidget {
  final Map<String, dynamic> ev;
  const _TarjetaEvaluacion({required this.ev});

  @override
  Widget build(BuildContext context) {
    final modo = ev['modo'] as String? ?? 'SIN_DATO';
    final umbral = (ev['umbral_pct'] as num?)?.toDouble() ?? 10.0;

    if (modo == 'SIN_DATO') {
      return Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AppColors.amberBg,
          borderRadius: BorderRadius.circular(10),
        ),
        child: Text(
          (ev['mensaje'] as String?) ??
              'Sin homologación y sin captura previa del mismo producto para comparar.',
          style: const TextStyle(fontSize: 11.5, color: AppColors.amberTxt),
        ),
      );
    }

    final esHomologo = modo == 'HOMOLOGO';
    final deltaPct = (ev['delta_pct'] as num).toDouble();
    final alerta = ev['alerta'] as bool;
    final umbralTxt = umbral % 1 == 0 ? umbral.toStringAsFixed(0) : umbral.toString();

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.line),
      ),
      child: Column(children: [
        if (esHomologo) ...[
          FilaResumen('Azzorti equivalente', ev['azzorti_sku'] as String),
          FilaResumen('Precio Azzorti', 'Bs ${ev['precio_azzorti']}'),
        ] else
          FilaResumen('Campaña anterior', ev['campana_anterior'] as String),
        FilaResumen(
          esHomologo ? 'Precio competencia' : 'Precio campaña anterior',
          'Bs ${esHomologo ? ev['precio_competencia'] : ev['precio_anterior']}',
        ),
        if (!esHomologo)
          FilaResumen('Precio campaña actual', 'Bs ${ev['precio_competencia']}'),
        FilaResumen('Diferencia', '${deltaPct.toStringAsFixed(1)}%'),
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 10, 4, 12),
          child: _EtiquetaEstado(
            texto: alerta
                ? '⚠ Alerta: variación mayor al $umbralTxt% ${esHomologo ? "frente a Azzorti" : "frente a la campaña anterior"}'
                : 'Variación dentro de la tolerancia del $umbralTxt%',
            ok: !alerta,
          ),
        ),
      ]),
    );
  }
}

class _EtiquetaEstado extends StatelessWidget {
  final String texto;
  final bool? ok; // null = neutral (sin política)
  const _EtiquetaEstado({required this.texto, required this.ok});
  @override
  Widget build(BuildContext context) {
    final estado = ok; // variable local: permite que Dart estreche el tipo
    final color = estado == null
        ? AppColors.muted
        : (estado ? AppColors.green : const Color(0xFFC93B3B));
    final bg = estado == null
        ? const Color(0xFFF1F5F9)
        : (estado ? AppColors.greenBg : const Color(0xFFFBEAEA));
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(8)),
      child: Text(texto,
          style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: color)),
    );
  }
}

// ====================== TAB 3: PERFIL ======================
class PerfilTab extends StatelessWidget {
  final List<Captura> capturas;
  const PerfilTab({super.key, required this.capturas});

  @override
  Widget build(BuildContext context) {
    final total = capturas.length;
    final borradores =
        capturas.where((c) => c.estado == Estado.borrador).length;
    final sincronizadas =
        capturas.where((c) => c.estado == Estado.sincronizada).length;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Perfil',
            style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          const CircleAvatar(
              radius: 34,
              backgroundColor: Color(0xFFEFF6FF),
              child: Icon(Icons.person, size: 36, color: AppColors.blue)),
          const SizedBox(height: 10),
          const Center(
              child: Text('Analista de campo',
                  style:
                      TextStyle(fontSize: 16, fontWeight: FontWeight.w700))),
          const Center(
              child: Text('Módulo M1 · Benchmarking Precios Bolivia',
                  style: TextStyle(fontSize: 12, color: AppColors.muted))),
          const SizedBox(height: 20),
          FilaResumen('Capturas de hoy', '$total'),
          FilaResumen('Borradores por completar', '$borradores'),
          FilaResumen('Sincronizadas', '$sincronizadas'),
          FilaResumen('Versión del prototipo', 'V2.1 · cámara real'),
        ],
      ),
    );
  }
}

// ====================== WIDGETS REUTILIZABLES ======================
class Etiqueta extends StatelessWidget {
  final String texto;
  const Etiqueta(this.texto, {super.key});
  @override
  Widget build(BuildContext context) => Text(texto,
      style: const TextStyle(
          fontSize: 10.5,
          fontWeight: FontWeight.w800,
          letterSpacing: 1.1,
          color: AppColors.muted));
}

class Selector extends StatelessWidget {
  final String? valor;
  final String hint;
  final List<String> opciones;
  final ValueChanged<String?> onChanged;
  const Selector(
      {super.key,
      required this.valor,
      required this.hint,
      required this.opciones,
      required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppColors.line),
      ),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<String>(
          value: valor,
          isExpanded: true,
          hint: Text(hint,
              style: const TextStyle(fontSize: 13.5, color: AppColors.muted)),
          items: opciones
              .map((o) => DropdownMenuItem(
                  value: o,
                  child: Text(o, style: const TextStyle(fontSize: 13.5))))
              .toList(),
          onChanged: onChanged,
        ),
      ),
    );
  }
}

class CampoTexto extends StatelessWidget {
  final TextEditingController controller;
  final String hint;
  final bool numerico;
  final bool auto;
  const CampoTexto(
      {super.key,
      required this.controller,
      required this.hint,
      this.numerico = false,
      this.auto = false});

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      keyboardType: numerico ? TextInputType.number : TextInputType.text,
      decoration: InputDecoration(
        hintText: hint,
        filled: true,
        fillColor: auto ? const Color(0xFFF0FDF4) : Colors.white,
        suffixIcon: auto
            ? const Padding(
                padding: EdgeInsets.only(right: 10),
                child: Icon(Icons.check_circle, color: AppColors.green, size: 18),
              )
            : null,
        border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: BorderSide(
                color: auto ? AppColors.green : AppColors.line,
                width: auto ? 1.4 : 1)),
      ),
    );
  }
}

/// Muestra la foto completa (con pinch-to-zoom) en pantalla, para cuando la
/// miniatura de 90px no alcanza para reconocer el detalle del producto.
void _abrirFotoCompleta(BuildContext context, Uint8List bytes, String etiqueta) {
  Navigator.of(context).push(PageRouteBuilder(
    opaque: false,
    barrierColor: Colors.black,
    pageBuilder: (_, __, ___) => Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        child: Stack(
          children: [
            Center(
              child: InteractiveViewer(
                minScale: 1,
                maxScale: 5,
                child: Image.memory(bytes),
              ),
            ),
            Positioned(
              top: 8,
              left: 8,
              child: IconButton(
                icon: const Icon(Icons.close, color: Colors.white, size: 28),
                onPressed: () => Navigator.of(context).pop(),
              ),
            ),
            Positioned(
              top: 14,
              left: 56,
              child: Text(etiqueta,
                  style: const TextStyle(color: Colors.white, fontSize: 15)),
            ),
          ],
        ),
      ),
    ),
  ));
}

class MiniFoto extends StatelessWidget {
  final String etiqueta;
  final Uint8List? bytes;
  const MiniFoto({super.key, required this.etiqueta, this.bytes});
  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        height: 90,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          color: bytes != null
              ? const Color(0xFFCBD5E1)
              : const Color(0xFFF1F5F9),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: AppColors.line),
        ),
        child: bytes != null
            ? GestureDetector(
                // Antes esta miniatura de 90px era la unica forma de ver la
                // foto - en el Momento 2 (Ficha del producto) el analista
                // ya no se acuerda bien del detalle y necesita verla mas
                // grande para describir el producto (reportado desde
                // campo). Tocarla abre la foto completa con zoom.
                onTap: () => _abrirFotoCompleta(context, bytes!, etiqueta),
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    Image.memory(bytes!, fit: BoxFit.cover),
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: 0,
                      child: Container(
                        color: Colors.black45,
                        padding: const EdgeInsets.symmetric(vertical: 2),
                        child: Text(etiqueta,
                            textAlign: TextAlign.center,
                            style: const TextStyle(
                                fontSize: 9, color: Colors.white)),
                      ),
                    ),
                    const Positioned(
                      right: 4,
                      top: 4,
                      child: Icon(Icons.zoom_in,
                          size: 16, color: Colors.white, shadows: [
                        Shadow(color: Colors.black, blurRadius: 3),
                      ]),
                    ),
                  ],
                ),
              )
            : Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(Icons.hide_image_outlined,
                      size: 22, color: AppColors.muted),
                  const SizedBox(height: 4),
                  Text('$etiqueta (omitida)',
                      style: const TextStyle(
                          fontSize: 10, color: AppColors.muted)),
                ],
              ),
      ),
    );
  }
}

class FilaResumen extends StatelessWidget {
  final String k;
  final String v;
  const FilaResumen(this.k, this.v, {super.key});
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 9),
      decoration: const BoxDecoration(
          border: Border(bottom: BorderSide(color: Color(0xFFF1F5F9)))),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(k, style: const TextStyle(fontSize: 12.5, color: AppColors.muted)),
          Flexible(
            child: Text(v.isEmpty ? '—' : v,
                textAlign: TextAlign.end,
                style: const TextStyle(
                    fontSize: 12.5, fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }
}

class VisorFoto extends StatelessWidget {
  final Uint8List? bytes;
  final String textoVacio;
  const VisorFoto({super.key, required this.bytes, required this.textoVacio});
  @override
  Widget build(BuildContext context) {
    return Container(
      height: 300,
      width: double.infinity,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: AppColors.visor,
        borderRadius: BorderRadius.circular(16),
      ),
      child: bytes != null
          ? Image.memory(bytes!, fit: BoxFit.cover)
          : Stack(
              children: [
                Positioned.fill(
                  child: Padding(
                    padding: const EdgeInsets.all(18),
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        border: Border.all(
                            color: const Color(0xFF3B4F75), width: 1.5),
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                  ),
                ),
                Center(
                  child: Text(
                    textoVacio,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        color: Color(0xFF7D90B5), fontSize: 12.5),
                  ),
                ),
              ],
            ),
    );
  }
}

class Obturador extends StatelessWidget {
  final VoidCallback onTap;
  const Obturador({super.key, required this.onTap});
  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: 64,
        height: 64,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: Colors.white,
          border: Border.all(color: const Color(0xFFCBD5E1), width: 4),
          boxShadow: const [
            BoxShadow(
                color: Color(0x22101D36),
                blurRadius: 10,
                offset: Offset(0, 4)),
          ],
        ),
        child: const Icon(Icons.photo_camera, color: AppColors.navy),
      ),
    );
  }
}
