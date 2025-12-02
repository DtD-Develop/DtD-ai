#!/bin/sh
set -e

echo "Starting Ollama service..."
/bin/ollama serve &

# Wait for service to be ready
sleep 5

echo "Pulling required models (only if not exists)..."
ollama list | grep -q "llama3.1:8b" || ollama pull llama3.1:8b
ollama list | grep -q "nomic-embed-text" || ollama pull nomic-embed-text

echo "Ollama is ready!"
wait
