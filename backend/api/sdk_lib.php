<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_compat.php';

/** Public, deterministic SDK preparation tools. No delivery, credentials or certificate storage. */
const SDK_DATA_DIR = __DIR__ . '/../data/sdk';
const SDK_NS = 'urn:riv:infrastructure:messaging:MessageWithAttachments:3';

function sdk_manifest(): array
{
    return json_decode((string) file_get_contents(SDK_DATA_DIR . '/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
}

function sdk_source(string $id): string
{
    foreach (sdk_manifest()['sources'] as $source) {
        if ($source['id'] === $id) { return $source['url']; }
    }
    return 'https://www.digg.se/saker-digital-kommunikation';
}

function sdk_asset(string $id): ?array
{
    foreach (sdk_manifest()['assets'] as $asset) {
        if ($asset['id'] === $id) { return $asset; }
    }
    return null;
}

function sdk_asset_content(array $asset): string
{
    // Only a basename from the checked manifest is used; user paths are never resolved.
    $path = SDK_DATA_DIR . '/assets/' . basename($asset['local_path']);
    if (!is_file($path)) { throw new RuntimeException('Exempelfilen saknas.'); }
    return (string) file_get_contents($path);
}

function sdk_result(string $summary, array $exclusions = []): array
{
    return ['valid' => false, 'checks' => [], 'issues' => [], 'normalized' => null,
        'coverage' => ['summary' => $summary, 'level' => 'lokal förhandskontroll', 'xsd' => false,
            'schematron' => false, 'implemented_rules' => [], 'exclusions' => $exclusions], 'source_urls' => []];
}

function sdk_check(array &$result, string $id, string $label, bool $pass, string $message,
    string $path = '/', string $source = 'message-spec', string $severity = 'error'): void
{
    $result['checks'][] = ['id' => $id, 'label' => $label, 'status' => $pass ? 'pass' : ($severity === 'warning' ? 'warning' : 'fail'), 'message' => $message];
    $result['coverage']['implemented_rules'][] = $id;
    $url = sdk_source($source);
    $result['source_urls'][] = $url;
    if (!$pass) {
        $result['issues'][] = ['path' => $path, 'severity' => $severity, 'message' => $message, 'rule' => $id, 'source_url' => $url];
    }
}

function sdk_finish(array $result): array
{
    $result['valid'] = !array_filter($result['issues'], static fn(array $issue): bool => $issue['severity'] === 'error');
    $result['source_urls'] = array_values(array_unique($result['source_urls']));
    $result['coverage']['implemented_rules'] = array_values(array_unique($result['coverage']['implemented_rules']));
    return $result;
}

function sdk_uuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1;
}

function sdk_participant(string $value): bool
{
    return preg_match('/^0203:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/iD', $value) === 1;
}

function sdk_length(string $value): int { return mb_strlen($value, 'UTF-8'); }

function sdk_validate_xml(string $content): array
{
    $r = sdk_result('XML kontrolleras mot medföljande officiellt XSD och ett uttryckligt urval innehållsregler. Hela Schematron körs inte.', ['Fullständig Schematron/XSLT 2', 'XHE/AS4-kuvertering', 'Kryptering och signatur', 'Adressens existens i aktuell miljö', 'Federationsgodkännande']);
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
        sdk_check($r, 'xml-no-entities', 'Säkert XML-underlag', false, 'DOCTYPE och externa entiteter stöds inte. Exportera en fristående XML-fil.');
        return sdk_finish($r);
    }
    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
    $errors = libxml_get_errors(); libxml_clear_errors();
    sdk_check($r, 'xml-syntax', 'XML kan läsas', $loaded, $loaded ? 'XML-syntaxen kan läsas.' : 'XML kan inte läsas. Kontrollera element och avslutande taggar.');
    if (!$loaded) {
        foreach (array_slice($errors, 0, 8) as $e) { $r['issues'][] = ['path' => 'rad ' . $e->line, 'severity' => 'error', 'message' => trim($e->message), 'rule' => 'xml-syntax', 'source_url' => sdk_source('message-validation')]; }
        libxml_use_internal_errors($previous); return sdk_finish($r);
    }
    $root = $doc->documentElement;
    $rightRoot = $root && $root->localName === 'messagePayload' && $root->namespaceURI === SDK_NS;
    sdk_check($r, 'sdk-root', 'SDK-rot och namnrymd', $rightRoot, $rightRoot ? 'Rotelement och SDK-namnrymd stämmer.' : 'Förväntar SDK messagePayload i namnrymden ' . SDK_NS . '.');
    if (!$rightRoot) { libxml_use_internal_errors($previous); return sdk_finish($r); }
    $schemaValid = $doc->schemaValidate(SDK_DATA_DIR . '/assets/infrastructure_messaging_MessageWithAttachments_3.0.xsd');
    $schemaErrors = libxml_get_errors(); libxml_clear_errors(); libxml_use_internal_errors($previous);
    $r['coverage']['xsd'] = true;
    sdk_check($r, 'xsd-structure', 'Officiellt XSD', $schemaValid, $schemaValid ? 'Strukturen följer det lokalt medföljande officiella XSD-schemat.' : 'Strukturen avviker från det officiella XSD-schemat.', '/', 'message-validation');
    foreach (array_slice($schemaErrors, 0, 15) as $e) { $r['issues'][] = ['path' => 'rad ' . $e->line, 'severity' => 'error', 'message' => trim($e->message), 'rule' => 'xsd-structure', 'source_url' => sdk_source('message-validation')]; }
    $xp = new DOMXPath($doc); $xp->registerNamespace('sdk', SDK_NS);
    $header = '/sdk:messagePayload/sdk:message/sdk:messageHeader';
    $get = static fn(string $path): string => trim((string) $xp->evaluate('string(' . $path . ')'));
    $time = $get($header . '/sdk:creationDateTime');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $time);
    $timeValid = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{1,3}Z$/D', $time) === 1 && strtotime($time) !== false;
    sdk_check($r, 'utc-time', 'UTC-tid', $timeValid, $timeValid ? 'Tidsstämpeln har UTC och millisekundsformat enligt medföljande innehållsregler.' : 'Ange UTC-tid med 1–3 decimaler och Z, exempelvis 2026-10-14T12:00:00.000Z.', $header . '/creationDateTime');
    foreach (['messageId', 'conversationId', 'refToMessageId'] as $field) {
        $value = $get($header . '/sdk:' . $field);
        if ($field === 'refToMessageId' && $value === '') { continue; }
        sdk_check($r, 'uuid-' . $field, 'UUID: ' . $field, sdk_uuid($value), sdk_uuid($value) ? 'Identifieraren har UUID-format.' : 'Identifieraren ska ha UUID-format.', $header . '/' . $field);
    }
    $label = $get($header . '/sdk:label');
    sdk_check($r, 'subject', 'Meddelanderubrik', $label !== '' && sdk_length($label) <= 256, $label !== '' && sdk_length($label) <= 256 ? 'Rubrik finns och är högst 256 tecken.' : 'En rubrik med högst 256 tecken behövs.', $header . '/label');
    foreach (['sender', 'recipient'] as $party) {
        $base = $header . '/sdk:' . $party;
        $id = $get($base . '/sdk:' . $party . 'ID/sdk:extension');
        $idRoot = $get($base . '/sdk:' . $party . 'ID/sdk:root');
        sdk_check($r, $party . '-root', ucfirst($party) . ': kodverk', $idRoot === 'iso6523-actorid-upis', $idRoot === 'iso6523-actorid-upis' ? 'Kodverket är iso6523-actorid-upis.' : 'Organisationsidentifierarens root ska vara iso6523-actorid-upis.', $base . '/' . $party . 'ID/root');
        sdk_check($r, $party . '-participant', ucfirst($party) . ': deltagaridentitet', sdk_participant($id), sdk_participant($id) ? 'Identiteten har formatet 0203:domän.' : 'Ange deltagaridentitet i formatet 0203:domän.', $base . '/' . $party . 'ID/extension');
        $fbase = $base . '/sdk:attention/sdk:subOrganization/sdk:organizationId';
        $function = $get($fbase . '/sdk:extension'); $functionRoot = $get($fbase . '/sdk:root');
        $functionOk = $function !== '' && $functionRoot === 'urn:riv:infrastructure:messaging:functionalAddress';
        sdk_check($r, $party . '-function', ucfirst($party) . ': funktionsadress', $functionOk, $functionOk ? 'Funktionsadress och kodverk finns. Adressens registrering har inte kontrollerats.' : 'En funktionsadress med rätt functionalAddress-kodverk behövs.', $fbase);
    }
    $empty = [];
    foreach ($xp->query('//*[not(*)]') as $node) { if (trim($node->textContent) === '') { $empty[] = $node->getNodePath(); } }
    sdk_check($r, 'nonempty-elements', 'Inga tomma element', !$empty, !$empty ? 'Inkluderade lövelement har värden.' : 'Tomma element hittades: ' . implode(', ', array_slice($empty, 0, 5)), '/');
    $files = []; $fileOk = true;
    foreach ($xp->query('//sdk:ContentFiles') as $node) {
        $filename = trim((string) $xp->evaluate('string(sdk:fileName)', $node));
        $mime = trim((string) $xp->evaluate('string(sdk:contentType)', $node));
        $b64 = preg_replace('/\s+/', '', (string) $xp->evaluate('string(sdk:content)', $node));
        $decoded = base64_decode($b64, true); $ok = $decoded !== false && $filename !== '' && preg_match('~^[\w.+-]+/[\w.+-]+$~D', $mime);
        $fileOk = $fileOk && $ok;
        $files[] = ['name' => $filename, 'mime_type' => $mime, 'bytes' => $decoded === false ? 0 : strlen($decoded)];
    }
    sdk_check($r, 'attachment-encoding', 'Bilagornas metadata och Base64', $fileOk, $fileOk ? 'Bilagornas angivna metadata och Base64 kan läsas.' : 'Kontrollera filnamn, MIME-typ och strikt Base64-kodning.');
    $texts = []; foreach ($xp->query('//sdk:ContentText/sdk:characterSequence') as $node) { $texts[] = mb_substr($node->textContent, 0, 6000); }
    sdk_check($r, 'message-content', 'Meddelandeinnehåll', count($texts) + count($files) > 0, count($texts) + count($files) > 0 ? 'Text eller bilaga finns.' : 'Meddelandet behöver text eller bilaga.');
    $r['normalized'] = ['message_id' => $get($header . '/sdk:messageId'), 'conversation_id' => $get($header . '/sdk:conversationId'), 'label' => $label, 'creation_date_time' => $time, 'sender' => $get($header . '/sdk:sender/sdk:senderID/sdk:extension'), 'recipient' => $get($header . '/sdk:recipient/sdk:recipientID/sdk:extension'), 'text' => implode("\n", $texts), 'attachments' => $files];
    return sdk_finish($r);
}

