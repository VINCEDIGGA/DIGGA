# DIGG-A

Frontend for Ena Agents with a need-driven V3 flow for DIGG/ENA onboarding.

## V3 Flow

- `index.html` stays as a clean chat page.
- `poc/steg-for-steg.html` starts the V3 flow with the municipality need and
  optional supporting files.
- The guide flow can also start from the case menu: `Ansluta till hjälp` ->
  `poc/steg-for-steg.html`.
- The guide page creates a case, asks the backend for a specific guide, and
  stores the need/material list locally with that case.
- `poc/steg-for-steg.html` shows a contextual six-step anslutningsresa when it
  is opened from a V3 case.
- Chat cases and messages are stored locally in the browser.
- Assistant answers are fetched from the PoC RAG/LLM backend configured in
  `js/config.js`. The default endpoint is the relative path
  `backend/api/chat.php`, which is suitable when the whole project is uploaded
  to one PHP-capable school environment.
- Example PDF knowledge about nationella grunddata is extracted into
  `backend/data/schema_and_seed_student.sql`, so it becomes available after the
  normal phpMyAdmin import.
- Never put LLM API keys in the frontend. Keys belong in backend configuration.

## Backend

A minimal PHP backend is included in `backend/`. It contains the chat endpoint,
database connection, embedding retrieval, and a student-friendly schema/seed
file. See `backend/README.md` for local setup.

## School Upload Checklist

The static UI and guided checklist work on ordinary web hosting. The AI chat
requires PHP and MySQL.

1. Upload the project folder with `index.html`, `css/`, `js/`, `poc/`, and
   `backend/` kept in the same relative structure.
2. Import `backend/data/schema_and_seed_student.sql` into the school MySQL
   database.
3. Copy `backend/api/local.secrets.example.php` to
   `backend/api/local.secrets.php` on the server.
4. Add the school database credentials and Mistral API key in
   `backend/api/local.secrets.php`.
5. Keep `js/config.js` as `ragChatEndpoint: "backend/api/chat.php"` if the
   backend folder is uploaded next to `index.html`. Change that value only if
   the school server places the API somewhere else.

If PHP, MySQL, or the API key is missing, the app still opens and the guide
still works. The chat will show a clear message that AI answers are not active
in that environment.

## Learning Links

- CI/CD: https://www.youtube.com/watch?v=AknbizcLq4w
- System design: https://www.youtube.com/watch?v=SE2KF-vxvS0
- Docker: https://www.youtube.com/watch?v=3c-iBn73dDE
- MCP: https://www.youtube.com/watch?v=oblaHqULUHk

## Quick Git And GitHub Guide

### Essential Commands

| Command | Action | Watch Out For |
| --- | --- | --- |
| `git status` | Shows modified and staged files | Always run this first |
| `git add <file>` | Stages files for commit | Avoid `git add .` without a `.gitignore` |
| `git commit -m "msg"` | Saves a local snapshot | Keep messages clear and descriptive |
| `git push origin <branch>` | Uploads local commits to GitHub | Do not use `--force` on shared branches |
| `git pull origin <branch>` | Downloads and merges remote changes | Can trigger merge conflicts |
| `git merge <branch>` | Combines another branch into yours | Watch out for overlapping edits |

### Branch And PR Routine

Do not push directly to `main` on shared work. Use a branch and a pull request:

```bash
git pull origin main
git checkout -b feat/my-work
git status
git add <file>
git commit -m "feat: short description"
git push -u origin feat/my-work
```

Then open a pull request on GitHub for review and merging.

### Merge Conflicts

A merge conflict happens when two branches change the same line differently.
Git pauses the merge and adds conflict markers inside the file. The markers
start with strings such as `<<<<<<<`, `=======`, and `>>>>>>>`.

To resolve it, edit the file, keep the correct code, delete the markers, run
`git add <file>`, and commit. If you need to cancel the merge, run:

```bash
git merge --abort
```
