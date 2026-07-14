import 'package:flutter/material.dart';

void main() => runApp(const AzzortiApp());

// ====================== COLORES ======================
class AppColors {
  static const navy = Color(0xFF101D36);
  static const ink = Color(0xFF0F1C33);
  static const blue = Color(0xFF2563EB);
  static const paper = Color(0xFFF6F7FA);
  static const muted = Color(0xFF64748B);
  static const line = Color(0xFFE2E8F0);
  static const green = Color(0xFF16A34A);
  static const greenBg = Color(0xFFDCFCE7);
  static const amberTxt = Color(0xFF8A5A06);
  static const amberBg = Color(0xFFFEF3C7);
  static const visor = Color(0xFF0B1526);
}

// ====================== MODELO ======================
enum Estado { borrador, porSincronizar, sincronizada }

class Captura {
  bool fotoEtiqueta;
  bool fotoProducto;
  String competidor;
  String precioTienda; // precio opcional digitado en tienda (Momento 1)
  final DateTime creada;

  // Datos que se completan en el Momento 2:
  String canal;
  String campana;
  String categoria;
  String puntoPrecio;
  String silueta;
  String tela;
  String manga;
  String colorPrenda;
  String detalle;
  String precioFinal;
  String sku;
  Estado estado;

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
    this.silueta = '',
    this.tela = '',
    this.manga = '',
    this.colorPrenda = '',
    this.detalle = '',
    this.precioFinal = '',
    this.sku = '',
    this.estado = Estado.borrador,
  });

  String get hora =>
      '${creada.hour.toString().padLeft(2, '0')}:${creada.minute.toString().padLeft(2, '0')}';
  int get numFotos => (fotoEtiqueta ? 1 : 0) + (fotoProducto ? 1 : 0);
}

// ====================== APP ======================
class AzzortiApp extends StatelessWidget {
  const AzzortiApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Azzorti Captura V2',
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

  void guardarBorrador(Captura c) {
    setState(() {
      capturas.insert(0, c);
      ultimoCompetidor = c.competidor;
    });
  }

  void refrescar() => setState(() {});

  void sincronizarTodo() {
    setState(() {
      for (final c in capturas) {
        if (c.estado == Estado.porSincronizar) c.estado = Estado.sincronizada;
      }
    });
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Registros sincronizados con la base de datos (simulado)')));
  }