function sdk_parse_document(string $content): array
{
    $trim = ltrim($content, "\xEF\xBB\xBF \t\r\n");
    if (str_starts_with($trim, '{') || str_starts_with($trim, '[')) {
        $document = json_decode($trim, true, 80, JSON_THROW_ON_ERROR);
    } else {
        // Basic, bounded YAML only. Never process object tags, aliases or file references.
        if (preg_match('/(?:^|:\s+|-\s+)[!&*][A-Za-z_][\w-]*(?:\s|$)/m', $trim) || preg_match('/^\t/m', $trim)) { throw new InvalidArgumentException('YAML-taggar, ankare, alias och tabbindrag stöds inte. Exportera OpenAPI som JSON eller enkel YAML.'); }
        require_once SDK_DATA_DIR . '/vendor/symfony-deprecation/function.php';
        static $autoloaded = false;
        if (!$autoloaded) {
            spl_autoload_register(static function (string $class): void {
                $prefix = 'Symfony\\Component\\Yaml\\';
                if (str_starts_with($class, $prefix)) {
                    $file = SDK_DATA_DIR . '/vendor/symfony-yaml/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) { require_once $file; }
                }
            });
            $autoloaded = true;
        }
        $document = Symfony\Component\Yaml\Yaml::parse($trim, Symfony\Component\Yaml\Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
    }
    if (!is_array($document)) { throw new InvalidArgumentException('Dokumentet behöver vara ett JSON- eller YAML-objekt.'); }
    return $document;
}

function sdk_validate_openapi(string $content): array
{
    $r = sdk_result('Översiktlig kontroll av OpenAPI 3 JSON eller enkel YAML. Detta är inte en fullständig OpenAPI-metavalidering.', ['Fullständig OpenAPI-metavalidering', 'Externa $ref hämtas inte', 'Live-anrop och autentisering', 'YAML-taggar/ankare/alias']);
    try { $doc = sdk_parse_document($content); }
    catch (Throwable $e) { sdk_check($r, 'openapi-parse', 'Kontrakt kan läsas', false, $e->getMessage(), '/', 'mtmk-openapi'); return sdk_finish($r); }
    sdk_check($r, 'openapi-version', 'OpenAPI-version', isset($doc['openapi']) && preg_match('/^3\.\d+\.\d+$/D', (string) $doc['openapi']), 'Ange en OpenAPI 3-version, exempelvis 3.0.3.', '/openapi', 'mtmk-openapi');
    $info = $doc['info'] ?? [];
    sdk_check($r, 'openapi-info', 'Titel och API-version', is_array($info) && !empty($info['title']) && !empty($info['version']), 'Kontraktet behöver info.title och info.version.', '/info', 'mtmk-openapi');
    $paths = $doc['paths'] ?? null;
    sdk_check($r, 'openapi-paths', 'API-sökvägar', is_array($paths) && count($paths) > 0, 'Kontraktet behöver minst en sökväg under paths.', '/paths', 'mtmk-openapi');
    $operations = []; $refs = [];
    foreach ((array) $paths as $path => $methods) {
        sdk_check($r, 'path-' . $path, 'Sökväg: ' . $path, str_starts_with((string) $path, '/'), 'API-sökvägar ska börja med /.', '/paths/' . $path, 'mtmk-openapi');
        foreach ((array) $methods as $method => $op) {
            if (!in_array(strtolower((string) $method), ['get','post','put','patch','delete','head','options','trace'], true)) { continue; }
            $operations[] = ['method' => strtoupper($method), 'path' => $path, 'summary' => $op['summary'] ?? '', 'operationId' => $op['operationId'] ?? null];
            sdk_check($r, 'response-' . $method . '-' . $path, strtoupper($method) . ' ' . $path, is_array($op) && !empty($op['responses']), 'Operationen behöver dokumenterade responses.', '/paths/' . $path . '/' . $method . '/responses', 'mtmk-openapi');
        }
    }
    $walk = function ($node, string $path = '') use (&$walk, &$refs, &$r, $doc): void {
        if (!is_array($node)) { return; }
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;
                if (str_starts_with($value, '#/')) {
                    $target = $doc; $found = true;
                    foreach (explode('/', substr($value, 2)) as $part) { $part = str_replace(['~1','~0'], ['/','~'], $part); if (!is_array($target) || !array_key_exists($part, $target)) { $found=false; break; } $target=$target[$part]; }
                    if (!$found) { sdk_check($r, 'unresolved-ref', 'Lokal referens', false, 'Referensen saknas: ' . $value, $path, 'mtmk-openapi'); }
                } else { sdk_check($r, 'external-ref', 'Extern referens', false, 'Extern referens har inte hämtats: ' . $value, $path, 'mtmk-openapi', 'warning'); }
            } else { $walk($value, $path . '/' . $key); }
        }
    }; $walk($doc);
    sdk_check($r, 'operations', 'Dokumenterade operationer', count($operations) > 0, count($operations) . ' operationer hittades.', '/paths', 'mtmk-openapi');
    $r['normalized'] = ['title' => $info['title'] ?? '', 'version' => $info['version'] ?? '', 'openapi' => $doc['openapi'] ?? '', 'operations' => $operations, 'schemas' => array_keys($doc['components']['schemas'] ?? []), 'security_schemes' => array_keys($doc['components']['securitySchemes'] ?? []), 'references' => array_values(array_unique($refs))];
    return sdk_finish($r);
}

