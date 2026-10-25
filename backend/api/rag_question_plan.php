<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_compat.php';

/** Plans the information needed to answer a class of questions, never the facts. */
function rag_question_plan(string $question, array $profile = []): array
{
    $q = mb_strtolower($question, 'UTF-8');
    $plan = ['intent'=>'general', 'instructions'=>[], 'required_facets'=>[], 'clarifying_questions'=>[], 'conditional_questions'=>[]];
    $supplier = (bool) preg_match('/leverantör|leverantor|tjänsteleverantör|operatör|driftspartner|integrationspartner|extern.{0,10}part/u', $q);
    $certificate = (bool) preg_match('/certifikat|o2o|as4/u', $q);
    $roles = $supplier && (bool) preg_match('/ansvar|åtagande|skyldighet|vem.{0,30}(sköt|hanter)|vad.{0,65}(göra själv|gör.{0,20}själv)|kommun.{0,30}själv|själva.{0,35}leverantör|vilka.{0,25}uppgifter|vad.{0,40}leverantör.{0,35}(göra|sköta)|rollfördel/u', $q);
    $formatProblem = $certificate && (bool) preg_match('/fel.{0,18}(format|fil|certifikat)|format.{0,25}(fel|problem)|kan inte.{0,30}(öppna|läsa|ladda)|konverter|pfx|p12|pem/u', $q);
    $alternative = ($supplier || preg_match('/verksamhetssystem|journalsystem|meddelandeklient|nuvarande system/u',$q)) && (bool) preg_match('/(?:stöd(?:er|jer)?|stötta|saknar|kan inte|erbjuder inte|har inte|utan|alternativ|annan).{0,45}(?:sdk|stöd|anslut)|sdk.{0,30}(saknas|stöds inte)|stöder inte|stödjer inte/u', $q);
    if ($roles) {
        $plan['intent'] = $certificate ? 'certificate_responsibilities' : 'participant_supplier_responsibilities';
        $plan['instructions'] = [
            'Besvara ansvarsfördelningen direkt: använd belägg för deltagarorganisationens kvarstående ansvar och för leverantörens egna uppgifter. Skilj ansvar från praktisk hjälp och från okända avtalsvillkor.',
            'Ett svar enbart om administratörsroller, uppladdning eller kontakt med Digg besvarar inte en bred fråga om kommunen och leverantören. Välj källcitat som förklarar båda parterna.',
            'Om källan uttryckligen beskriver grundansvaret får det inte döljas bakom att det enskilda avtalet är okänt. Detaljer i ett osynligt avtal är däremot okända.',
            'Ägande av ett certifikat, ansvar att säkerställa rätt certifikat och praktisk förvaltning är olika saker. Ett ägandecitat stöder inte att kommunen alltid själv installerar eller förvaltar certifikatet.',
        ];
        $plan['required_facets'] = [
            'participant'=>['pattern'=>'/(?:kommun\p{L}*|deltagarorganisation\p{L}*|er organisation|\bni\b).{0,100}(?:ansvar|säkerställ)|(?:ansvar|säkerställ).{0,70}(?:kommun\p{L}*|deltagarorganisation\p{L}*|er organisation)/iu', 'question'=>'Vilket ansvar behåller kommunen som deltagarorganisation?'],
            'supplier'=>['pattern'=>'/(?:leverantör|partner|extern part)\p{L}*.{0,120}(?:ansvar|hanter|sköt|installer|tillhandahåll|hjälp|teknisk|utveckl|förvalt)|(?:hanter|installer|tillhandahåll|förvalt).{0,70}(?:leverantör|partner|extern part)\p{L}*/iu', 'question'=>'Vilka praktiska och tekniska uppgifter har leverantören enligt underlaget och ert avtal?'],
        ];
        if(!$certificate){
            $plan['required_facets']['participant']['pattern']='/(?:kommun\p{L}*|deltagarorganisation\p{L}*|er organisation|\bni\b).{0,160}(?:ansvar|säkerställ|följa|följer).{0,130}(?:meddelandesystem|information|regelverk|dataskydd|sekretess)|(?:meddelandesystem|information|regelverk).{0,120}(?:kommun\p{L}*|deltagarorganisation\p{L}*).{0,60}ansvar/iu';
            $plan['required_facets']['participant']['question']='Vilket ansvar har kommunen för meddelandesystem, information och regelverk även när en leverantör hjälper till?';
        }else{
            $plan['instructions'][]='Ta också med leverantörens egna tekniska ansvarsuppgifter när källans rollavsnitt beskriver dem. Praktisk hjälp, certifikatägande och korrekt användning i meddelandesystemet får inte slås ihop till en enda ägarfråga.';
            $plan['required_facets']['supplier_technical']=['pattern'=>'/(?:leverantör|partner)\p{L}*.{0,100}(?:ansvar|säkerställ|ska).{0,100}(?:använd|meddelandesystem|kommunik|teknisk)|(?:leverantör|partner)\p{L}*.{0,100}(?:teknisk|meddelandesystem).{0,70}ansvar/iu','question'=>'Vilket eget tekniskt ansvar har leverantören för certifikatens användning i meddelandesystemet?'];
        }
    } elseif ($formatProblem) {
        $plan['intent'] = 'certificate_file_problem';
        $plan['instructions'] = [
            'Ett certifikat som inte har granskats kan inte diagnostiseras. Börja med användning, miljö och vilket indataformat mottagande system faktiskt kräver.',
            'Skilj O2O-certifikat från AS4-certifikat och en certifikatsbegäran/CSR. Överför aldrig format- eller nyckellängdskrav mellan dessa bara för att de finns på samma sida.',
            'Om källorna inte fastställer ett O2O-formatkrav ska du inte ange ett generellt PEM-, PKCS#10-, RSA- eller ECC-krav. Beskriv bara det som verkligen har belägg.',
            'Be inte om privat nyckel eller lösenord. Praktiska förtydliganden kan lämnas som frågor i missing_information utan att några krav hittas på.',
        ];
        $plan['clarifying_questions'] = [
            'Vilket filformat och vilket felmeddelande visar filen eller uppladdningen? Dela inte privat nyckel eller lösenord.',
            'Var ska det publika certifikatet användas, och gäller det OPEN-TEST, QA eller produktion?',
            'Vilket format kräver den aktuella tjänsten eller leverantören? Det behöver bekräftas innan filen konverteras.',
        ];
    } elseif ($alternative) {
        $plan['intent'] = 'supplier_alternatives';
        $plan['instructions'] = [
            'Prioritera praktiska alternativ för organisationen när en nuvarande lösning saknar stöd. Hämta sådana alternativ ur förberedelse- och upphandlingskällorna.',
            'Skilj ett möjligt tekniskt upplägg och upphandlingskrav från en rätt att tvinga en befintlig leverantör att ändra sitt system. Utan känt avtal kan ingen sådan rätt fastställas.',
            'Beskriv inte ett rekommenderat paket eller en separat klient som obligatorisk lösning.',
        ];
        $plan['required_facets'] = [
            'alternative'=>['pattern'=>'/separat|fristående|annan leverantör|flera leverantörer|helhetslösning|helhetspaket|helhet från|utveckla.{0,35}(själv|egen)|egen.{0,35}utveckl/iu', 'question'=>'Vilket annat tekniskt upplägg kan organisationen använda om den nuvarande lösningen saknar stöd?'],
        ];
    } elseif ($certificate && preg_match('/vad.{0,50}(är|innebär|betyder)|vad är det|varför.{0,30}(behöv|certifikat)/u',$q)) {
        $plan['intent']='certificate_explanation';
        $plan['instructions']=[
            'Förklara först begreppet och dess praktiska syfte på enkel svenska. Bygg inte ut en kort definitionsfråga till en lista med ansöknings- eller installationskrav.',
            'Behåll certifikattyp och användningsområde från samma källavsnitt. Påstå inte att certifikatet ensamt innebär anslutningsgodkännande.',
        ];
    } elseif (($profile['service']??'')==='mina_meddelanden' || preg_match('/mina meddelanden|digital post/u',$q)) {
        $eligibility=(bool)preg_match('/alla|vilka.{0,25}(invån|ta emot)|personer.{0,25}(inte|ta emot)|kan.{0,35}ta emot|mottagarvillkor/u',$q);
        $plan['intent']=$eligibility?'recipient_eligibility':'digital_post_overview';
        $plan['instructions']=[
            'Skilj infrastruktur från en enskild brevlåda. Förklara funktion och användning med de källor som faktiskt hämtats.',
            'Lägg inte till besparingar, minskad pappershantering, säkerhetsgarantier eller effektivitet utan uttryckligt belägg för just dessa påståenden.',
        ];
        if($eligibility){
            $plan['instructions'][]='Skilj en dokumenterad kontroll av mottagare från fullständiga mottagarvillkor. Saknad information om ett krav betyder inte att kravet inte finns. Lista inte invånargrupper eller personidentifierare om källorna inte fastställer dem.';
            $plan['required_facets']['recipient_check']=['pattern'=>'/\brecipient\b|kontroll\p{L}*.{0,90}(?:mottagar|brevlåd)|mottagar\p{L}*.{0,60}kontroll/iu','question'=>'Vilken mottagarkontroll beskrivs i underlaget? Skilj den kända kontrollen från okända fullständiga mottagarvillkor.'];
            $plan['conditional_questions'][]='Vilka mottagarvillkor och mottagargrupper framgår av de aktuella reglerna? Dra ingen slutsats om full täckning eller undantag utifrån enbart en brevlådekontroll.';
        }
    }
    return $plan;
}