  @override
  Widget build(BuildContext context) {
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
            Text('Módulo M1 · V2 · fotos primero',
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
                      style:
                          TextStyle(fontSize: 12.5, color: AppColors.muted),
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
class FotoEtiquetaScreen extends StatelessWidget {
  final String competidorInicial;
  final ValueChanged<Captura> onGuardar;
  const FotoEtiquetaScreen(
      {super.key, required this.competidorInicial, required this.onGuardar});

  void _continuar(BuildContext context, bool tomoEtiqueta) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => FotoProductoScreen(
        tieneEtiqueta: tomoEtiqueta,
        competidorInicial: competidorInicial,
        onGuardar: onGuardar,
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
            VisorCamara(texto: 'Encuadra la etiqueta de composición / precio'),
            const SizedBox(height: 18),
            Obturador(onTap: () => _continuar(context, true)),
            const SizedBox(height: 18),
            OutlinedButton(
              onPressed: () => _continuar(context, false),
              child: const Text('Omitir etiqueta'),
            ),
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
class FotoProductoScreen extends StatelessWidget {
  final bool tieneEtiqueta;
  final String competidorInicial;
  final ValueChanged<Captura> onGuardar;
  const FotoProductoScreen(
      {super.key,
      required this.tieneEtiqueta,
      required this.competidorInicial,
      required this.onGuardar});

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
            VisorCamara(texto: 'Encuadra la prenda completa'),
            const SizedBox(height: 18),
            Obturador(onTap: () {
              Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => GuardadoRapidoScreen(
                  tieneEtiqueta: tieneEtiqueta,
                  competidorInicial: competidorInicial,
                  onGuardar: onGuardar,
                ),
              ));
            }),
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
  final bool tieneEtiqueta;
  final String competidorInicial;
  final ValueChanged<Captura> onGuardar;
  const GuardadoRapidoScreen(
      {super.key,
      required this.tieneEtiqueta,
      required this.competidorInicial,
      required this.onGuardar});

  @override
  State<GuardadoRapidoScreen> createState() => _GuardadoRapidoScreenState();
}

class _GuardadoRapidoScreenState extends State<GuardadoRapidoScreen> {
  static const competidores = ['Lilipink', 'Forever', 'Índigo', 'Éxito', 'Otro'];
  late String competidor;
  final precioCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    competidor = widget.competidorInicial;
  }

  Captura _crear() => Captura(
        fotoEtiqueta: widget.tieneEtiqueta,
        fotoProducto: true,
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
      // Vuelve directo a la cámara, manteniendo el competidor "pegado".
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
            MiniFoto(etiqueta: 'Etiqueta', existe: widget.tieneEtiqueta),
            const SizedBox(width: 10),
            const MiniFoto(etiqueta: 'Producto', existe: true),
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
                        fontWeight: competidor == c
                            ? FontWeight.w700
                            : FontWeight.w400,
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
                style:
                    const TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
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
      sub = '${c.competidor} · ${c.sku}';
    }
    final chip = switch (c.estado) {
      Estado.borrador => const _Chip('BORRADOR', AppColors.amberBg, AppColors.amberTxt),
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
        leading: Container(
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

  static const cortes = ['May-26 (activa)', 'Abr-26', 'Mar-26'];
  static const campanas = ['C7 (activa)', 'C6', 'C5', 'C4'];
  static const categorias = [
    'Blusas Femeninas',
    'Jeans Dama',
    'Ropa Interior',
    'Deportivo',
    'Infantil'
  ];

  @override
  Widget build(BuildContext context) {
    final c = widget.captura;
    final opciones = canal == 'Retail' ? cortes : campanas;
    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Completar borrador',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text(
                '${c.competidor} · ${c.hora}${c.precioTienda.isEmpty ? "" : " · Bs ${c.precioTienda}"}',
                style:
                    const TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Row(children: [
            MiniFoto(etiqueta: 'Etiqueta', existe: c.fotoEtiqueta),
            const SizedBox(width: 10),
            MiniFoto(etiqueta: 'Producto', existe: c.fotoProducto),
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
            opciones: categorias,
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
                      builder: (_) => FichaPrecioScreen(
                          captura: c, onFin: widget.onFin),
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
  String? silueta;
  String? tela;
  String? manga;
  late final TextEditingController colorCtrl;
  late final TextEditingController detalleCtrl;
  late final TextEditingController precioCtrl;
  late final TextEditingController skuCtrl;

  static const siluetas = ['Suelta', 'Entallada', 'Oversize', 'Recta'];
  static const telas = ['Poliéster 58%', 'Algodón 100%', 'Viscosa', 'Mezcla'];
  static const mangas = ['Sin manga', 'Manga corta', 'Manga larga', '3/4'];

  @override
  void initState() {
    super.initState();
    colorCtrl = TextEditingController();
    detalleCtrl = TextEditingController();
    // El precio digitado en la tienda (Momento 1) llega ya cargado:
    precioCtrl = TextEditingController(text: widget.captura.precioTienda);
    skuCtrl = TextEditingController();
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.captura;
    final vinoDeTienda = c.precioTienda.isNotEmpty;
    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Ficha del producto',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text('${c.categoria} · ${c.puntoPrecio}',
                style:
                    const TextStyle(fontSize: 11, color: Color(0xFF9DB2D6))),
          ],
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Row(children: [
            MiniFoto(etiqueta: 'Etiqueta', existe: c.fotoEtiqueta),
            const SizedBox(width: 10),
            MiniFoto(etiqueta: 'Producto', existe: c.fotoProducto),
          ]),
          const SizedBox(height: 20),
          const Etiqueta('SILUETA / CORTE'),
          const SizedBox(height: 8),
          Selector(
              valor: silueta,
              hint: 'Elige la silueta',
              opciones: siluetas,
              onChanged: (v) => setState(() => silueta = v)),
          const SizedBox(height: 16),
          const Etiqueta('TELA / COMPOSICIÓN'),
          const SizedBox(height: 8),
          Selector(
              valor: tela,
              hint: 'Según la foto de etiqueta',
              opciones: telas,
              onChanged: (v) => setState(() => tela = v)),
          const SizedBox(height: 16),
          const Etiqueta('MANGA'),
          const SizedBox(height: 8),
          Selector(
              valor: manga,
              hint: 'Elige el tipo de manga',
              opciones: mangas,
              onChanged: (v) => setState(() => manga = v)),
          const SizedBox(height: 16),
          const Etiqueta('COLOR'),
          const SizedBox(height: 8),
          CampoTexto(controller: colorCtrl, hint: 'ej. Celeste'),
          const SizedBox(height: 16),
          const Etiqueta('DETALLE'),
          const SizedBox(height: 8),
          CampoTexto(controller: detalleCtrl, hint: 'ej. Caído en el hombro'),
          const SizedBox(height: 16),
          Etiqueta(vinoDeTienda
              ? 'PRECIO (BS) — PRE-CARGADO DE LA TIENDA'
              : 'PRECIO (BS)'),
          const SizedBox(height: 8),
          CampoTexto(
              controller: precioCtrl,
              hint: 'ej. 219',
              numerico: true),
          if (vinoDeTienda)
            const Padding(
              padding: EdgeInsets.only(top: 6),
              child: Text(
                'Lo digitaste en la tienda: no hay que repetirlo. Puedes corregirlo si hubo error.',
                style: TextStyle(fontSize: 11.5, color: AppColors.muted),
              ),
            ),
          const SizedBox(height: 16),
          const Etiqueta('SKU DE REFERENCIA'),
          const SizedBox(height: 8),
          CampoTexto(controller: skuCtrl, hint: 'ej. FWR-BLZ-219-MAY26'),
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
              c.silueta = silueta ?? '';
              c.tela = tela ?? '';
              c.manga = manga ?? '';
              c.colorPrenda = colorCtrl.text.trim();
              c.detalle = detalleCtrl.text.trim();
              c.precioFinal = precioCtrl.text.trim();
              c.sku = skuCtrl.text.trim();
              Navigator.of(context).push(MaterialPageRoute(
                builder: (_) =>
                    RevisarScreen(captura: c, onFin: widget.onFin),
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

// ====================== MOMENTO 2 · REVISAR ======================
class RevisarScreen extends StatelessWidget {
  final Captura captura;
  final VoidCallback onFin;
  const RevisarScreen({super.key, required this.captura, required this.onFin});

  @override
  Widget build(BuildContext context) {
    final c = captura;
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
              FilaResumen('Silueta', c.silueta),
              FilaResumen('Tela', c.tela),
              FilaResumen('Precio', 'Bs ${c.precioFinal}'),
              FilaResumen('SKU', c.sku.isEmpty ? '—' : c.sku),
              FilaResumen('Fotos', '${c.numFotos} adjunta(s)'),
            ]),
          ),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppColors.amberBg,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: const Color(0xFFFDE68A)),
            ),
            child: const Text(
              'El sistema valida que el precio sea numérico y avisará si el SKU ya existe para ese competidor en esa campaña (simulado en este prototipo).',
              style: TextStyle(fontSize: 11.5, color: AppColors.amberTxt),
            ),
          ),
          const SizedBox(height: 22),
          FilledButton(
            onPressed: () {
              c.estado = Estado.porSincronizar;
              onFin();
              Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => ConfirmacionScreen(captura: c, onFin: onFin),
              ));
            },
            child: const Text('Guardar y sincronizar',
                style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }
}

// ====================== MOMENTO 2 · CONFIRMACIÓN ======================
class ConfirmacionScreen extends StatelessWidget {
  final Captura captura;
  final VoidCallback onFin;
  const ConfirmacionScreen(
      {super.key, required this.captura, required this.onFin});

  @override
  Widget build(BuildContext context) {
    final c = captura;
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
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            const SizedBox(height: 30),
            Container(
              width: 72,
              height: 72,
              decoration: const BoxDecoration(
                  color: AppColors.greenBg, shape: BoxShape.circle),
              child:
                  const Icon(Icons.check, color: AppColors.green, size: 38),
            ),
            const SizedBox(height: 16),
            const Text('¡Guardado!',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(
              '${c.competidor} · ${c.categoria} · ${c.puntoPrecio} quedó listo para sincronizar con la base de datos.',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 12.5, color: AppColors.muted),
            ),
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppColors.amberBg,
                borderRadius: BorderRadius.circular(10),
              ),
              child: const Text(
                'Si no hubiera señal: queda "pendiente" y se sincroniza solo más tarde.',
                style:
                    TextStyle(fontSize: 11.5, color: AppColors.amberTxt),
              ),
            ),
            const Spacer(),
            FilledButton(
              onPressed: () =>
                  Navigator.of(context).popUntil((r) => r.isFirst),
              child: const Text('Completar otro borrador',
                  style: TextStyle(fontWeight: FontWeight.w700)),
            ),
            const SizedBox(height: 10),
            OutlinedButton(
              onPressed: () =>
                  Navigator.of(context).popUntil((r) => r.isFirst),
              child: const Text('Ver mis pendientes'),
            ),
          ],
        ),
      ),
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
          FilaResumen('Versión del prototipo', 'V2 · fotos primero'),
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
              style:
                  const TextStyle(fontSize: 13.5, color: AppColors.muted)),
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
  const CampoTexto(
      {super.key,
      required this.controller,
      required this.hint,
      this.numerico = false});

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      keyboardType: numerico ? TextInputType.number : TextInputType.text,
      decoration: InputDecoration(
        hintText: hint,
        filled: true,
        fillColor: Colors.white,
        border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: AppColors.line)),
      ),
    );
  }
}