/** Validate the supported type/required/properties/array/enum/pattern/allOf subset of the pinned MT/MK schema. */
function sdk_schema_review($value, array $schema, array $contract, string $path, array &$r, int $depth = 0): void
{
    if ($depth > 30) { throw new InvalidArgumentException('JSON-strukturen är för djupt nästlad.'); }
    if (isset($schema['$ref'])) {
        $target = $contract;
        foreach (explode('/', substr($schema['$ref'], 2)) as $part) { $target = $target[$part] ?? []; }
        sdk_schema_review($value, $target, $contract, $path, $r, $depth + 1); return;
    }
    foreach ($schema['allOf'] ?? [] as $part) { sdk_schema_review($value, $part, $contract, $path, $r, $depth + 1); }
    $type = $schema['type'] ?? null;
    $typeOk = match ($type) { 'object' => is_object($value), 'array' => is_array($value), 'string' => is_string($value), 'boolean' => is_bool($value), 'integer' => is_int($value), 'number' => is_int($value) || is_float($value), default => true };
    if (!$typeOk) { sdk_check($r, 'schema-type', 'Datatyp', false, 'Förväntar datatypen ' . $type . '.', $path, 'mtmk-openapi'); return; }
    if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) { sdk_check($r, 'schema-enum', 'Tillåtet värde', false, 'Värdet finns inte i kontraktets lista.', $path, 'mtmk-openapi'); }
    if (is_string($value) && isset($schema['pattern']) && preg_match('~' . str_replace('~', '\~', $schema['pattern']) . '~u', $value) !== 1) { sdk_check($r, 'schema-pattern', 'Textvärde', false, 'Värdet följer inte kontraktets textregel.', $path, 'mtmk-openapi'); }
    if (is_object($value)) {
        foreach ($schema['required'] ?? [] as $key) { if (!property_exists($value, $key)) { sdk_check($r, 'schema-required', 'Obligatoriskt fält', false, 'Fältet saknas: ' . $key . '.', $path . '/' . $key, 'mtmk-openapi'); } }
        foreach ($schema['properties'] ?? [] as $key => $child) { if (property_exists($value, $key)) { sdk_schema_review($value->$key, $child, $contract, $path . '/' . $key, $r, $depth + 1); } }
    }
    if (is_array($value) && isset($schema['items'])) { foreach ($value as $i => $item) { sdk_schema_review($item, $schema['items'], $contract, $path . '/' . $i, $r, $depth + 1); } }
}

