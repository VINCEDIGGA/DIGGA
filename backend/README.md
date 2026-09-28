# Backend för RAG-chatten

Det här är den minsta backenddelen som behövs för att vänprojektets chatt ska
kunna fråga PoC:ns RAG/LLM-endpoint.

## Vad som ingår

- `api/chat.php`: tar emot chatfrågor via `POST`.
- `api/db.php`: ansluter till MySQL och tillåter lokal CORS från frontend.
- `api/embedding_lib.php`: använder Mistral embeddings om de finns byggda.
- `api/config.php`: läser inställningar från miljövariabler eller
  `local.secrets.php`.
- `api/local.secrets.example.php`: mall för lokala hemligheter.
- `data/schema_and_seed_student.sql`: tabeller och startkällor för RAG.

## Kom igång lokalt

1. Importera `data/schema_and_seed_student.sql` i din MySQL/phpMyAdmin-databas.
2. Kopiera `api/local.secrets.example.php` till `api/local.secrets.php`.
3. Fyll i databasuppgifter och Mistral API-nyckel i `api/local.secrets.php`.
4. Starta backend från repo-roten:

```bash
php -S 127.0.0.1:8080 -t backend
```

Frontendchatten anropar då:

```text
http://127.0.0.1:8080/api/chat.php
```

## Viktigt

Lägg aldrig riktiga API-nycklar eller databaslösenord i GitHub. Filen
`api/local.secrets.php` ignoreras av Git.
