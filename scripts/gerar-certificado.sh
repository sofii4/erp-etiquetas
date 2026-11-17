#!/bin/bash
# Gera um certificado HTTPS autoassinado para o nginx (docker/nginx/certs).
#
# Por que: o navegador só libera a câmera (leitor de QR) em https:// ou em
# localhost. Para testar pelo celular na rede local, acesse
# https://<ip-da-vm>:8191 e aceite o aviso de certificado uma vez.
#
# Uso: scripts/gerar-certificado.sh [ip-ou-nome-extra ...]
#   Sem argumentos, inclui localhost e os IPs atuais da máquina.
#   Rode de novo (e reinicie o nginx) se o IP da VM mudar.
set -e
cd "$(dirname "$0")/.."

DIR=docker/nginx/certs
mkdir -p "$DIR"

SAN="DNS:localhost,IP:127.0.0.1"
for ip in $(hostname -I 2>/dev/null); do
    case "$ip" in *:*) continue ;; esac   # ignora IPv6
    SAN="$SAN,IP:$ip"
done
for extra in "$@"; do
    if [[ "$extra" =~ ^[0-9.]+$ ]]; then SAN="$SAN,IP:$extra"; else SAN="$SAN,DNS:$extra"; fi
done

openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -keyout "$DIR/erp.key" -out "$DIR/erp.crt" \
    -subj "/CN=Modulo de Etiquetas (dev)" \
    -addext "subjectAltName=$SAN" 2>/dev/null
# O nginx (master roda como root) lê a chave; não precisa ser legível por outros.
chmod 600 "$DIR/erp.key"

echo "🔒 Certificado gerado em $DIR (válido para: $SAN)"
