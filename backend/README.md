# RAG Chat Backend

This folder contains the smallest backend layer needed for the frontend chat to
ask the PoC RAG/LLM endpoint.

## Included Files

- `api/chat.php`: accepts chat questions via `POST`.
- `api/case_attachments.php`: stores uploaded chat underlag in MySQL so it can
  be used as case context.
- `api/reformat_files.php`: prepares the next human-in-the-loop workflow where
  a municipality file can be matched against a DIGG-style template and
  reformatted into a draft for review.
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

## Human-in-the-loop Reformat API

`api/reformat_files.php` is the backend foundation for the next Overleaf-style
workflow:

- left side: DIGG-style template;
- right side: municipality material reformatted as a review draft;
- button action: frontend calls the backend to "reformat" the file;
- human review: the result is saved as `draft` until a person approves or
  rejects it.

Supported first-step matches:

- `openapi.yaml` / `openapi.yml`
- `json`
- `xml`
- `p12`, `pfx`, `cer`, `crt`, `pem` certificate metadata

Certificate files are treated carefully. The backend does not store certificate
content for `.p12`, `.pfx`, `.cer`, `.crt`, or `.pem`; it creates a metadata
checklist instead. Do not send private keys, passwords, or real certificate
content into the AI chat.

Fetch templates:

```text
GET backend/api/reformat_files.php?action=templates
```

Create a reformat draft:

```json
{
  "case_id": "case-123",
  "template_key": "openapi_yaml",
  "file": {
    "name": "openapi.yaml",
    "type": "text/yaml",
    "size": 1200,
    "preview": "openapi: 3.0.3\ninfo:\n  title: Example API\npaths: {}\n"
  }
}
```

You can also reformat a file that was already stored through
`case_attachments.php`:

```json
{
  "case_id": "case-123",
  "attachment_id": 12,
  "template_key": "json_payload"
}
```

Fetch drafts for a case:

```text
GET backend/api/reformat_files.php?case_id=case-123
```

Approve or reject after human review:

```json
{
  "action": "approve",
  "job_id": 1
}
```

or:

```json
{
  "action": "reject",
  "job_id": 1
}
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
