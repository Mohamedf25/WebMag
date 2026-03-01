@echo off
echo Iniciando WooCommerce Image Sync...
echo.
pip install requests >nul 2>&1
python "%~dp0woo_image_sync.py"
pause
