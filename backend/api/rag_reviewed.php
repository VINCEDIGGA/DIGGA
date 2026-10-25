<?php
declare(strict_types=1);

/**
 * Reviewed participant guidance. All supporting excerpts are looked up in the
 * pinned public snapshots at runtime. Documents are data, never instructions.
 * This module performs no network request, database write or model invocation.
 */
function rag_reviewed_response(string $question, array $profile, array $chunks): ?array
{
    if (($profile['in_scope'] ?? true) === false) return null;
    if (($profile['service'] ?? '') !== 'sdk' && !in_array('SDK', $profile['allowed_services'] ?? [], true)) return null;
    $topic = rag_reviewed_topic($question);
    if ($topic === null) return null;
    $documents = rag_reviewed_documents();
    if (!$documents) return null;
    $definitions = rag_reviewed_catalog()[$topic] ?? [];
    $claims = []; $sources = [];
    foreach ($definitions as $definition) {
        $evidence = [];
        foreach ($definition[1] as [$sourceId, $needle]) {
            $item = rag_reviewed_excerpt($sourceId, $needle, $documents, $chunks);
            // Fail closed: never attach a page URL to an unsupported statement.
            if ($item === null) return null;
            $evidence[] = $item;
            $sources[$sourceId] = [
                'id' => $sourceId, 'source_id' => $sourceId,
                'title' => $item['title'], 'service' => 'SDK',
                'source_url' => $item['source_url'], 'version' => $item['version'],
                'last_checked' => $item['last_checked'],
                'environment' => $item['environment'], 'role' => $item['role'],
            ];
        }
        $claims[] = ['text' => $definition[0], 'kind' => 'reviewed',
            'support_check' => 'reviewed_fact', 'evidence' => $evidence];
    }
    if (!$claims) return null;
    $missing=[];
    if($topic==='addresses'&&preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu',$question)){
        $missing[]='Den angivna adressen har inte slagits upp eller verifierats i SDK adressbok. Svaret bekräftar inte att den är en korrekt SDK-funktionsadress.';
    }
    return [
        'mode' => 'reviewed_guidance',
        'answer' => implode("\n\n", array_map(static fn(array $claim): string => '- ' . $claim['text'], $claims)),
        'claims' => $claims, 'sources' => array_values($sources),
        'verification' => [
            'status' => $missing?'partial':'reviewed', 'checked_claims' => count($claims),
            'omitted_claims' => 0, 'semantic_check' => 'not_used',
            'notice' => 'Förhandsgranskade vägledningspunkter med ordagranna utdrag ur sparade officiella källor. Ingen semantisk modellkontroll har använts. Detta är vägledning, inte Diggs godkännande eller en kontroll av er faktiska anslutning.',
        ],
        'missing_information' => $missing,
    ];
}

