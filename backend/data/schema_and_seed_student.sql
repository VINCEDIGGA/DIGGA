-- Student phpMyAdmin import file.
-- Use this when your database already exists, for example wisi2403.
-- Do not add CREATE DATABASE or USE statements on shared student hosting.

CREATE TABLE IF NOT EXISTS reference_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  service VARCHAR(80) NOT NULL,
  source_url VARCHAR(700) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reference_source (source_url(255)),
  FULLTEXT KEY ft_reference (title, service, content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_embeddings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  model VARCHAR(80) NOT NULL,
  text_hash CHAR(64) NOT NULL,
  embedding JSON NOT NULL,
  vector_norm DOUBLE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_document_embedding (document_id, model),
  INDEX idx_embedding_model (model),
  INDEX idx_embedding_hash (text_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question TEXT NOT NULL,
  answer MEDIUMTEXT NOT NULL,
  source_ids JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS validation_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile VARCHAR(32) NOT NULL,
  detected_format VARCHAR(16) NOT NULL,
  is_valid TINYINT(1) NOT NULL,
  issues JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_organizations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL DEFAULT 'Mock kommun',
  org_number VARCHAR(32) NOT NULL DEFAULT '',
  public_actor_type VARCHAR(120) NOT NULL DEFAULT 'Kommunal myndighet',
  contact_name VARCHAR(255) NOT NULL DEFAULT '',
  contact_email VARCHAR(255) NOT NULL DEFAULT '',
  contact_phone VARCHAR(80) NOT NULL DEFAULT '',
  it_contact VARCHAR(255) NOT NULL DEFAULT '',
  business_owner VARCHAR(255) NOT NULL DEFAULT '',
  peppol_id VARCHAR(120) NOT NULL DEFAULT '',
  supplier_strategy VARCHAR(255) NOT NULL DEFAULT 'Egen PoC med framtida leverantörsval',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS readiness_progress (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  service VARCHAR(32) NOT NULL,
  item_key VARCHAR(80) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'todo',
  note TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_readiness_item (organization_id, service, item_key),
  INDEX idx_readiness_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evidence_exports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  payload JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_evidence_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id VARCHAR(80) NOT NULL,
  source_system VARCHAR(120) NOT NULL,
  target_system VARCHAR(120) NOT NULL,
  profile VARCHAR(32) NOT NULL,
  mode VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  source_payload JSON NOT NULL,
  transformed_payload JSON NOT NULL,
  validation_result JSON NOT NULL,
  delivery_result JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_integration_run_id (run_id),
  INDEX idx_integration_created (created_at),
  INDEX idx_integration_profile (profile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mock_source_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id VARCHAR(80) NOT NULL,
  case_type VARCHAR(160) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  sender JSON NOT NULL,
  recipient JSON NOT NULL,
  person JSON NOT NULL,
  attachments JSON NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'ready',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mock_source_case (case_id),
  INDEX idx_mock_source_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reference_documents (title, service, source_url, content)
VALUES
  (
    'Anslut till Mina meddelanden',
    'Mina meddelanden',
    'https://www.digg.se/digitala-tjanster/digital-post/digital-post-for-dig-som-offentlig-aktor/anslut',
    'Offentliga aktörer som vill skicka digital post via Mina meddelanden följer en stegvis anslutningsprocess: förberedelser, ansökan, avtal, tekniska tester och rutiner efter anslutning. Avtal krävs för både infrastrukturen Mina meddelanden och auktorisationssystemet för digital post. Tekniska tester ska genomföras innan produktion.'
  ),
  (
    'Teknisk beskrivning av digital post',
    'Mina meddelanden',
    'https://www.digg.se/digitala-tjanster/digital-post/digital-post-for-dig-som-brevladeoperator/teknisk-beskrivning-av-digital-post',
    'Digital post-infrastrukturen, även kallad Mina meddelanden, omfattar tjänster hos förmedlare, Digg och brevlådeoperatörer. Avsändaren ansvarar för att skapa försändelser enligt tekniskt tjänstekontrakt, signera och försegla korrekt, kontrollera mottagarens digitala brevlåda via Recipient och skicka till rätt brevlåda. Centrala tjänster är Recipient, Message, Authority och Service.'
  ),
  (
    'Tjänstekontrakt och API-specifikationer',
    'Mina meddelanden',
    'https://www.digg.se/digitala-tjanster/digital-post/digital-post-for-dig-som-offentlig-aktor/teknisk-beskrivning-av-digital-post/tjanstespecifikation/tjanstekontrakt---api-specifikationer',
    'Tekniska tjänstekontrakt är en central del av underlaget för anslutning till Mina meddelanden. De riktar sig till teknisk personal hos offentliga aktörer och leverantörer och beskriver meddelandeflöden, kvittenser, händelsehantering och felkoder. Fullständiga API-specifikationer finns uppdelade efter roll.'
  ),
  (
    'SDK adressbok',
    'SDK',
    'https://www.digg.se/saker-digital-kommunikation/sdk-for-deltagarorganisationer/anslut-som-deltagarorganisation/sdk-adressbok',
    'SDK adressbok samlar anslutna organisationers funktionsadresser. Meddelanden adresseras till funktioner, inte till enskilda personer. Organisationen ansvarar för att bemanna, registrera och uppdatera sina funktionsadresser. SDK-anslutna deltagarorganisationer ska vara registrerade hos Digg i SMP och uppfylla krav på informationssäkerhet och tillit.'
  ),
  (
    'SDK Innehållsspecifikation Meddelande',
    'SDK',
    'https://www.digg.se/saker-digital-kommunikation/sdk-for-leverantorer-av-meddelandesystem/tekniska-specifikationer/sdk-tekniska-specifikationer/sdk-innehallsspecifikation-meddelande',
    'SDK-komponenter som meddelandesystem och klienter ska kunna hantera SDK-meddelanden. SDK-meddelandet paketeras med XHE och MessageType. Innehållsvalidering ska säkerställa problemfri kommunikation mellan parter i SDK-federationen. Meddelandetjänst ska validera SDK-meddelanden med schema och schematron på utgående och inkommande försändelser. Inkommande valideringsfel hanteras med kvittensmeddelande enligt Diggs specifikation.'
  ),
  (
    'DIGG digitala tjänster och datadelningsstöd',
    'DIGG',
    'https://www.digg.se/digitala-tjanster',
    'DIGG:s digitala tjänster samlar befintliga och kommande tjänster för offentliga aktörer och leverantörer. Listan omfattar bland annat Säker digital kommunikation, Infrastruktur för digital post - Mina meddelanden, API-hantering, Sveriges dataportal, Hinderbanan, Publicera specifikation för data och andra stöd för datadelning.'
  ),
  (
    'Hinderbanan - generell metod för informationsutbyte',
    'DIGG',
    'https://www.digg.se/digitala-tjanster/generell-metod-for-informationsutbyte-mellan-myndigheter---hinderbanan',
    'Hinderbanan är DIGG:s metodstöd för digitalt informationsutbyte mellan myndigheter. Metoden lyfter sex fokusområden: syfte, behov och mål; organisation; juridiska förutsättningar; krav och lösning; utveckling och test; införande och förvaltning. Krav och lösning omfattar datainnehåll och struktur, informationssäkerhet, säkerhet och behörighet, tekniska gränssnitt och loggning, kanalstrategi och tillgänglighet, återanvändning och standardisering samt källa och format.'
  ),
  (
    'REST API-profil',
    'Dataportal',
    'https://www.dataportal.se/rest-api-profil',
    'REST API-profilen är en förvaltningsgemensam specifikation och rekommendation för offentliga aktörer. Den samlar bästa praxis och SKALL/BÖR/KAN-krav för att skapa REST API:er med högre kvalitet, bättre dokumentation och bättre interoperabilitet mellan producenter och konsumenter. Kompletterande resurser som avstämningsfil och lint-verktyg kan användas för uppföljning mot profilen.'
  ),
  (
    'En introduktion till REST',
    'Dataportal',
    'https://www.dataportal.se/api-playbook/en-introduktion-till-rest',
    'Dataportalens API-playbook beskriver API:er som gränssnitt för informationsutbyte mellan applikationer. Ett API är inte en databas eller server utan accesspunkten till information. För att använda ett API bör man hitta ett dokumenterat och underhållet API, läsa dokumentation, förstå autentisering och åtkomst, förstå datastruktur och anrop samt testa med verktyg som Postman eller JQ. REST bygger normalt på HTTP och tillståndslösa anrop.'
  ),
  (
    'Sveriges dataportal',
    'Dataportal',
    'https://www.dataportal.se/',
    'Sveriges dataportal är ett nationellt nav för att hitta, utforska och använda data från offentlig, privat och civilsamhällessektor. Portalen innehåller metadata och länkar till åtkomstpunkter; själva datamängderna hämtas hos organisationen som ansvarar för dem. Portalen kan användas för att hitta data och API:er som en integration kan bygga på.'
  ),
  (
    'DCAT-AP-SE Processor',
    'diggsweden GitHub',
    'https://github.com/diggsweden/DCAT-AP-SE-Processor',
    'DCAT-AP-SE Processor automatiserar framställning av metadata enligt DCAT-AP-SE för publicering av datamängder och API:er på Sveriges dataportal. Verktyget tar OpenAPI, RAML eller separata metadatafiler som input och genererar RDF redo för skördning. Det kan köras i UI, REST API, CLI eller integreras i CI/CD. Arbetsprocessen omfattar skördningskälla, metadata i API-definition eller separat fil, generering av RDF, skördning och verifiering.'
  ),
  (
    'REST API-profil Lint Processor',
    'diggsweden GitHub',
    'https://github.com/diggsweden/rest-api-profil-lint-processor',
    'REST API-profil Lint Processor, RAP-LP, validerar OpenAPI v3-specifikationer mot den svenska REST API-profilen med hjälp av Spectral. Verktyget kan köras lokalt i CLI-läge eller API-läge, via webgränssnitt, npm/npx eller container. Det stödjer regelkategorier för dokumentation, resurser, säkerhet, spårbarhet, felhantering, versionering, filtrering och fler profilområden.'
  ),
  (
    'PoC-guide: Koppla två system steg för steg',
    'PoC guide',
    'local://poc/connection-playbook',
    'Verktygets nybörjarflöde är: 1. fyll i mockorganisation, 2. importera DIGG-källor, API-dokumentation, OpenAPI-filer och mappningsanteckningar, 3. kör Anslutningsagenten för att se vad som saknas för Mina meddelanden och SDK, 4. kör Systemkoppling som dry-run från källpayload till SDK- eller Mina meddelanden-stil payload, 5. skicka osäkra antaganden till Granskning HITL, 6. exportera evidence pack. För riktig QA eller produktion behövs verkliga avtal, ansökan, QA-miljö, testdata, API-nycklar, certifikat/O2O, accesspunkt eller meddelandesystem, loggning, felhantering, säkerhetsklassning och beslut från behöriga personer. Dry-run får inte beskrivas som produktionsanslutning.'
  ),
  (
    'PoC-guide: Från dry-run till riktig QA',
    'PoC guide',
    'local://poc/dry-run-to-qa',
    'För att gå från dry-run till riktig QA för SDK och Mina meddelanden behöver organisationen samla: beslut och ansvarig kontakt, organisationsuppgifter, offentlig aktör-underlag, avtal eller avtalsägare, DIGG-ansökningsunderlag, tekniskt vägval, källsystemets API eller exportformat, målsystemets QA-endpoint eller leverantörsväg, API-dokumentation och OpenAPI, fältmappning, testdata utan skarpa personuppgifter, säkerhetsklassning, loggning och felhantering, certifikat eller O2O-plan för SDK, funktionsadresser, accesspunkt eller meddelandesystem, tekniska testfall, kvittohantering och HITL-godkännande. Live-sändning får bara aktiveras när QA-miljö, credentials, endpoint, avtal och testdatapolicy är klara.'
  )
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  service = VALUES(service),
  content = VALUES(content),
  updated_at = CURRENT_TIMESTAMP;

INSERT INTO mock_source_cases
  (case_id, case_type, subject, body, sender, recipient, person, attachments, status)
VALUES
  (
    'SOC-2026-00042',
    'Orosanmälan enligt SoL 14',
    'Begäran om säker komplettering',
    'Detta är syntetisk PoC-data från mockorganisationens sociala ärendesystem. Ingen skarp personuppgift används.',
    '{"organisationNumber":"2120000000","functionAddress":"socialnamnd@mock.sdk","name":"Mock kommun socialnämnd"}',
    '{"organisationNumber":"2021006883","functionAddress":"registrator@digg.mock.sdk","name":"Mottagande funktion"}',
    '{"personId":"190001019999","displayName":"Testperson Social"}',
    '[{"fileName":"soc-case-summary.txt","contentType":"text/plain","content":"U3ludGV0aXNrIHNvY2lhbHRqw6Ruc3RiaWxhZ2EgZsO2ciBQb0MtdmFsaWRlcmluZy4="}]',
    'ready'
  ),
  (
    'EDU-2026-00118',
    'Skolärende - frånvarounderlag',
    'Komplettering av frånvarounderlag',
    'Syntetiskt underlag från skolans verksamhetssystem för att visa hur ett annat källsystem kan anslutas.',
    '{"organisationNumber":"2120000000","functionAddress":"utbildning@mock.sdk","name":"Mock kommun utbildningsförvaltning"}',
    '{"organisationNumber":"2321000016","functionAddress":"elevhalsa@region.mock.sdk","name":"Regional elevhälsa"}',
    '{"personId":"201201019999","displayName":"Testperson Elev"}',
    '[{"fileName":"attendance-summary.txt","contentType":"text/plain","content":"U3ludGV0aXNrdCBmcsOlbnZhcm91bmRlcmxhZyBmw7ZyIFBvQy12YWxpZGVyaW5nLg=="}]',
    'ready'
  ),
  (
    'ADM-2026-00007',
    'Administrativ komplettering',
    'Begäran om kompletterande organisationsuppgift',
    'Syntetiskt administrativt ärende utan känsligt innehåll. Används för en lugn Mina meddelanden-demo.',
    '{"organisationNumber":"2120000000","functionAddress":"registrator@mock.sdk","name":"Mock kommun registratur"}',
    '{"organisationNumber":"2021006883","functionAddress":"registrator@digg.mock.sdk","name":"Mottagande funktion"}',
    '{"personId":"190001019999","displayName":"Testperson Admin"}',
    '[{"fileName":"admin-note.txt","contentType":"text/plain","content":"U3ludGV0aXNrIGFkbWluaXN0cmF0aXYgYmlsYWdhIGbDtnIgUG9DLXZhbGlkZXJpbmcu"}]',
    'ready'
  )
ON DUPLICATE KEY UPDATE
  case_type = VALUES(case_type),
  subject = VALUES(subject),
  body = VALUES(body),
  sender = VALUES(sender),
  recipient = VALUES(recipient),
  person = VALUES(person),
  attachments = VALUES(attachments),
  status = VALUES(status),
  updated_at = CURRENT_TIMESTAMP;
