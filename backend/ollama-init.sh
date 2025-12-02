#!/bin/sh
set -e

echo "Starting Ollama service in background..."
/bin/ollama serve &

# รอให้ service พร้อมก่อนดึง model
sleep 3

echo "Pulling models..."
/bin/ollama pull llama3.1:8b || true
/bin/ollama pull nomic-embed-text || true

echo "Ollama Init Completed. Keeping container alive..."
# foreground wait เพื่อไม่ให้ container exit
wait