/** Deliberately narrow routing; detailed technical and ambiguous questions use normal RAG. */
function rag_reviewed_topic(string $question): ?string
{
    $q = mb_strtolower(trim($question), 'UTF-8');
    // A general FAQ must not silently stand in for an unanswered detailed question.
    if(preg_match('/avgift|kostnad|pris|betala|gdpr|dataskydd|personuppgift|timeout|tidsgräns|svarstid|sekunder|minuter|timmar|valider|formatkontroll|underlagsverkstad|grön|fingeravtryck|serienummer|giltighet|gäller till|utgånget|nyckel|algoritm|hur länge|hur snabbt|hur mycket|varför|skillnaden mellan.{0,30}tf\s*\d|python|javascript/u',$q))return null;
    if ($q === '' || preg_match('/rekommenderad|open[ -]?test|förny|certifikatbyte|nyckellängd|algoritm|p12|pfx|pem|x\.?509|openssl|openapi|\bjson\b|\bxml\b|\brest\b|\bapi\b|mina meddelanden|digital post|driftinfo/u', $q)) return null;
    if (preg_match('/leverantör.{0,45}(test|godkänn|lista|finns)|accesspunktsoperatör.{0,45}(test|godkänn)|vilka.{0,25}leverantör/u', $q)) return null;
    if (preg_match('/(kolla|slå upp|kontrollera|bekräfta).{0,60}(vår|kommunens|organisationens).{0,50}(identitet|registrering)|är.{0,20}(vår|kommunens|organisationens).{0,40}(registrerad|upplagd|godkänd)/u', $q)) return null;
    // Explicit broad preparation questions must not collapse into a single test or address fact.
    if (preg_match('/förbered|komma igång|första steg|göra först|börja.{0,30}(sdk|anslut)|inför.{0,25}(sdk.?qa|anslut)|krävs.{0,25}(sdk.?qa|anslut)/u', $q)
        && !preg_match('/sex.{0,15}test|\b6\b.{0,15}test|obligatorisk|testfall|deltagarident|\bsmp\b|funktionsadress|administratör|lämna.{0,20}certifikat/u', $q)) return 'preparation';
    if (preg_match('/(?:sex|\b6\b).{0,20}test|(?:vilka|lista|sammanfatta).{0,50}test|testfall.{0,30}(?:behöver|krävs|obligatoriska)/u', $q)) return 'tests';
    if (preg_match('/funktionsadress|funktionsbrevlåda/u', $q)) return 'addresses';
    if (preg_match('/deltagarident|participant.identifier|\bsmp\b/u', $q) && !preg_match('/certifikat|byte|byta|ändra/u', $q)) return 'identity';
    if (preg_match('/organisationsadministr|användaradministr|adressadministr|adressboksadministr|lämna.{0,25}certifikat|certifikat.{0,25}(lämna|behörighet)|roller.{0,25}adressbok/u', $q)) return 'roles';
    return null;
}

/** Only checked official snapshot bodies are accepted; manifest summaries are not quotations. */
function rag_reviewed_documents(): array
{
    $directory = dirname(__DIR__) . '/data/sdk';
    $file = $directory . '/manifest.json';
    if (!is_file($file)) return [];
    $manifest = json_decode((string) file_get_contents($file), true);
    if (!is_array($manifest)) return [];
    $documents = [];
    foreach ($manifest['sources'] ?? [] as $source) {
        if (($source['status'] ?? '') !== 'downloaded' || empty($source['id']) || empty($source['local_path'])) continue;
        $path = $directory . '/snapshots/' . basename($source['local_path']);
        if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'txt') continue;
        $body = str_replace("\r\n", "\n", (string) file_get_contents($path));
        if ($body === '') continue;
        // Snapshot hashes cover extracted LF text without the writer's one final newline.
        // Normalize only that storage convention; changes to actual text still fail closed.
        $hashBody = str_ends_with($body, "\n") ? substr($body, 0, -1) : $body;
        if (!empty($source['sha256']) && !hash_equals((string) $source['sha256'], hash('sha256', $hashBody))) continue;
        $documents[$source['id']] = ['source' => $source, 'body' => $body];
    }
    return $documents;
}

/** Return an exact substring from its body, with a reproducible snapshot line locator. */
function rag_reviewed_excerpt(string $sourceId, string $needle, array $documents, array $chunks): ?array
{
    $document = $documents[$sourceId] ?? null;
    if (!$document || $needle === '') return null;
    $offset = strpos($document['body'], $needle);
    if ($offset === false) return null;
    $source = $document['source'];
    $quote = substr($document['body'], $offset, strlen($needle));
    $line = substr_count(substr($document['body'], 0, $offset), "\n") + 1;
    $chunkId = 'snapshot:' . $sourceId . ':L' . $line . ':' . substr(hash('sha256', $quote), 0, 12);
    // Reuse an actual retrieved chunk identifier only when it contains this exact excerpt.
    foreach ($chunks as $chunk) {
        if (($chunk['source_id'] ?? '') === $sourceId && !empty($chunk['chunk_id'])
            && strpos((string) ($chunk['content'] ?? ''), $quote) !== false) {
            $chunkId = $chunk['chunk_id']; break;
        }
    }
    return [
        'source_id' => $sourceId, 'chunk_id' => $chunkId,
        'title' => (string) $source['title'], 'source_url' => (string) $source['url'],
        'quote' => $quote, 'version' => $source['version'] ?? null,
        'last_checked' => $source['last_checked'] ?? null,
        'environment' => $source['environment'] ?? null, 'role' => $source['role'] ?? null,
        'evidence_origin' => 'official_snapshot', 'provenance' => 'official_snapshot_body',
        'snapshot_path' => $source['local_path'], 'line_start' => $line,
    ];
}

