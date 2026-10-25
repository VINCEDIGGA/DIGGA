<?php
declare(strict_types=1);

/** The public snapshots are data. Retrieval never executes directions found in documents. */
function sdk_retrieve_chunks(PDO $pdo, string $question, array $services, int $limit): array
{
    if ($services && !in_array('SDK', $services, true) && !in_array('DIGG', $services, true)) { return []; }
    try {
        $rows = $pdo->query('SELECT id,source_id,chunk_number,title,service,source_url,content,metadata_json FROM sdk_source_chunks')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    return sdk_rank_source_chunks($rows, $question, $services, $limit);
}

/** Separate ranking from storage so coverage can be checked without a model or database writes. */
function sdk_rank_source_chunks(array $rows, string $question, array $services, int $limit): array
{
    if ($limit < 1 || ($services && !in_array('SDK', $services, true) && !in_array('DIGG', $services, true))) { return []; }
    $text = mb_strtolower($question, 'UTF-8');
    $stop = ['eller','det','den','detta','denna','och','som','att','för','vad','hur','vilka','vilken','varför','med','kan','ska','behöver','från','till','inte','har','ett','oss','vår','vårt','kommun','kommunen','sdk','steg','fråga','checklistpunkten','förklara'];
    $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];
    $tokens = array_values(array_filter(array_unique($tokens), static fn(string $v): bool => mb_strlen($v)>2 && !in_array($v,$stop,true)));
    $intents = sdk_retrieval_intents($text);
    // Compound words such as systemleverantör otherwise miss helhetsleverantör entirely.
    if (preg_match('/leverantör|driftspartner|integrationspartner|extern part/u', $text)) { $tokens[] = 'leverantör'; }
    if ($intents['responsibility']) { $tokens[] = 'ansvar'; }
    $priority=[];
    if (preg_match('/smp|deltagarident|participant|accesspunkt|organisationsnummer/u',$text)) { $priority=['connection-instructions','connection-journey','glossary']; $tokens=array_merge($tokens,['deltagaridentitet','smp']); }
    if (preg_match('/certifikat|o2o|pem|x509|x\.509|privat.*nyckel/u',$text)) { $priority=['certificates','certificate-renewal','connection-instructions']; $tokens=array_merge($tokens,['certifikat','organisationsadministratör']); }
    if (preg_match('/testfall|sex.*test|obligatorisk|testklient|tf\s*[23]/u',$text)) { $priority=['qa-tests','test-client','recommended-tests']; $tokens=array_merge($tokens,['testfall']); }
    if (preg_match('/funktionsadress|adressbok|behörighet|administratör/u',$text) && !$priority) { $priority=['addressbook-guide','addressbook-admin','connection-instructions']; }
    if (preg_match('/xml|xsd|schema|openapi|json|meddelandeformat/u',$text) && !$priority) { $priority=['message-spec','message-validation','mtmk-guide','mtmk-openapi']; }
    if (preg_match('/messageid|conversationid|mt\s*[\/-]\s*mk|meddelandeklient.{0,60}meddelandetjänst|meddelandetjänst.{0,60}meddelandeklient/u',$text)) { $priority=['mtmk-guide','mtmk-openapi','faq','message-spec']; }
    if (preg_match('/adressbok.{0,15}api|api.{0,15}adressbok/u',$text)) { $priority=['addressbook-api','addressbook-guide']; }
    if (preg_match('/ansluten|anslutna.*kommun|status/u',$text) && !$priority) { $priority=['municipalities']; }
    $tokens = array_values(array_unique($tokens));
    $ranked=[];
    foreach ($rows as $row) {
        if ($services && !in_array($row['service'],$services,true)) { continue; }
        $content = mb_strtolower($row['content'],'UTF-8'); $score=0.0;
        foreach ($tokens as $token) { $score += min(4,substr_count($content,$token)) * (mb_strlen($token)>5?2.0:1.0); }
        [$intentScore, $facets] = sdk_retrieval_passage_intent($row, $content, $intents);
        $score += $intentScore;
        if ($score<=0) { continue; }
        $title = mb_strtolower((string)$row['title'],'UTF-8');
        foreach($tokens as $token)if(mb_strpos($title,$token)!==false)$score+=3;
        // A named library source should outrank pages which merely mention it.
        if(mb_strlen($title)>12&&mb_strpos($text,$title)!==false)$score+=35;
        $pos=array_search($row['source_id'],$priority,true);
        if ($pos!==false) { $score += 16 - $pos*3; }
        if ((int)$row['chunk_number']===0) { $score += 2; }
        // A matching passage beats an unrelated first-page excerpt; key points remain available as chunk zero.
        // Chunk zero is an editorial manifest summary, not original webpage wording.
        // Keep this explicit through draft checks, support review and the evidence UI.
        $row['origin'] = (int) $row['chunk_number'] === 0 ? 'reviewed_manifest_key_point' : 'official_snapshot';
        $row['retrieval_facets'] = $facets;
        $row['score']=$score; $row['chunk_id']='sdk-'.$row['id']; $ranked[]=$row;
    }
    usort($ranked,static fn(array $a,array $b): int => ($b['score']<=>$a['score']) ?: ((int)$a['id']<=>(int)$b['id']));
    $result=[]; $perSource=[]; $seen=[];
    $add = static function(array $row) use (&$result, &$perSource, &$seen, $limit): bool {
        $sid=$row['source_id']; $id=$row['chunk_id'];
        if (count($result)>=$limit || isset($seen[$id]) || ($perSource[$sid]??0)>=2) { return false; }
        $result[]=$row; $seen[$id]=true; $perSource[$sid]=($perSource[$sid]??0)+1;
        return true;
    };
    // Reserve relevant evidence for distinct parts of a broad question before filling by score.
    // These are source passages, never prewritten answers; identifiers and original content stay intact.
    $coverage=[];
    if ($intents['as4_request']) { $coverage[]='as4_request'; }
    if ($intents['alternatives']) { $coverage=['supplier_alternatives','supplier_choice']; }
    if ($intents['responsibility'] && (!$intents['as4_request'] || $intents['o2o'])) {
        $coverage=array_merge($coverage,$intents['certificate']
            ? ['participant_responsibility','certificate_ownership','certificate_roles','supplier_renewal','supplier_tasks']
            : ['participant_responsibility','supplier_tasks']);
    }
    if ($intents['certificate_intro']) { $coverage[]='certificate_definition'; }
    if ($intents['renewal']) { $coverage[]='supplier_renewal'; }
    foreach (array_unique($coverage) as $facet) {
        foreach ($ranked as $row) {
            if (in_array($facet,$row['retrieval_facets'],true) && (isset($seen[$row['chunk_id']]) || $add($row))) { break; }
        }
    }
    foreach ($ranked as $row) {
        $add($row);
        if(count($result)>=$limit) {break;}
    }
    usort($result,static fn(array $a,array $b): int => ($b['score']<=>$a['score']) ?: ((int)$a['id']<=>(int)$b['id']));
    return $result;
}

