# DIGG-A

Frontend for Ena Agents with a first careful integration of the DIGG/ENA proof
of concept into the existing UI.

## PoC Integration In This Version

- `index.html` is still the existing chat page.
- The help flow starts from the case menu: `Ansluta till hjälp` ->
  `poc/steg-for-steg.html`.
- `poc/steg-for-steg.html` is intentionally blank for now.
- Chat cases and messages are stored locally in the browser.
- Assistant answers are fetched from the PoC RAG/LLM backend:
  `http://127.0.0.1:8080/api/chat.php`.
- Never put LLM API keys in the frontend. Keys belong in backend configuration.

## Backend

A minimal PHP backend is included in `backend/`. It contains the chat endpoint,
database connection, embedding retrieval, and a student-friendly schema/seed
file. See `backend/README.md` for local setup.

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
