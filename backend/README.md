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
- `data/schema_and_seed_student.sql`: tables, starter RAG sources, and V3 PDF
  source summaries for nationella grunddata.

## Local Setup

1. Import `data/schema_and_seed_student.sql` into your MySQL/phpMyAdmin
   database.
2. Copy `api/local.secrets.example.php` to `api/local.secrets.php`.
3. Add your database credentials and Mistral API key in
   `api/local.secrets.php`.
4. For the full app and backend together, start PHP from the repository root:

```bash
php -S 127.0.0.1:8091 -t .
```

Open:

```text
http://127.0.0.1:8091/index.html
```

The frontend chat calls the relative endpoint:

```text
backend/api/chat.php
```

If you prefer to run only the backend separately for development, start it from
the repository root:

```bash
php -S 127.0.0.1:8080 -t backend
```

Then edit `js/config.js` during local development so it points to:

```text
http://127.0.0.1:8080/api/chat.php
```

Switch `js/config.js` back to `backend/api/chat.php` before uploading the whole
project to one PHP-capable school environment.

## School Upload

Upload the `backend/` folder next to `index.html`, import
`data/schema_and_seed_student.sql`, and create `api/local.secrets.php` on the
server with the school database credentials and Mistral API key. Do not commit
or publish real secrets.

The SQL seed already includes concise rows extracted from the example PDF
folder. The original PDFs do not need to be parsed by the school server.

## Important

Never commit real API keys or database passwords to GitHub.
`api/local.secrets.php` is ignored by Git.
