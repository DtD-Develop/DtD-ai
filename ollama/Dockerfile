FROM ollama/ollama:latest

COPY ./backend/ollama-init.sh /ollama-init.sh
RUN chmod +x /ollama-init.sh

ENTRYPOINT ["/ollama-init.sh"]
