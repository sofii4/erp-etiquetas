#!/bin/bash
cd "$(dirname "$0")/.."
echo "⚠️  ATENÇÃO: este script APAGA o banco Oracle local (volume oracle_data)."
echo "   No próximo start os scripts de docker/oracle/init rodam de novo."
read -p "Confirmar reset? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "❌ Operação cancelada"
    exit 1
fi

docker compose down -v
echo "🗑️  Reset executado"