/** Recognise work and ownership questions without treating every "vem" or "krav" as role intent. */
function sdk_retrieval_intents(string $text): array
{
    $certificate = (bool) preg_match('/certifikat|o2o|pem|x\.?509|\bcsr\b|privat.{0,15}nyckel/u',$text);
    $responsibility = (bool) preg_match('/ansvar|åtagande|skyldighet|förpliktelse|ägande|vem.{0,35}(äger|sköter|hanterar|lämnar|publicerar|lägger upp)|vems.{0,25}certifikat|vad.{0,50}(kommun|organisation).{0,30}göra.{0,15}själv|vilka.{0,30}(uppgifter|skyldigheter).{0,30}(kvar|själv)|ska.{0,70}(leverantör|partner).{0,30}eller.{0,25}(vi|kommun)/u',$text);
    $sourcing = (bool) preg_match('/leverantör|partner|verksamhetssystem|journalsystem|system|accesspunkt|meddelandeklient|klient/u',$text);
    $gap = (bool) preg_match('/(?:inte|ej).{0,30}(?:stöd|stöder|stödjer|klarar|erbjuder)|(?:stöd|stöder|stödjer|klarar|erbjuder).{0,20}(?:inte|ej)|saknar.{0,30}(?:sdk|stöd|funktion)|(?:sdk|stöd).{0,20}saknas|kan inte.{0,40}(?:sdk|anslut)|utan.{0,30}(?:sdk|integration)/u',$text);
    $choice = (bool) preg_match('/alternativ|annan leverantör|annat system|byta.{0,25}(?:system|leverantör)|separat.{0,15}klient|upphandla.{0,20}helhet|olika leverantör|samma.{0,25}(?:företag|leverantör)|dela.{0,20}accesspunkt|accesspunkt.{0,30}(?:separat|paket)|integrera senare/u',$text);
    $renewal = $certificate && (bool) preg_match('/förny|löper.{0,15}ut|certifikatbyte|byta.{0,15}certifikat/u',$text);
    $as4 = $certificate && (bool) preg_match('/\bas4\b|certifikatsbegäran|pkcs\s*#?\s*10|accesspunktsoperatör.{0,40}(?:beställ|certifikat|csr)/u',$text);
    $intro = $certificate && !$renewal && !$as4 && (bool) preg_match('/vad.{0,40}(?:är|betyder|innebär|används)|vad är det|varför.{0,35}(?:certifikat|behöver)|förklara.{0,35}(?:o2o|certifikat)/u',$text);
    return ['certificate'=>$certificate,'o2o'=>(bool)preg_match('/\bo2o\b|organisation till organisation/u',$text),'responsibility'=>$responsibility,'alternatives'=>$sourcing&&($gap||$choice),'renewal'=>$renewal,'as4_request'=>$as4,'certificate_intro'=>$intro];
}