function rag_reviewed_catalog(): array
{
    return [
        'identity' => [
            ['Ni bestämmer deltagaridentiteten enligt SDK:s regler. Den ska stämma med den Participant Identifier som accesspunktsoperatören registrerar i SMP för aktuell miljö; QA och produktion har egna registreringar.', [
                ['connection-instructions', 'Ni tar själva fram och beslutar om er deltagaridentitet (organisationsidentifierare) enligt gällande regler och krav inom SDK.'],
                ['connection-instructions', 'Identiteten ska överensstämma med ”Participant Identifier” som er accesspunktsoperatör registrerar i SMP i SDK QA-miljön.'],
                ['connection-instructions', 'Identiteten ska överensstämma med ”Participant Identifier” som er accesspunktsoperatör registrerar i SMP i SDK produktionsmiljö.'],
            ]],
            ['Säkerställ med accesspunktsoperatören att organisationen är registrerad i SDK QA:s SMP innan ni påbörjar anslutningen genom formuläret.', [
                ['connection-journey', 'Deltagarorganisationen har säkerställt att accesspunktsoperatör har registrerat organisationen i Diggs metadatatjänst (SMP) i SDK QA-miljö. Deltagarorganisationen påbörjar anslutningsförfarandet genom att skicka in formulär: Anslutning av deltagarorganisationer till SDK.'],
            ]],
            ['Registrering av deltagaridentiteten och publicering av publikt O2O-certifikat är skilda aktiviteter. Organisationsadministratören lämnar certifikatet i adressboken; Digg hämtar och publicerar det i certifikatspubliceringstjänsten i SMP.', [
                ['connection-instructions', 'För att garantera riktighet lämnas O2O-certifikat i SDK adressbok av er organisationsadministratör.'],
                ['connection-instructions', 'Vi hämtar certifikatet från adressboken och publicerar det i Certifikatspubliceringstjänsten i SMP.'],
                ['connection-instructions', 'Identiteten ska överensstämma med ”Participant Identifier” som er accesspunktsoperatör registrerar i SMP i SDK QA-miljön.'],
            ]],
        ],
        'roles' => [
            ['Användaradministratören tilldelar organisationsadministratör och adressadministratör samt behörighet till SDK testklient. Behörigheterna hanteras inom organisationen.', [
                ['connection-instructions', 'Användaradministratörens roll är att tilldela andra i er organisation de roller med tillhörande behörigheter som finns för SDK adressbok - organisationsadministratör och adressadministratör.'],
                ['connection-instructions', 'Det är användaradministratören som även tilldelar behörighet till den eller de personer som ska vara användare av SDK testklient i er organisation.'],
            ]],
            ['Organisationsadministratören lämnar organisationens publika O2O-certifikat i SDK adressbok. Rollen behöver alltså finnas för den som gör inlämningen.', [
                ['connection-instructions', 'För att garantera riktighet lämnas O2O-certifikat i SDK adressbok av er organisationsadministratör. Er användaradministratör tilldelar rollen organisationsadministratör som i sin tur lägger upp certifikatet.'],
            ]],
            ['Vid inlämning går organisationsadministratören till Mina organisationer, väljer organisation och Redigera. Nuvarande instruktion säger att samma publika certifikat ska anges i fälten för kryptering och signering.', [
                ['connection-instructions', 'går till avsnittet ”Mina organisationer” i vänstermenyn, väljer organisation och trycker på ”Redigera”.'],
                ['connection-instructions', 'Eftersom SDK regelverk i nuläget reglerar att samma certifikat ska användas för både kryptering och signering av SDK-meddelanden lämnar ni samma publika certifikat i båda fälten.'],
            ]],
            ['Efter uppladdningen notifierar ni Digg via sdk@digg.se. Digg hämtar det publika certifikatet från adressboken och publicerar det i certifikatspubliceringstjänsten i SMP.', [
                ['connection-instructions', 'När det är utfört behöver ni notifiera oss på Digg att certifikatet finns upplagt. Notifiering sker per mejl till sdk@digg.se. Vi hämtar certifikatet från adressboken och publicerar det i Certifikatspubliceringstjänsten i SMP.'],
            ]],
            ['Adressadministratören registrerar funktionsadresser. Det är en annan behörighet än organisationsadministratörens certifikatinlämning.', [
                ['connection-instructions', 'Det är de(n) som har rollen adressadministratör som är behörig att registrera funktionsadresser.'],
                ['connection-instructions', 'För att garantera riktighet lämnas O2O-certifikat i SDK adressbok av er organisationsadministratör.'],
            ]],
        ],
        'addresses' => [
            ['En funktionsadress i SDK representerar en verksamhetsfunktion eller funktionsbrevlåda. Den används inte för att adressera SDK-meddelanden till en enskild person.', [
                ['qa-tests', 'En funktionsadress är en adress som registrerats för en verksamhetsfunktion eller funktionsbrevlåda i SDK adressbok.'],
                ['addressbook-guide', 'Observera att det endast finns funktionsadresser i SDK adressbok, vilket innebär att man inte kan skicka ett meddelande till en specifik person.'],
            ]],
            ['Organisationen ansvarar för att bemanna, registrera och uppdatera sina funktionsadresser på ett strukturerat sätt.', [
                ['addressbook-guide', 'Varje organisation är ansvarig för att bemanna, registrera och uppdatera sina funktionsadresser på ett strukturerat sätt.'],
            ]],
            ['Användaradministratören tilldelar rollen adressadministratör. Adressadministratören registrerar sedan funktionsadresserna i SDK adressbok.', [
                ['connection-instructions', 'Användaradministratören behöver därmed tilldela rollen adressadministratör som i sin tur registrerar funktionsadresser.'],
            ]],
            ['Accesspunktsoperatörens administration av teknisk metadata i SMP är en separat uppgift från organisationens administration av funktionsadresser.', [
                ['glossary', 'Accesspunktsoperatörsrollen ansvarar för accesspunktsfunktionen och administrerar metadata i gemensam komponent Service Metadata Publisher (SMP).'],
                ['connection-instructions', 'Det är de(n) som har rollen adressadministratör som är behörig att registrera funktionsadresser.'],
            ]],
        ],
        'tests' => [
            ['Deltagarorganisationens följande sex anslutningstestfall genomförs i SDK QA inför anslutning till produktionsmiljön.', [
                ['qa-tests', 'Här specificeras de testfall som en deltagarorganisation inom SDK ska genomföra inför anslutning till SDK produktionsmiljö.'],
                ['qa-tests', 'Testfallen utförs i SDK QA-miljö och förutsätter att deltagarorganisationen följer de regler och specifikationer som finns för miljön.'],
            ]],
            ['TF 2.0.1: ta emot ett meddelande i meddelandetjänsten, generera meddelandekvittens och vidarebefordra till meddelandeklienten.', [
                ['qa-tests', 'TF 2.0.1 SDK testklient skickar meddelande till deltagarens meddelandesystem'],
                ['qa-tests', 'Verifiera att deltagarorganisationens meddelandetjänst kan ta emot ett inkommande SDK-meddelande, generera en meddelandekvittens och vidarebefordra meddelandet till meddelandeklienten.'],
            ]],
            ['TF 2.0.2: skicka ett meddelande från meddelandetjänsten till SDK testklient och ta emot meddelandekvittensen.', [
                ['qa-tests', 'TF 2.0.2 - Deltagarorganisationens meddelandesystem skickar meddelande till SDK testklient'],
                ['qa-tests', 'Verifiera att deltagarorganisationens meddelandetjänst kan skicka ett SDK-meddelande till SDK testklient samt ta emot en meddelandekvittens.'],
            ]],
            ['TF 2.1.2: ta emot ett meddelande med flera bilagor och sammanlagd storlek strax under 30 MB i meddelandetjänsten.', [
                ['qa-tests', 'TF 2.1.2 - SDK testklient skickar ett maximalt meddelande till deltagarorganisationens meddelandesystem'],
                ['qa-tests', 'Verifiera att deltagarorganisationens meddelandetjänst kan hantera ett inkommande SDK-meddelande som innehåller flera bilagor där den totala storleken är strax under 30 MB'],
            ]],
            ['TF 3.0.1: ta emot och visa ett meddelande korrekt i meddelandeklienten; kontrollera också bilagor och formatering.', [
                ['qa-tests', 'TF 3.0.1 - Meddelandeklienten tar emot meddelande från SDK testklient'],
                ['qa-tests', 'Verifiera att deltagarorganisationens meddelandeklient kan ta emot och presentera ett inkommande SDK-meddelande korrekt för användaren.'],
                ['qa-tests', 'Kontrollera att samtliga bilagor kan öppnas och läsas.'],
                ['qa-tests', 'Kontrollera att formateringen visas korrekt.'],
            ]],
            ['TF 3.0.2: skapa och skicka ett meddelande från klienten till SDK testklient och välj mottagare med uppgifter från SDK adressbok.', [
                ['qa-tests', 'TF 3.0.2 - Meddelandeklienten skickar meddelande till SDK testklient'],
                ['qa-tests', 'Verifiera att användare av meddelandeklienten kan skapa och skicka ett SDK-meddelande till SDK testklient samt adressera mottagaren genom uppgifter hämtade från SDK adressbok.'],
            ]],
            ['TF 3.1.2: ta emot ett meddelande med flera bilagor och sammanlagd storlek strax under 30 MB i meddelandeklienten.', [
                ['qa-tests', 'TF 3.1.2 - Meddelandeklienten tar emot meddelande (maximal) från SDK testklient'],
                ['qa-tests', 'Verifiera att deltagarorganisationens meddelandeklient kan hantera ett inkommande SDK-meddelande med flera bilagor där den totala storleken är strax under den maximala tillåtna storleken på 30 MB.'],
            ]],
            ['Markera samtliga manuella testfall som genomförda i SDK testklient under Nytt anslutningstest. Om mer än sex månader går mellan genomförandet och ansökan behöver de berörda testerna göras om.', [
                ['qa-tests', 'Samtliga manuella testfall ska markeras som genomförda i SDK testklient under fliken "Nytt anslutningstest".'],
                ['qa-tests', 'Dröjer det mer än sex månader mellan testets genomförande och ansökan om federationsgodkännande behöver berörda tester göras om på nytt.'],
            ]],
        ],
        'preparation' => [
            ['Börja med verksamhetens behov och vilka informationsutbyten som är aktuella. Involvera ledning, verksamhet, IT och informationssäkerhet i förarbetet.', [
                ['preparation', 'Därefter är det viktigt att genomföra ett förarbete som fokuserar på vilka verksamheter och informationsutbyten som är aktuella, tekniska vägval och informationssäkerhet.'],
                ['preparation', 'Ledning, verksamhet, IT och informationssäkerhet är involverade.'],
            ]],
            ['Bedöm hur organisationen uppfyller informationssäkerhetskraven i SDK:s regelverk och IT-säkerhetsbilaga. Identifiera luckor och åtgärder även när ni köper en teknisk lösning.', [
                ['preparation', 'Utvärdera hur väl organisationen lever upp till de informationssäkerhetskrav som specificeras i regelverk för deltagarorganisationer inom SDK och IT-säkerhetsbilaga. Identifiera eventuella luckor och behov av åtgärder.'],
                ['preparation', 'Om organisationen väljer att upphandla en färdig teknisk lösning är det kraven på informationssäkerhet som blir det viktigaste att fokusera på.'],
            ]],
            ['Bestäm tekniskt vägval och ansvar: ni behöver meddelandetjänst, meddelandeklient och tillgång till en godkänd accesspunkt. Meddelandesystem kan utvecklas internt eller komma från extern leverantör.', [
                ['preparation', 'En organisation som vill ansluta till SDK behöver själv utveckla eller anlita en leverantör som tillhandahåller system för meddelandetjänst och meddelandeklient.'],
                ['connection-journey', 'Deltagarorganisationen har anskaffat eller anslutit till en godkänd accesspunkt från en av Diggs godkända accesspunktsoperatörer för plattform för eDelivery.'],
            ]],
            ['Bestäm deltagaridentiteten och be accesspunktsoperatören bekräfta registreringen i SDK QA:s SMP. Utse användaradministratör som kan tilldela organisations- och adressadministratör.', [
                ['connection-journey', 'Deltagarorganisationen har säkerställt att accesspunktsoperatör har registrerat organisationen i Diggs metadatatjänst (SMP) i SDK QA-miljö.'],
                ['connection-instructions', 'Användaradministratörens roll är att tilldela andra i er organisation de roller med tillhörande behörigheter som finns för SDK adressbok - organisationsadministratör och adressadministratör.'],
            ]],
            ['Påbörja anslutningen med Diggs formulär och organisations- och kontaktuppgifter. Efter granskning av rätten att ansluta skickar Digg QA-blanketten; samla deltagaridentitet, accesspunktsuppgifter samt meddelandesystemets namn, version och ID till underlaget.', [
                ['connection-instructions', 'Det gör ni med hjälp av formuläret: Anslutning av deltagarorganisationer till SDK. Ni fyller i organisationsuppgifter samt uppgifter för en kontaktperson hos er.'],
                ['connection-instructions', 'Vid ett godkännande återkommer vi med blanketten Anmälan om anslutning till SDK QA-miljö som skickas per mejl till den person ni angett som kontaktperson.'],
                ['connection-instructions', 'Deltagaridentitet*'], ['connection-instructions', 'Accesspunktsoperatör*'], ['connection-instructions', 'Accesspunktsidentitet*'],
                ['connection-instructions', 'Meddelandesystem (namn, version och meddelandesystem-ID)'],
            ]],
            ['Planera för QA-testerna. Publikt O2O-certifikat behöver publiceras, funktionsadresser registreras och användare få tillgång till SDK testklient innan ni påbörjar testerna. Samtliga manuella testfall ska markeras i testklienten.', [
                ['connection-instructions', 'När certifikatet är publicerat av oss, er(a) adressadministratör(er) har registrerat funktionsadresser i SDK adressbok och er(a) användare fått tillgång till SDK testklient är ni redo att påbörja testerna.'],
                ['qa-tests', 'Testfallen utförs i SDK QA-miljö och förutsätter att deltagarorganisationen följer de regler och specifikationer som finns för miljön.'],
                ['qa-tests', 'Samtliga manuella testfall ska markeras som genomförda i SDK testklient under fliken "Nytt anslutningstest".'],
            ]],
        ],
    ];
}
