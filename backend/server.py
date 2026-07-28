"""
Backend de demo — Azzorti Benchmarking de Precios (Hito 1 / Retail).

Reemplaza el patrón "GitHub como base de datos" del prototipo original
(token embebido en el APK + capturas.json en un repo público) por una API
real con persistencia en SQLite. Pensado para correr en la laptop de
Yohana durante la demo remota: la app y el dashboard le apuntan a la IP
de la laptop en la red local, no hace falta hosting público.

Los datos de "azzorti_producto" y "politica_precio" son de MUESTRA para
la demo (no es el archivo SID real todavía) — quedan marcados como tal
en /info para que se pueda aclarar en la reunión qué es dato real y qué
es de muestra.

Ejecutar:
    pip install -r requirements.txt
    uvicorn server:app --host 0.0.0.0 --port 8000

Con eso queda accesible desde el celular en la misma WiFi en
http://<IP-de-la-laptop>:8000
"""
from __future__ import annotations

import sqlite3
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

DB_PATH = Path(__file__).parent / "azzorti_demo.db"

app = FastAPI(title="Azzorti Benchmarking — Backend de demo")

# La app Flutter y el dashboard HTML corren en orígenes distintos (o sin
# origen, desde un WebView/APK) — para la demo se permite cualquiera.
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


# ====================== ESQUEMA Y SEED ======================

SCHEMA = """
CREATE TABLE IF NOT EXISTS captura (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    competidor TEXT NOT NULL,
    canal TEXT NOT NULL,
    campana TEXT NOT NULL,
    categoria TEXT NOT NULL,
    nivel_precio TEXT NOT NULL,
    silueta TEXT,
    composicion1 TEXT,
    composicion2 TEXT,
    manga TEXT,
    color TEXT,
    detalle TEXT,
    caracteristicas TEXT,
    precio REAL NOT NULL,
    sku_competidor TEXT,
    azzorti_sku_confirmado TEXT,
    creada_en TEXT NOT NULL,
    UNIQUE (competidor, campana, sku_competidor)
);

CREATE TABLE IF NOT EXISTS azzorti_producto (
    sku TEXT PRIMARY KEY,
    categoria TEXT NOT NULL,
    color TEXT,
    composicion TEXT,
    silueta TEXT,
    manga TEXT,
    nivel_precio TEXT NOT NULL,
    precio REAL NOT NULL,
    campana TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS politica_precio (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    categoria TEXT NOT NULL,
    competidor TEXT NOT NULL,
    tipo TEXT NOT NULL,          -- DEBAJO_PCT | ENCIMA_PCT | IGUAL | SIN_POLITICA
    umbral_pct REAL,
    UNIQUE (categoria, competidor)
);
"""

# Reglas reales tomadas de "IPC BOLIVIA 2024_2025 - Venta retail.xlsx" y
# confirmadas por Yohana el 2026-07-27 (ver memoria del proyecto).
POLITICAS_SEED = [
    ("Blusas Femeninas", "Forever", "DEBAJO_PCT", 20),
    ("Vestidos Femeninos Cortos", "Forever", "DEBAJO_PCT", 30),
    ("Vestidos Femeninos Largos", "Forever", "DEBAJO_PCT", 30),
    ("Camisetas Masculinas", "Mitsuba", "IGUAL", None),
    ("Camisetas Femeninas", "Forever", "DEBAJO_PCT", 20),
    ("Camisetas Femeninas", "Mitsuba", "IGUAL", None),
    ("Jeans Femeninos", "Forever", "DEBAJO_PCT", 20),
    ("Jeans Femeninos", "Mitsuba", "DEBAJO_PCT", 20),
    ("Cubrecamas Dobles", "Casa In", "ENCIMA_PCT", 10),
    ("Sábanas Dobles", "Casa In", "ENCIMA_PCT", 10),
    ("Toallas", "Casa In", "ENCIMA_PCT", 10),
    ("Mochilas Infantiles", "Casa Ideas", "DEBAJO_PCT", 10),
    ("Cubrecamas Sencillos Infantiles", "Casa Ideas", "DEBAJO_PCT", 10),
]