/** Add narrowly scoped source/passage signals; generic header matches must not crowd out an answer facet. */
function sdk_retrieval_passage_intent(array $row, string $content, array $intents): array
{
    if ((int)$row['chunk_number']===0) { return [0,[]]; }
    $sid=$row['source_id']; $score=0; $facets=[];
    if ($intents['responsibility'] && (!$intents['as4_request'] || $intents['o2o'])) {
        if ($sid==='participant-rules' && preg_match('/deltagarorganisationen ansvarar för (?:sina meddelandesystem|den information|egen anslutning)/u',$content)) { $score+=34; $facets[]='participant_responsibility'; }
        if ($sid==='technical-checklist' && preg_match('/helhetsleverantören.{0,100}(?:installation|konfiguration|hjälper)|certifikatet ägs av deltagarorganisationen/u',$content)) { $score+=28; $facets[]='supplier_tasks'; }
        if ($intents['certificate'] && $sid==='certificates' && preg_match('/2\.\s*roller och ansvar/u',$content)) { $score+=26; $facets[]='certificate_roles'; }
        if ($intents['certificate'] && $sid==='technical-checklist' && str_contains($content,'certifikatet ägs av deltagarorganisationen')) { $score+=18; $facets[]='certificate_ownership'; }
        if ($intents['certificate'] && $sid==='participant-rules' && str_contains($content,'deltagarorganisationen ska vid var tid ha ett giltigt o2o-certifikat')) { $score+=34; $facets[]='participant_responsibility'; }
    }
    if ($intents['alternatives']) {
        if ($sid==='preparation' && preg_match('/separat klient|upphandla en helhet|köpa den separat|låta de agera accesspunktsoperatör/u',$content)) { $score+=42; $facets[]='supplier_alternatives'; }
        if ($sid==='faq' && preg_match('/(?:en eller flera valfria leverantörer|fristående klient|separat klient|leverantörsoberoende)/u',$content)) { $score+=25; $facets[]='supplier_choice'; }
        if ($sid==='technical-checklist' && preg_match('/upphandla\/avropa helhetsleverantör/u',$content)) { $score+=10; }
    }
    if ($intents['certificate_intro'] && $sid==='certificates' && preg_match('/o2o\s*\(organisation till organisation\)/u',$content)) { $score+=38; $facets[]='certificate_definition'; }
    if (($intents['renewal'] || ($intents['certificate'] && $intents['responsibility'])) && $sid==='certificate-renewal' && preg_match('/stöd från leverantör|leverantör planerar och genomför förnyelsen/u',$content)) { $score+=26; $facets[]='supplier_renewal'; }
    if ($intents['as4_request'] && $sid==='certificates' && preg_match('/6\.4\.1\.1\s*as4-certifikat/u',$content)) { $score+=32; $facets[]='as4_request'; }
    return [$score,$facets];
}
