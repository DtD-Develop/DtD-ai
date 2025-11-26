Production Checklist
====================

1) Domain & DNS
- Point your domain A record to your VM public IP.

2) Nginx reverse proxy
- Use the nginx/default.conf as a starting point.
- Install nginx on the host or run in a container and mount certs.

3) Obtain Let's Encrypt certificates
- Install certbot on host, then:
  sudo apt update
  sudo apt install certbot python3-certbot-nginx
  sudo certbot --nginx -d your.domain.com

4) Firewall rules (ufw example)
- Allow only ports:
  sudo ufw allow OpenSSH
  sudo ufw allow 'Nginx Full'    # 80,443
  sudo ufw deny 6333             # Qdrant
  sudo ufw deny 11434            # Ollama
  sudo ufw enable

5) Internal-only services
- Ensure qdrant and ollama are not public. Use cloud firewalls to restrict access.

6) Backups
- Periodic snapshot of qdrant_data and ollama_data volumes.
