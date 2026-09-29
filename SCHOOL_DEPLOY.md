# School Deployment

Use this checklist after uploading the project to a PHP capable school server.

## Upload

Upload the whole project folder and keep this structure:

```text
index.html
css/
js/
poc/
backend/
```

## Database

1. Open phpMyAdmin.
2. Create or choose your course database.
3. Import `backend/data/schema_and_seed_student.sql`.

This import also adds V3 source summaries extracted from the example PDF folder.

## Secrets

1. Copy `backend/api/local.secrets.example.php`.
2. Rename the copy to `backend/api/local.secrets.php`.
3. Fill in the database credentials and Mistral API key.

Do not upload real keys to GitHub.

## Test

Open this URL on the school server:

```text
backend/api/health.php
```

Expected result:

```json
{
  "ok": true,
  "database": {
    "connected": true
  },
  "llm": {
    "configured": true
  }
}
```

If `database.connected` is false, check the database host, name, username, and
password in `local.secrets.php`.

If `llm.configured` is false, add the Mistral API key in `local.secrets.php`.

## Frontend Endpoint

The frontend reads `js/config.js`. The default is:

```js
window.DIGGA_CONFIG = {
  ragChatEndpoint: "backend/api/chat.php",
};
```

Keep this value if `backend/` is uploaded next to `index.html`.