class MiniFoto extends StatelessWidget {
  final String etiqueta;
  final bool existe;
  const MiniFoto({super.key, required this.etiqueta, required this.existe});
  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        height: 72,
        decoration: BoxDecoration(
          color: existe ? const Color(0xFFCBD5E1) : const Color(0xFFF1F5F9),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: AppColors.line),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(existe ? Icons.image : Icons.hide_image_outlined,
                size: 22, color: AppColors.muted),
            const SizedBox(height: 4),
            Text(existe ? etiqueta : '$etiqueta (omitida)',
                style:
                    const TextStyle(fontSize: 10, color: AppColors.muted)),
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
          Text(k,
              style:
                  const TextStyle(fontSize: 12.5, color: AppColors.muted)),
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

class VisorCamara extends StatelessWidget {
  final String texto;
  const VisorCamara({super.key, required this.texto});
  @override
  Widget build(BuildContext context) {
    return Container(
      height: 300,
      decoration: BoxDecoration(
        color: AppColors.visor,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Stack(
        children: [
          Positioned.fill(
            child: Padding(
              padding: const EdgeInsets.all(18),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  border: Border.all(
                      color: const Color(0xFF3B4F75),
                      width: 1.5,
                      style: BorderStyle.solid),
                  borderRadius: BorderRadius.circular(12),
                ),
              ),
            ),
          ),
          Center(
            child: Text(
              '$texto\n(cámara simulada en el prototipo)',
              textAlign: TextAlign.center,
              style:
                  const TextStyle(color: Color(0xFF7D90B5), fontSize: 12.5),
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
