#!/bin/bash
set -e
cd "$(dirname "$0")/.."

echo "🚀 Iniciando Módulo de Etiquetas (ERP)"

if [ ! -f .env ]; then
    echo "❌ Arquivo .env não encontrado!"
    echo "📝 Copie .env.docker.example para .env e configure as variáveis"
    exit 1
fi

echo "🔨 Building images..."
docker compose build

echo "📦 Starting containers (o Oracle pode levar ~1 min no primeiro boot)..."
docker compose up -d --wait

docker compose ps

PORTA=$(grep -E '^WEB_HOST_PORT=' .env | cut -d= -f2)
echo ""
echo "✅ Sistema disponível em: http://localhost:${PORTA:-8190}"
echo "📊 Logs: docker compose logs -f"