# Tolerancia genérica de vigilancia (independiente de la política puntual
# de cada categoría) — confirmada por Yohana: 10%.
UMBRAL_ALERTA_GENERICA_PCT = 10.0

# Catálogo Azzorti de MUESTRA para poder demostrar el motor de similitud
# en la reunión. Sustituir por la ingesta real del archivo SID cuando
# esté disponible (ver memoria "project_motor_precios_decisiones").
AZZORTI_PRODUCTOS_SEED = [
    ("AZZ-BLZ-045", "Blusas Femeninas", "Celeste", "97% Poliéster + 3% Spandex", "Suelta", "Manga corta", "Bajo", 159, "C-07"),
    ("AZZ-BLZ-045-M", "Blusas Femeninas", "Celeste", "97% Poliéster + 3% Spandex", "Suelta", "Manga corta", "Medio", 219, "C-07"),
    ("AZZ-BLZ-102", "Blusas Femeninas", "Blanco", "100% Algodón", "Entallada", "Manga larga", "Bajo", 149, "C-07"),
    ("AZZ-BLZ-102-M", "Blusas Femeninas", "Blanco", "100% Algodón", "Entallada", "Manga larga", "Medio", 199, "C-07"),
    ("AZZ-JNF-011", "Jeans Femeninos", "Azul", "98% Algodón + 2% Elastano", "Entallada", "N/A (no aplica)", "Bajo", 219, "C-07"),
    ("AZZ-JNF-011-M", "Jeans Femeninos", "Azul", "98% Algodón + 2% Elastano", "Entallada", "N/A (no aplica)", "Medio", 289, "C-07"),
    ("AZZ-CAM-030", "Camisetas Femeninas", "Negro", "95% Algodón + 5% Spandex", "Suelta", "Manga corta", "Bajo", 99, "C-07"),
    ("AZZ-CAM-031", "Camisetas Masculinas", "Blanco", "100% Algodón", "Recta", "Manga corta", "Bajo", 89, "C-07"),
    ("AZZ-CUB-007", "Cubrecamas Dobles", "Beige", "Microfibra", None, None, "Medio", 380, "C-07"),
    ("AZZ-SAB-004", "Sábanas Dobles", "Blanco", "Poliéster", None, None, "Bajo", 250, "C-07"),
]


@contextmanager
def get_conn():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    try:
        yield conn
        conn.commit()
    finally:
        conn.close()


def init_db() -> None:
    with get_conn() as conn:
        conn.executescript(SCHEMA)
        if conn.execute("SELECT COUNT(*) FROM politica_precio").fetchone()[0] == 0:
            conn.executemany(
                "INSERT INTO politica_precio (categoria, competidor, tipo, umbral_pct) VALUES (?, ?, ?, ?)",
                POLITICAS_SEED,
            )
        if conn.execute("SELECT COUNT(*) FROM azzorti_producto").fetchone()[0] == 0:
            conn.executemany(
                "INSERT INTO azzorti_producto (sku, categoria, color, composicion, silueta, manga, nivel_precio, precio, campana) "
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                AZZORTI_PRODUCTOS_SEED,
            )


init_db()


# ====================== MODELOS ======================

class CapturaIn(BaseModel):
    competidor: str
    canal: str = "Retail"
    campana: str
    categoria: str
    nivel_precio: str
    silueta: Optional[str] = None
    composicion1: Optional[str] = None
    composicion2: Optional[str] = None
    manga: Optional[str] = None
    color: Optional[str] = None
    detalle: Optional[str] = None
    caracteristicas: Optional[str] = None
    precio: float
    sku_competidor: Optional[str] = None


class HomologacionConfirmar(BaseModel):
    azzorti_sku: str


# ====================== ENDPOINTS ======================

