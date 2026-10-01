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

CREATE TABLE IF NOT EXISTS guide_step_context (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id VARCHAR(120) NOT NULL,
  step_index INT UNSIGNED NOT NULL,
  check_index INT UNSIGNED NOT NULL,
  step_title VARCHAR(255) NOT NULL,
  check_text VARCHAR(500) NOT NULL,
  organization_name VARCHAR(255) NOT NULL DEFAULT '',
  organization_number VARCHAR(80) NOT NULL DEFAULT '',
  contact_name VARCHAR(255) NOT NULL DEFAULT '',
  contact_email VARCHAR(255) NOT NULL DEFAULT '',
  contact_role VARCHAR(160) NOT NULL DEFAULT '',
  short_description TEXT NULL,
  need_text TEXT NULL,
  application_json MEDIUMTEXT NULL,
  files_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_guide_step_context (case_id, step_index, check_index),
  INDEX idx_guide_step_context_case (case_id)
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
    'SDK betyder Säker digital kommunikation',
    'SDK',
    'https://www.digg.se/saker-digital-kommunikation',
    'I den här anslutningsguiden betyder SDK alltid Säker digital kommunikation. Det ska inte tolkas som Software Development Kit. SDK är ett säkert utbyte mellan organisationer. O2O betyder organisation till organisation. SDK är inte samma spår som Mina meddelanden eller digital post, och Mina meddelanden ska inte föreslås som ersättning när frågan gäller SDK.'
  ),
  (
    'SDK ansvar mellan kommun och leverantör',
    'SDK',
    'https://www.digg.se/saker-digital-kommunikation/sdk-for-deltagarorganisationer/anslut-som-deltagarorganisation',
    'Kommunen eller deltagarorganisationen behöver äga beslut, ansvar, uppföljning och verksamhetsförankring i en SDK-anslutning. En leverantör kan hjälpa med accesspunkt, meddelandesystem, teknisk installation, test, certifikat och felhantering, men leverantören ersätter inte kommunens ansvar. För en liten kommun bör första frågan vara vad leverantören redan kan hantera och vad kommunen själv måste besluta, bemanna och godkänna.'
  ),
  (
    'SDK certifikat och O2O-underlag',
    'SDK',
    'local://sdk/certifikat-o2o-underlag',
    'Vid SDK-frågor om certifikat bör svaret börja enkelt: fråga leverantören vem som praktiskt skapar, installerar och förvaltar certifikat, vilken testmiljö som används, vilket format som krävs och hur byte eller förnyelse görs. Kommunen bör spara ansvarig kontakt, certifikatägare, miljö, giltighetstid, filnamn, format, teststatus och vem som godkänt inför QA eller produktion. Tekniska detaljer ska förklaras först efter den praktiska ansvarsfördelningen.'
  ),
  (
    'SDK exempel på enkelt tekniskt underlag',
    'SDK',
    'local://sdk/exempel-tekniskt-underlag',
    'För en liten kommun kan tekniskt SDK-underlag beskrivas med enkel filstruktur: underlag/api/openapi.yaml för källsystemets API, underlag/mapping/faltmappning.xlsx för källfält till målfält, underlag/testdata/exempel.json för syntetiskt testfall, underlag/certifikat/README.txt för vem som hanterar certifikat och underlag/ansvar/roller.md för ansvarig verksamhet, IT-kontakt och leverantör. Syftet är att göra frågan begriplig innan detaljer som XHE, MessageType eller schematron tas upp.'
  ),
  (
    'SDK anslutning till QA och produktion',
    'SDK',
    'https://www.digg.se/saker-digital-kommunikation/sdk-for-deltagarorganisationer/anslut-som-deltagarorganisation/instruktioner-for-anslutning-till-sdk',
    'Vid anslutning till SDK behöver kommunen se processen som stegvis: avtal och roller, publikt O2O-certifikat i SDK adressbok, notifiering till Digg, publicering i SMP, registrering av funktionsadresser, tillgång till testklient och tester med godkänt resultat innan federationsgodkännande och produktion. För en liten kommun bör checklistan översättas till enkla ägare: vem hos kommunen godkänner, vem hos leverantören installerar och testar, vem registrerar funktionsadresser och vem sparar bevis från QA.'
  ),
  (
    'SDK funktionsadresser för små kommuner',
    'SDK',
    'https://www.digg.se/saker-digital-kommunikation/sdk-for-deltagarorganisationer/anslut-som-deltagarorganisation/sdk-adressbok',
    'SDK adressbok innehåller funktionsadresser, inte personadresser. Kommunen behöver beskriva vilka verksamhetsfunktioner som ska kunna skicka eller ta emot meddelanden, till exempel socialtjänst, utbildning, registratur eller en särskild ärendetyp. Varje funktionsadress behöver bemanning, ansvarig administratör, tydlig funktion, beskrivning, eventuell organisatorisk enhet och rutin för uppdatering. Det går inte att skicka SDK-meddelanden direkt till en enskild person, men ett ärende-id eller personreferens kan finnas i meddelandet när behörighet och informationssäkerhet är löst.'
  ),
  (
    'SDK informationsspecifikation adressbok',
    'SDK',
    'https://www.digg.se/saker-digital-kommunikation/sdk-for-leverantorer-av-meddelandesystem/tekniska-beskrivningar/informationsspecifikation-sdk-adressbok',
    'SDK adressbok har strukturerade uppgifter för deltagarorganisation och funktionsadresser. För en funktionsadress är praktiska fält exempelvis identifier, unitName, name och description. Identifier ska vara unik inom deltagarorganisationen och inte vara skiftlägeskänslig. För små kommuner bör detta göras begripligt som en lista: funktionsnamn, kort beskrivning, intern ägare, eventuell förvaltning, teknisk identifierare och vem som får ändra posten.'
  ),
  (
    'SDK leverantörsfrågor före teknisk implementation',
    'SDK',
    'https://www.digg.se/saker-digital-kommunikation/sdk-for-deltagarorganisationer/vagledningar-och-rekommendationer-infor-anslutning-till-sdk/forberedelser-infor-anslutning-till-sdk/checklistor/checklista-for-anskaffning-av-komponenter-infor-anslutning-till-sdk',
    'När kommunen saknar egen IT bör första tekniska steget vara att fråga leverantören om komponenter och ansvar. Fråga om leverantören stödjer SDK som meddelandesystem eller accesspunkt, vilka miljöer som finns, vem som hanterar certifikat och O2O, hur funktionsadresser kopplas, hur kvittenser och fel loggas, hur testfall körs och vilken dokumentation kommunen får. Svaret ska inte byta till Mina meddelanden bara för att leverantören saknar SDK, utan ska beskriva gapet och möjliga nästa beslut.'
  ),
  (
    'SDK API MT/MK enkel testförståelse',
    'SDK',
    'https://www.digg.se/saker-digital-kommunikation/sdk-for-leverantorer-av-meddelandesystem/tekniska-beskrivningar/apier-for-sdk/sdk-api-mt-mk/sdk-api-mt-mk-implementationsguide',
    'SDK API MT/MK-materialet är tekniskt och riktar sig främst till leverantörer av meddelandetjänst eller meddelandeklient. För en kommunal verksamhet bör det översättas till enklare frågor: vilken testmiljö används, vilken funktionsadress används för test, vilket testmeddelande skickas, hur ser kvittens eller fel ut, och var sparas logg och testbevis. Tekniska endpointar och API-anrop bör visas som exempel först efter att ansvar och testmål är klart.'
  ),
  (
    'DIGG RAP-LP validering av OpenAPI',
    'diggsweden GitHub',
    'https://github.com/diggsweden/rest-api-profil-lint-processor',
    'REST API-profil Lint Processor från diggsweden granskar OpenAPI v3-specifikationer mot den nationella REST API-profilen. Verktyget kan användas via npm, npx, Docker eller Podman och kan ge logg eller avstämningsfil. För små kommuner räcker det ofta att be leverantören lämna openapi.yaml och en kort valideringsrapport: vilka fel finns, vilka varningar finns och vad måste rättas innan integrationen testas.'
  ),
  (
    'DIGG DCAT-AP-SE publicering av API-metadata',
    'diggsweden GitHub',
    'https://github.com/diggsweden/DCAT-AP-SE-Processor',
    'DCAT-AP-SE Processor från diggsweden automatiserar metadata enligt DCAT-AP-SE för datamängder och API:er på Sveriges dataportal. För en kommun betyder detta inte att integrationen är klar, utan att API:et eller datamängden kan beskrivas mer strukturerat. Praktiskt underlag är OpenAPI-fil, namn på datamängd eller API, kontakt, åtkomstpunkt, licens eller villkor, beskrivning och ansvarig förvaltning.'
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
    'DIGG DCAT-AP-SE publicering av API-metadata',
    'diggsweden GitHub',
    'https://github.com/diggsweden/DCAT-AP-SE-Processor',
    'DCAT-AP-SE Processor automatiserar framställning av metadata enligt DCAT-AP-SE för publicering av datamängder och API:er på Sveriges dataportal. Verktyget tar OpenAPI, RAML eller separata metadatafiler som input och genererar RDF redo för skördning. För en kommun betyder detta inte att integrationen är klar, utan att API:et eller datamängden kan beskrivas mer strukturerat. Praktiskt underlag är OpenAPI-fil, namn på datamängd eller API, kontakt, åtkomstpunkt, licens eller villkor, beskrivning och ansvarig förvaltning.'
  ),
  (
    'DIGG RAP-LP validering av OpenAPI',
    'diggsweden GitHub',
    'https://github.com/diggsweden/rest-api-profil-lint-processor',
    'REST API-profil Lint Processor, RAP-LP, validerar OpenAPI v3-specifikationer mot den svenska REST API-profilen med hjälp av Spectral. Verktyget kan köras lokalt i CLI-läge eller API-läge, via webgränssnitt, npm/npx, Docker eller Podman. För små kommuner räcker det ofta att be leverantören lämna openapi.yaml och en kort valideringsrapport: vilka fel finns, vilka varningar finns och vad måste rättas innan integrationen testas.'
  ),
  (
    'Svensk REST API-profil - praktisk OpenAPI-struktur',
    'Dataportal',
    'https://www.dataportal.se/rest-api-profil#praktisk-openapi-struktur',
    'När kommunen ber om ett API-underlag bör det normalt vara en OpenAPI v3-specifikation i YAML eller JSON. För en liten kommun kan kravet beskrivas som: lämna en openapi.yaml som innehåller info med titel, version, beskrivning och kontakt; servers med test- eller produktionsadress; paths med resurser och HTTP-metoder; operationId, summary och description per operation; parameters för path/query/header; requestBody där API:et tar emot data; responses med statuskoder; content-typer som application/json där JSON används; components/schemas för datamodeller; säkerhetskrav; felmodell och exempel. Detta säger inte att varje verksamhetsmeddelande måste vara JSON, och AI får inte säga att DIGG alltid kräver JSON eller XML för API-sändning om källan inte uttryckligen säger det. REST API-profilen och RAP-LP arbetar med OpenAPI som beskriver API:et och dess payloads; exakt payload-format måste kontrolleras i tjänstens API-specifikation eller med leverantör/DIGG.'
  ),
  (
    'RAP-LP regelkategorier som AI ska använda vid API-råd',
    'diggsweden GitHub',
    'https://github.com/diggsweden/rest-api-profil-lint-processor#regelfiltrering',
    'RAP-LP kan validera OpenAPI mot regelkategorier från REST API-profilen: dokumentation, datum- och tidsformat, resurser, URL-format och namngivning, mognad, säkerhet, spårbarhet och korrelation, API-meddelanden, API-request, felhantering, versionshantering, filtrering/paginering/sökparametrar och förutsättningar. När AI ger råd om hur ett kommunalt API ska se ut ska svaret översätta dessa kategorier till praktiska frågor: är API:et dokumenterat, är resursnamn och URL:er begripliga, finns säkerhet, finns spårbarhet, finns felmodell, finns versionering och finns filtrering eller paginering där listor returneras.'
  ),
  (
    'RAP-LP strikt läge och leverantörsbevis',
    'diggsweden GitHub',
    'https://github.com/diggsweden/rest-api-profil-lint-processor#strikt-läge-oas3-validering',
    'RAP-LP har ett strikt läge som först kontrollerar att OpenAPI-specifikationen är korrekt uppbyggd och semantiskt giltig enligt OpenAPI 3 innan reglerna i REST API-profilen körs. För kommunens workflow betyder detta att leverantören bör kunna lämna tre saker: själva openapi.yaml eller openapi.json, en RAP-LP-rapport eller logg från validering, och en kort åtgärdslista för kvarvarande fel och varningar. Om kommunen saknar egen IT ska AI inte kräva att kommunen kör verktyget själv först, utan föreslå att leverantören gör valideringen och skickar resultatet.'
  ),
  (
    'OpenAPI exempel för kommunal systemkoppling',
    'Dataportal',
    'local://rest-api-profil/kommun-openapi-checklista',
    'En praktisk kommunal OpenAPI-checklista kan vara: 1. API-namn och version, 2. ansvarig förvaltning och teknisk kontakt, 3. testserver och produktionsserver, 4. endpoints som beskriver verksamhetsresurser med substantiv, till exempel /franvarounderlag eller /elever/{elevId}/franvaro, 5. metoder som GET för hämtning, POST för skapande eller inskick, PUT/PATCH för ändring och DELETE endast där radering verkligen är tillåten, 6. query-parametrar för filtrering och paginering, till exempel limit och page, 7. JSON-schema för request och response om JSON används, 8. felrespons med type, title, status, detail och instance enligt problem response-mönster, 9. autentisering och behörighet, 10. exempelpayload utan skarpa personuppgifter. Vid osäkerhet ska AI säga att RAP-LP-rapport och leverantörsdokumentation behövs.'
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
  ),
  (
    'PDF: Process för att utveckla och förvalta nationella grunddata',
    'Nationella grunddata',
    'local://pdf/grunddata-process',
    'Bilagan beskriver processen för hur nationella grunddata beslutas, utvecklas, fastställs och förvaltas. Triggern är ett identifierat behov av nationella grunddata. Kandidatsteget omfattar att dokumentera inkomna behov, bedöma juridiska förutsättningar, bedöma informationssäkerhetsbehov, analysera datamängder och identifiera intressenter. Utveckling och anpassning omfattar riskanalys, specifikation, kvalitetssäkring och publicering. Förvaltning handlar om ändringar, uppdatering, fortsatt tillgängliggörande och ett likartat arbetssätt mellan grunddatadomäner.'
  ),
  (
    'PDF: Roller inom nationella grunddata',
    'Nationella grunddata',
    'local://pdf/grunddata-roller',
    'Bilagan beskriver roller och ansvar för nationella grunddata. Grunddatadomänansvarig samordnar utveckling och förvaltning inom en grunddatadomän, följer ramverket, samverkar med andra aktörer, rapporterar status och främjar användning av nationella grunddata. Grunddataproducenten ansvarar för sina datamängder, metadata, kvalitet och tillgängliggörande där det är rättsligt möjligt. Rollbeskrivningarna hjälper kommunen att placera ansvar, beslutsmandat, samverkan och förvaltningsansvar innan en integrations- eller datadelningslösning byggs.'
  ),
  (
    'PDF: Begreppsmodeller för nationella grunddata',
    'Nationella grunddata',
    'local://pdf/grunddata-begreppsmodeller',
    'Bilagan förklarar att begreppsmodeller visar relationer mellan begrepp inom ramverket för nationella grunddata. Begreppsmodellerna kompletterar begreppskatalogen på Sveriges dataportal och hjälper aktörer att skapa gemensam förståelse innan informationsmodeller, API:er och mappningar tas fram. I en anslutningsresa bör oklara begrepp, datamängder, informationsobjekt och ansvariga roller identifieras tidigt så att källsystem och målsystem inte använder samma ord på olika sätt.'
  ),
  (
    'PDF: Informationsarkitektur för nationella grunddata',
    'Nationella grunddata',
    'local://pdf/grunddata-informationsarkitektur',
    'Bilagan beskriver modelltyper, standarder, specifikationer och gemensamma regler för informationsarkitektur. Begreppsmodeller beskriver verksamhetens begrepp och relationer. Informationsmodeller beskriver informationens struktur, attribut, relationer och regler. Specifikationer bör vara tydliga, spårbara och återanvändbara. Namnsättning, dokumenterad notation, enhetligt utseende, metadata och koppling mellan behov, juridik, informationssäkerhet och tekniska gränssnitt är viktigt när kommunen tar fram API-dokumentation, OpenAPI, fältmappning och testbara integrationskrav.'
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
