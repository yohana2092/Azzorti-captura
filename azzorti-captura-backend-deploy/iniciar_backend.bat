@echo off
title Azzorti Backend - NO CERRAR ESTA VENTANA
cd /d "%~dp0"

:inicio
echo ============================================
echo  Azzorti Benchmarking - Backend
echo  %date% %time%
echo  Si esta ventana se cierra, el dashboard y la
echo  app dejan de recibir datos. Dejala abierta.
echo ============================================
python -m uvicorn server:app --host 0.0.0.0 --port 8000

echo.
echo El backend se detuvo. Reiniciando en 3 segundos...
echo (si esto se repite seguido, avisa a Claude / revisa el error de arriba)
timeout /t 3 /nobreak >nul
goto inicio