@app.get("/info")
def info():
    return {
        "servicio": "Azzorti Benchmarking — backend de demo",
        "nota": (
            "azzorti_producto y politica_precio son datos reales de negocio "
            "(politicas del Excel IPC BOLIVIA) salvo el catalogo Azzorti, que "
            "es de MUESTRA hasta integrar el archivo SID real."
        ),
        "umbral_alerta_generica_pct": UMBRAL_ALERTA_GENERICA_PCT,
    }


@app.post("/capturas", status_code=201)
def crear_captura(c: CapturaIn):
    with get_conn() as conn:
        try:
            cur = conn.execute(
                """INSERT INTO captura
                (competidor, canal, campana, categoria, nivel_precio, silueta,
                 composicion1, composicion2, manga, color, detalle,
                 caracteristicas, precio, sku_competidor, creada_en)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
                (
                    c.competidor, c.canal, c.campana, c.categoria, c.nivel_precio,
                    c.silueta, c.composicion1, c.composicion2, c.manga, c.color,
                    c.detalle, c.caracteristicas, c.precio, c.sku_competidor,
                    datetime.now(timezone.utc).isoformat(),
                ),
            )
        except sqlite3.IntegrityError:
            raise HTTPException(
                status_code=409,
                detail=f"Registro duplicado: ya existe una captura de '{c.competidor}' "
                       f"con SKU '{c.sku_competidor}' en la campaña '{c.campana}'.",
            )
        captura_id = cur.lastrowid
    return {"id": captura_id, "mensaje": "Captura sincronizada correctamente."}


@app.get("/capturas")
def listar_capturas(competidor: Optional[str] = None, campana: Optional[str] = None):
    query = "SELECT * FROM captura WHERE 1=1"
    params: list = []
    if competidor:
        query += " AND competidor = ?"
        params.append(competidor)
    if campana:
        query += " AND campana = ?"
        params.append(campana)
    query += " ORDER BY id DESC"
    with get_conn() as conn:
        rows = conn.execute(query, params).fetchall()
    return [dict(r) for r in rows]


def _score_similitud(captura: sqlite3.Row, producto: sqlite3.Row) -> float:
    """Puntaje 0-100. Categoría ya viene filtrada como condición obligatoria;
    esto puntúa qué tan cerca están los atributos que sí capturó el analista.
    Nunca compara por código — solo por atributos y color, tal como se
    definió con Yohana (no hay SKU compartido con la competencia)."""
    score = 0.0
    color_c = (captura["color"] or "").strip().lower()
    color_p = (producto["color"] or "").strip().lower()
    if color_c and color_p and color_c == color_p:
        score += 30

    silueta_c = (captura["silueta"] or "").strip().lower()
    silueta_p = (producto["silueta"] or "").strip().lower()
    if silueta_c and silueta_p and silueta_c == silueta_p:
        score += 30

    comp_c = f"{captura['composicion1'] or ''} {captura['composicion2'] or ''}".lower()
    comp_p = (producto["composicion"] or "").lower()
    if comp_c.strip() and comp_p.strip():
        tokens_c = set(comp_c.replace("+", " ").split())
        tokens_p = set(comp_p.replace("+", " ").split())
        interseccion = tokens_c & tokens_p
        if interseccion:
            score += min(25, 25 * len(interseccion) / max(len(tokens_p), 1))

    manga_c = (captura["manga"] or "").strip().lower()
    manga_p = (producto["manga"] or "").strip().lower()
    if manga_c and manga_p and manga_c == manga_p:
        score += 15

    return round(score, 1)


@app.get("/capturas/{captura_id}/homologacion/sugerencias")
def sugerir_homologacion(captura_id: int):
    with get_conn() as conn:
        captura = conn.execute("SELECT * FROM captura WHERE id = ?", (captura_id,)).fetchone()
        if not captura:
            raise HTTPException(404, "Captura no encontrada")
        candidatos = conn.execute(
            "SELECT * FROM azzorti_producto WHERE categoria = ? AND nivel_precio = ?",
            (captura["categoria"], captura["nivel_precio"]),
        ).fetchall()

    sugerencias = sorted(
        (
            {
                "sku": p["sku"],
                "categoria": p["categoria"],
                "color": p["color"],
                "composicion": p["composicion"],
                "silueta": p["silueta"],
                "manga": p["manga"],
                "precio": p["precio"],
                "score_similitud": _score_similitud(captura, p),
            }
            for p in candidatos
        ),
        key=lambda x: x["score_similitud"],
        reverse=True,
    )
    return {
        "captura_id": captura_id,
        "criterio": "Filtrado por categoría + nivel de precio (elegido por el analista, "
                    "nunca clasificado por rango en el sistema). Ranking por color, "
                    "silueta, composición y manga — nunca por código.",
        "sugerencias": sugerencias,
    }


@app.post("/capturas/{captura_id}/homologacion/confirmar")
def confirmar_homologacion(captura_id: int, body: HomologacionConfirmar):
    with get_conn() as conn:
        existe = conn.execute("SELECT 1 FROM azzorti_producto WHERE sku = ?", (body.azzorti_sku,)).fetchone()
        if not existe:
            raise HTTPException(404, f"SKU Azzorti '{body.azzorti_sku}' no existe en el catálogo")
        cur = conn.execute(
            "UPDATE captura SET azzorti_sku_confirmado = ? WHERE id = ?",
            (body.azzorti_sku, captura_id),
        )
        if cur.rowcount == 0:
            raise HTTPException(404, "Captura no encontrada")
    return {"mensaje": f"Homologación confirmada: captura {captura_id} <-> {body.azzorti_sku}"}


@app.get("/capturas/{captura_id}/evaluacion")
def evaluar_captura(captura_id: int):
    with get_conn() as conn:
        captura = conn.execute("SELECT * FROM captura WHERE id = ?", (captura_id,)).fetchone()
        if not captura:
            raise HTTPException(404, "Captura no encontrada")
        if not captura["azzorti_sku_confirmado"]:
            raise HTTPException(
                400,
                "Esta captura todavía no tiene un SKU Azzorti confirmado — "
                "resuelve la homologación antes de evaluar.",
            )
        azzorti = conn.execute(
            "SELECT * FROM azzorti_producto WHERE sku = ?", (captura["azzorti_sku_confirmado"],)
        ).fetchone()
        politica = conn.execute(
            "SELECT * FROM politica_precio WHERE categoria = ? AND competidor = ?",
            (captura["categoria"], captura["competidor"]),
        ).fetchone()

    precio_azzorti = azzorti["precio"]
    precio_competencia = captura["precio"]
    delta_pct = (precio_azzorti - precio_competencia) / precio_azzorti * 100

    if politica is None:
        cumplimiento = {"tiene_politica": False, "detalle": "Sin política definida para esta categoría/competidor."}
    else:
        tipo, umbral = politica["tipo"], politica["umbral_pct"]
        if tipo == "SIN_POLITICA":
            cumple = None
        elif tipo == "IGUAL":
            cumple = abs(delta_pct) < 1e-6
        elif tipo == "DEBAJO_PCT":
            cumple = precio_azzorti <= precio_competencia * (1 - umbral / 100)
        elif tipo == "ENCIMA_PCT":
            cumple = precio_azzorti >= precio_competencia * (1 + umbral / 100)
        else:
            cumple = None
        cumplimiento = {
            "tiene_politica": True,
            "tipo": tipo,
            "umbral_pct": umbral,
            "cumple": cumple,
        }

    alerta_generica = abs(delta_pct) > UMBRAL_ALERTA_GENERICA_PCT

    return {
        "captura_id": captura_id,
        "azzorti_sku": azzorti["sku"],
        "precio_azzorti": precio_azzorti,
        "precio_competencia": precio_competencia,
        "delta_pct": round(delta_pct, 1),
        "cumplimiento_politica": cumplimiento,
        "alerta_generica_10pct": {
            "umbral_pct": UMBRAL_ALERTA_GENERICA_PCT,
            "disparada": alerta_generica,
        },
    }
