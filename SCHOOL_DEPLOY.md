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

This import also adds V3 source summaries extracted from the example PDF folder
and creates the chat upload context table `case_attachments`.

If you already imported an older SQL version, import this file again or add the
new table from the top of `schema_and_seed_student.sql`.

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
  stepContextEndpoint: "backend/api/step_context.php",
  caseAttachmentsEndpoint: "backend/api/case_attachments.php",
};
```

Keep this value if `backend/` is uploaded next to `index.html`.

## Upload Context

Chat file uploads are stored as database context, not sent directly to the AI.
Text-like files such as `.txt`, `.json`, `.yaml`, `.yml`, `.xml`, `.csv`, and
`.md` store a text preview. PDF and binary files store filename, type, and size;
paste the important PDF excerpt into the chat or underlag form if the AI needs
to reason over the contents.
