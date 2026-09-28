# RAG Chat Backend

This folder contains the smallest backend layer needed for the frontend chat to
ask the PoC RAG/LLM endpoint.

## Included Files

- `api/chat.php`: accepts chat questions via `POST`.
- `api/db.php`: connects to MySQL and allows local CORS from the frontend.
- `api/embedding_lib.php`: uses Mistral embeddings when an index exists.
- `api/config.php`: reads settings from environment variables or
  `local.secrets.php`.
- `api/local.secrets.example.php`: template for local secrets.
- `data/schema_and_seed_student.sql`: tables and starter RAG sources.

## Local Setup

1. Import `data/schema_and_seed_student.sql` into your MySQL/phpMyAdmin
   database.
2. Copy `api/local.secrets.example.php` to `api/local.secrets.php`.
3. Add your database credentials and Mistral API key in
   `api/local.secrets.php`.
4. Start the backend from the repository root:

```bash
php -S 127.0.0.1:8080 -t backend
```

The frontend chat calls:

```text
http://127.0.0.1:8080/api/chat.php
```

## Important

Never commit real API keys or database passwords to GitHub.
`api/local.secrets.php` is ignored by Git.
