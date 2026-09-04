# Azzorti Benchmarking — Backend (para subir al servidor)

Copia autocontenida del backend Python de `Azzorti-captura` (sin la BD ni
las fotos generadas — eso lo recrea el servidor solo al arrancar). Vive
**aparte** del backend PHP real (`ventas_v1`, `vincu_v1`, etc.): no lo toca,
no comparte base de datos, corre en su propio puerto (8000).

## Qué contiene

- `server.py` — la API completa (FastAPI + SQLite embebida).
- `requirements.txt` — dependencias Python.
- `iniciar_backend.bat` — arranque manual en Windows (ventana abierta).
- `deploy/azzorti-backend.service` — unit de `systemd` para Linux (arranca
  solo, se reinicia si crashea).

## Requisitos en el servidor

- **Python 3.9+**.
- **Tesseract OCR** instalado a nivel de sistema (el paquete `pytesseract`
  solo lo invoca, no lo trae):
  - Linux (Debian/Ubuntu): `apt install tesseract-ocr`
  - Windows: instalador de [UB-Mannheim](https://github.com/UB-Mannheim/tesseract/wiki)
    (el código ya intenta la ruta default de ese instalador si no está en `PATH`).
- PyMuPDF (`fitz`) no necesita nada aparte, viene autocontenido.

## Pasos

```bash
python3 -m venv venv
source venv/bin/activate        # Windows: venv\Scripts\activate
pip install -r requirements.txt
uvicorn server:app --host 0.0.0.0 --port 8000
```

### Como servicio (Linux, recomendado para dejarlo corriendo solo)

1. Copiar esta carpeta a algo como `/opt/azzorti-backend`.
2. Crear el venv e instalar dependencias ahí (ver arriba).
3. Ajustar en `deploy/azzorti-backend.service` las 3 rutas/usuario que
   digan `azzorti` / `/opt/azzorti-backend` por lo que corresponda en tu
   servidor.
4. `sudo cp deploy/azzorti-backend.service /etc/systemd/system/`
5. `sudo systemctl daemon-reload && sudo systemctl enable --now azzorti-backend`

## Antes de dejarlo expuesto de verdad (no solo demo en LAN)

Esto **no está endurecido para producción** — lo dejo anotado para que no
se pierda de vista:

- `CORS allow_origins=["*"]` — abierto a cualquiera, sin restricción de origen.
- **Sin autenticación** en ningún endpoint.
- Los datos de `azzorti_producto` / `politica_precio` son de **muestra**,
  no el archivo SID real (lo aclara `/info` en la propia API).
- Si el servidor va a quedar accesible por internet (no solo LAN), como
  mínimo habría que ponerle HTTPS (reverse proxy nginx/Apache + certificado)
  y algo de auth antes del deploy final.

## Si querés que "se sienta" parte del backend HMVC sin reescribirlo

Se puede exponer bajo una ruta con cara de HMVC vía reverse proxy, sin
tocar el PHP ni este código, por ejemplo en nginx:

```nginx
location /hmvc/captura_v1/ {
    proxy_pass http://127.0.0.1:8000/;
    proxy_set_header Host $host;
}
```

Así desde afuera la URL luce como un módulo más del backend real, aunque
por dentro sigue siendo este servicio Python independiente.