function sdk_validate_json(string $content): array
{
    $r = sdk_result('JSON kontrolleras som ett SDK API MT/MK-utkast mot det nedladdade kontraktets stödda regler och kompletterande SDK-fält. Ingen sändning sker.', ['Fullständig JSON Schema-metavalidering', 'Mottagarens registrering', 'Autentisering eller API-anrop', 'Asynkron servervalidering']);
    try { $doc = json_decode($content, false, 60, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { sdk_check($r, 'json-syntax', 'JSON kan läsas', false, 'Ogiltig JSON: ' . $e->getMessage(), '/', 'mtmk-openapi'); return sdk_finish($r); }
    $contract = sdk_parse_document(sdk_asset_content(sdk_asset('sdk-mtmk-openapi')));
    $schema = $contract['components']['schemas']['JsonApiTopLevelDocumentJsonApiResourceObjectMessageDto'];
    sdk_schema_review($doc, $schema, $contract, '', $r);
    $data = is_object($doc) ? ($doc->data ?? null) : null; $a = is_object($data) ? ($data->attributes ?? null) : null;
    sdk_check($r, 'json-type', 'Resurstyp', is_object($data) && ($data->type ?? '') === 'messages', 'data.type ska vara messages.', '/data/type', 'mtmk-openapi');
    sdk_check($r, 'json-attributes', 'Meddelandets fält', is_object($a), 'data.attributes behöver innehålla meddelandets fält.', '/data/attributes', 'mtmk-openapi');
    if (is_object($a)) {
        foreach (['sender','recipient'] as $party) {
            sdk_check($r, $party . '-id', 'Deltagaridentitet: ' . $party, is_string($a->$party ?? null) && sdk_participant($a->$party), 'Ange 0203:domän för ' . $party . '.', '/data/attributes/' . $party);
            $attention = $party . 'Attention'; $function = $a->$attention->subOrganization ?? null;
            $ok = is_object($function) && ($function->root ?? '') === 'urn:riv:infrastructure:messaging:functionalAddress' && is_string($function->extension ?? null) && trim($function->extension) !== '';
            sdk_check($r, $party . '-function', 'Funktionsadress: ' . $party, $ok, 'Ange subOrganization med functionalAddress-root och en funktionsadress i extension.', '/data/attributes/' . $attention);
        }
        $label = $a->label ?? null;
        sdk_check($r, 'json-subject', 'Rubrikens längd', is_string($label) && trim($label) !== '' && sdk_length($label) <= 256, 'Rubriken ska innehålla 1–256 tecken.', '/data/attributes/label');
        foreach (['messageId','conversationId','refToMessageId'] as $field) { if (isset($a->$field)) { sdk_check($r, 'uuid-' . $field, 'UUID: ' . $field, is_string($a->$field) && sdk_uuid($a->$field), 'Angiven identifierare ska ha UUID-format.', '/data/attributes/' . $field); } }
        $documents = $a->digitalDocument ?? []; $fileCount = 0;
        sdk_check($r, 'json-documents', 'Text eller bilaga', is_array($documents) && count($documents) > 0, 'Ange minst ett digitalDocument med text eller bilaga.', '/data/attributes/digitalDocument');
        if (is_array($documents)) {
            foreach ($documents as $i => $document) {
                if (!is_object($document)) { continue; }
                $hasText = is_array($document->contentTextBody ?? null) && count(array_filter($document->contentTextBody, static fn($v): bool => is_string($v) && trim($v) !== '')) > 0;
                $files = is_array($document->contentFiles ?? null) ? $document->contentFiles : []; $fileCount += count($files);
                sdk_check($r, 'json-document-content', 'Dokumentets innehåll', $hasText || count($files) > 0, 'Dokumentet behöver text eller bilaga.', '/data/attributes/digitalDocument/' . $i);
                foreach ($files as $j => $file) {
                    $ok = is_object($file) && is_string($file->content ?? null) && base64_decode($file->content, true) !== false && !empty($file->fileName) && !empty($file->contentType);
                    sdk_check($r, 'json-file', 'Bilagans metadata och Base64', $ok, 'Bilagan behöver filnamn, MIME-typ och giltig Base64.', '/data/attributes/digitalDocument/' . $i . '/contentFiles/' . $j);
                }
            }
        }
        sdk_check($r, 'open-test-file-limit', 'OPEN-TEST-begränsning', $fileCount <= 1, 'Nuvarande OPEN-TEST API-implementation stöder högst en bifogad fil.', '/data/attributes/digitalDocument', 'mtmk-guide', 'warning');
    }
    $r['normalized'] = json_decode($content, true);
    return sdk_finish($r);
}

function sdk_validate_certificate(string $content): array
{
    $r = sdk_result('Endast metadata i ett publikt PEM X.509-certifikat läses i minnet. Innehållet sparas inte och skickas inte till en språkmodell.', ['Certifikatkedjans tillit', 'Återkallelse/OCSP/CRL', 'Koppling till deltagaridentitet', 'Verifiering av godkänd utfärdare i vald miljö', 'Privat nyckel', 'Federationsgodkännande']);
    if (strlen($content) > 131072 || preg_match('/PRIVATE KEY|BEGIN PKCS12|BEGIN PFX/i', $content)) {
        sdk_check($r, 'public-only', 'Endast publikt certifikat', false, 'Privata nycklar och nyckelpaket får inte användas här. Välj endast ett publikt PEM-certifikat.', '/', 'certificates');
        return sdk_finish($r);
    }
    $publicOnly = preg_match('/\A\s*-----BEGIN CERTIFICATE-----\s*[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----\s*\z/D', $content) === 1;
    sdk_check($r, 'pem-public', 'Publikt PEM-certifikat', $publicOnly, $publicOnly ? 'Ett publikt PEM-certifikat har lästs.' : 'Exportera den publika delen som ett enda PEM X.509-certifikat med BEGIN CERTIFICATE.', '/', 'certificates');
    if (!$publicOnly) { return sdk_finish($r); }
    $certificate = openssl_x509_read($content); $parsed = $certificate ? openssl_x509_parse($certificate) : false;
    sdk_check($r, 'x509-parse', 'X.509 kan läsas', is_array($parsed), is_array($parsed) ? 'X.509-metadata kan läsas.' : 'Certifikatet kan inte tolkas som X.509.', '/', 'certificates');
    if (!is_array($parsed)) { return sdk_finish($r); }
    $now = time(); $from = (int) ($parsed['validFrom_time_t'] ?? 0); $to = (int) ($parsed['validTo_time_t'] ?? 0);
    sdk_check($r, 'certificate-dates', 'Giltighetsperiod', $from <= $now && $to > $now, $from <= $now && $to > $now ? 'Certifikatet är inom sin angivna giltighetsperiod.' : 'Certifikatet är utgånget eller har ännu inte börjat gälla.', '/', 'certificates');
    $key = openssl_pkey_get_public($certificate); $details = $key ? openssl_pkey_get_details($key) : [];
    $r['normalized'] = ['subject' => $parsed['subject'] ?? [], 'issuer' => $parsed['issuer'] ?? [], 'serial_number' => $parsed['serialNumberHex'] ?? '', 'valid_from' => gmdate('c', $from), 'valid_to' => gmdate('c', $to), 'days_remaining' => (int) floor(($to - $now) / 86400), 'sha256_fingerprint' => openssl_x509_fingerprint($certificate, 'sha256'), 'signature_algorithm' => $parsed['signatureTypeSN'] ?? '', 'public_key_bits' => $details['bits'] ?? null, 'key_usage' => $parsed['extensions']['keyUsage'] ?? null, 'stored' => false, 'trust_verified' => false];
    return sdk_finish($r);
}

function sdk_validate(string $format, string $content): array
{
    if (strlen($content) > 2097152) { throw new InvalidArgumentException('Förhandskontrollen stöder textfiler upp till 2 MB.'); }
    if (trim($content) === '') { throw new InvalidArgumentException('Lägg till ett dokument eller välj ett exempel.'); }
    return match ($format) { 'xml' => sdk_validate_xml($content), 'openapi' => sdk_validate_openapi($content), 'json' => sdk_validate_json($content), 'certificate' => sdk_validate_certificate($content), default => throw new InvalidArgumentException('Formatet stöds inte.') };
}
