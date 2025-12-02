#!/bin/sh
ollama list | grep "llama3.1:8b" > /dev/null || ollama pull llama3.1:8b
ollama list | grep "nomic-embed-text" > /dev/null || ollama pull nomic-embed-text
exec ollama serve
