#!/bin/bash
set -e
cd "$(dirname "$0")/.."

echo "🚀 Iniciando Módulo de Etiquetas (ERP)"

if [ ! -f .env ]; then
    echo "❌ Arquivo .env não encontrado!"
    echo "📝 Copie .env.docker.example para .env e configure as variáveis"
    exit 1
fi

# Certificado HTTPS (câmera do leitor de QR). Gerado uma vez só.
if [ ! -f docker/nginx/certs/erp.crt ]; then
    scripts/gerar-certificado.sh
fi

echo "🔨 Building images..."
docker compose build

echo "📦 Starting containers (o Oracle pode levar ~1 min no primeiro boot)..."
docker compose up -d --wait

docker compose ps

PORTA=$(grep -E '^WEB_HOST_PORT=' .env | cut -d= -f2)
echo ""
PORTA_HTTPS=$(grep -E '^WEB_HTTPS_PORT=' .env | cut -d= -f2)
echo "✅ Sistema disponível em: http://localhost:${PORTA:-8190}"
echo "🔒 HTTPS (leitor de QR pelo celular): https://<ip-da-vm>:${PORTA_HTTPS:-8191}"
echo "📊 Logs: docker compose logs -f"
