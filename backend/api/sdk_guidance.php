<?php
declare(strict_types=1);

/** Exact procedural facts use reviewed source text rather than generated identifiers. */
function sdk_guidance(string $question, array $profile): ?array
{
    if (!in_array('SDK', $profile['allowed_services'] ?? [], true)) return null;
    $q = mb_strtolower($question, 'UTF-8');
    // These reviewed answers concern participant onboarding, not supplier verification.
    if (preg_match('/rekommenderad|open.?test|leverantör.{0,35}(test|godkänn)|accesspunktsoperatör.{0,35}(test|godkänn)/u', $q)) return null;
    $topic = null;
    if (preg_match('/testfall|obligatorisk.{0,30}test|sex.{0,20}test|\b6\b.{0,20}test|tf\s*[23]\.[013]\.[012]/u', $q)) $topic = 'tests';
    elseif (preg_match('/deltagarident|participant.identifier|smp/u', $q) && !preg_match('/förny|förnya|certifikat/u', $q)) $topic = 'identity';
    elseif (preg_match('/funktionsadress|funktionsbrevlåda/u', $q)) $topic = 'addresses';
    elseif (preg_match('/organisationsadministr|användaradministr|adressadministr|lämna.{0,20}certifikat|certifikat.{0,20}(lämna|behörighet)/u', $q)) $topic = 'roles';
    if ($topic === null) return null;
    $base = 'https://www.digg.se/saker-digital-kommunikation/';
    $p = $base . 'sdk-for-deltagarorganisationer/anslut-som-deltagarorganisation/';
    $documents = [
        'instructions' => ['title'=>'Instruktioner för anslutning till SDK','service'=>'SDK','source_url'=>$p.'instruktioner-for-anslutning-till-sdk'],
        'tests' => ['title'=>'Testfall för deltagarorganisationer inom SDK','service'=>'SDK','source_url'=>$p.'testfall-deltagarorganisation-for-federationsgodkannande-sdk'],
        'addressbook' => ['title'=>'SDK adressbok','service'=>'SDK','source_url'=>$p.'sdk-adressbok'],
        'glossary' => ['title'=>'Ordlista för Säker digital kommunikation','service'=>'SDK','source_url'=>$base.'vad-ar-saker-digital-kommunikation/ordlista-for-saker-digital-kommunikation'],
    ];
    if ($topic === 'tests') {
        $answer = "### Sex testfall inför federationsgodkännande\n\n" .
            "- **TF 2.0.1:** Ta emot ett normalt meddelande i meddelandetjänsten, vidarebefordra det till klienten och skicka meddelandekvittens.\n" .
            "- **TF 2.0.2:** Skicka ett normalt meddelande till SDK testklient och ta emot meddelandekvittensen.\n" .
            "- **TF 2.1.2:** Ta emot ett meddelande med flera bilagor och sammanlagd storlek strax under 30 MB.\n" .
            "- **TF 3.0.1:** Läsa meddelandet i den egna klienten och kontrollera innehåll, bilagor och formatering.\n" .
            "- **TF 3.0.2:** Skapa och skicka ett meddelande från klienten, med mottagaren vald genom SDK adressbok.\n" .
            "- **TF 3.1.2:** Läsa ett meddelande med flera bilagor strax under 30 MB i den egna klienten.\n\n" .
            "Testerna genomförs i **SDK QA**, med rätt anslutning och behörighet till testklienten. Registrera även de manuella resultaten där. Resultat som är äldre än sex månader behöver göras om inför ansökan.\n\n" .
            "I Underlag kan ni dokumentera ansvarig, datum, resultat och bevis för varje test. DIGG bedömer ansökan; en lokal checklista är ett förberedelseunderlag.";
        $ids = ['tests','instructions'];
    } elseif ($topic === 'identity') {
        $answer = "### Deltagaridentitet och SMP\n\n" .
            "Deltagaridentiteten är en separat organisationsidentifierare för SDK. Den ska stämma exakt med **Participant Identifier** som accesspunktsoperatören registrerar i SMP för den aktuella miljön. DIGG använder uppgifterna för att kontrollera er anslutning; en avvikelse behöver rättas med operatören.\n\n" .
            "- **Deltagaridentitet:** en identifierare enligt SDK:s regler, exempelvis det officiella QA-exemplet `0203:test.organisationsnamn.se`. Exemplet är ingen tilldelad identitet för er organisation.\n" .
            "- **Organisationsnummer:** uppgift om den juridiska organisationen i ansökan. Det ersätter inte deltagaridentiteten.\n" .
            "- **Funktionsadress:** identifierar en verksamhetsfunktion och registreras i SDK adressbok. Den är inte en personlig e-postadress eller samma sak som deltagaridentiteten.\n\n" .
            "**Nästa steg:** be operatören bekräfta exakt deltagaridentitet och dess registrering i rätt SMP-miljö, jämför med ert underlag och dokumentera vad som återstår. Publicering av ert publika O2O-certifikat är en separat aktivitet som DIGG genomför.";
        $ids = ['instructions','glossary','addressbook'];
    } elseif ($topic === 'addresses') {
        $answer = "### Funktionsadresser i SDK\n\n" .
            "En funktionsadress hör till en verksamhetsfunktion, till exempel socialtjänsten. Den ska kunna bevakas av verksamheten även när en viss person är frånvarande. Den är inte en vanlig personlig e-postadress.\n\n" .
            "- **Adressadministratören** registrerar och underhåller funktionsadresser i **SDK adressbok**.\n" .
            "- **Användaradministratören** tilldelar de behörigheter som behövs.\n" .
            "- **Accesspunktsoperatören** administrerar teknisk metadata i SMP. Det är en annan uppgift än att registrera funktionsadresser.\n\n" .
            "Dokumentera funktionens namn, syfte, ansvarig grupp, bevakning och koppling till meddelandeklienten i ert underlag. I verkstadens Hitta testmottagare kan ni undersöka publika exempel i OPEN-TEST; dessa visar inte produktionsstatus.";
        $ids = ['addressbook','instructions','glossary'];
    } else {
        $answer = "### Roller och inlämning av publikt O2O-certifikat\n\n" .
            "**Användaradministratören** tilldelar organisationsadministratör, adressadministratör och testklientbehörighet inom organisationen. **Organisationsadministratören** lämnar det publika O2O-certifikatet i adressboken. **Adressadministratören** hanterar funktionsadresser.\n\n" .
            "För certifikatet går organisationsadministratören till Mina organisationer, väljer organisation och Redigera. Enligt nuvarande instruktion ska samma publika certifikat anges i fälten för kryptering och signering. Organisationen notifierar därefter DIGG, som publicerar certifikatet i CertPub/SMP.\n\n" .
            "Om fälten saknas: be er användaradministratör kontrollera rollen organisationsadministratör. Lämna endast den publika delen; den privata nyckeln hör till meddelandetjänsten.";
        $ids = ['instructions','glossary'];
    }
    return ['mode'=>'source_guidance','answer'=>$answer,'sources'=>array_map(static fn(string $id):array=>$documents[$id],$ids)];
}