/** A conservative completeness heuristic. It does not establish factual truth. */
function rag_answer_coverage_gaps(array $plan, array $claims): array
{
    $text=implode("\n",array_map(static fn(array $c):string=>(string)($c['text']??''),$claims));
    $gaps=[];
    foreach($plan['required_facets']??[] as $id=>$facet){
        if(!preg_match($facet['pattern'],$text))$gaps[$id]=$facet['question'];
    }
    return $gaps;
}

/** Surface only application questions/gaps, not unchecked model prose. */
function rag_answer_limitations(array $plan, array $coverageGaps, bool $modelReportedMissing, int $rejected): array
{
    $messages=array_values($coverageGaps);
    foreach($plan['clarifying_questions']??[] as $question)$messages[]=$question;
    if($modelReportedMissing||$rejected>0){foreach($plan['conditional_questions']??[] as $question)$messages[]=$question;}
    if($modelReportedMissing&&!$messages)$messages[]='Underlaget räcker inte för alla delar av frågan. Kontrollera de återstående detaljerna med ansvarig aktör eller i den aktuella tjänstens underlag.';
    if($rejected>0&&!$messages)$messages[]='Några föreslagna uppgifter kunde inte styrkas och har tagits bort. Endast de källstödda delarna visas.';
    return array_values(array_unique($messages));
}
