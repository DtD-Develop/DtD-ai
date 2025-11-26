AI Platform scaffold v2

What's new in v2:
- Laravel backend customized to forward queries to Qdrant and Ollama (stubs provided).
- Ingestion microservice (Python) with chunking, metadata and embedding pipeline.
- Redis + Laravel Horizon for background ingestion jobs.
- MySQL service for Laravel (example).
- Nginx notes and Let's Encrypt instructions.
- GitHub Actions CI skeleton.

Important local sample document (already on your VM):
file:///mnt/data/AI_Development_Plan.docx

Quick start (on your VM):
1. Place this repo on your VM: /srv/ai_platform (or similar)
2. Build and start containers:
   docker compose up --build -d
3. Enter backend container and run:
   composer install
   php artisan key:generate
   php artisan migrate
4. Visit:
   - Frontend: http://YOUR_VM_IP:3000
   - Backend API: http://YOUR_VM_IP:8000

Notes:
- In production, do NOT expose Qdrant or Ollama to the public internet.
- Use nginx reverse proxy with TLS (see nginx/ and README section below).
- To process an uploaded file, the Laravel backend will dispatch a job to Horizon which calls the ingestion microservice (or triggers its API).

Let's Encrypt / nginx (brief):
- Use the nginx reverse proxy to terminate TLS.
- Use certbot on the host or in a companion container to request certs for your domain.
- Example steps:
  1. Install nginx on the host or use the nginx container.
  2. Configure server_name and proxy to frontend/backend.
  3. Run certbot to obtain certificates and reload nginx.
