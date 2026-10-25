"""Refresh the explicitly curated, public DIGG material (never follows arbitrary links)."""
import concurrent.futures
import datetime
import hashlib
import html
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import urllib.request

BASE = Path(__file__).resolve().parents[1]
SDK = 'https://www.digg.se/saker-digital-kommunikation/'
P = SDK + 'sdk-for-deltagarorganisationer/'
L = SDK + 'sdk-for-leverantorer-av-meddelandesystem/'
TODAY = '2026-10-15'
SOURCE_ROWS = [
 ('preparation','Förberedelser inför anslutning till SDK',P+'vagledningar-och-rekommendationer-infor-anslutning-till-sdk/forberedelser-infor-anslutning-till-sdk','förberedelse','deltagarorganisation','alla','1.5',None,['Utgå från verksamhetens behov, informationsutbyte och motparter.','Planera teknisk lösning och informationssäkerhet före anslutningen.']),
 ('connection-journey','Anslutningsresa för deltagarorganisationer',P+'anslut-som-deltagarorganisation/anslutningsresa-for-deltagarorganisationer','anslutning','deltagarorganisation','QA/produktion','1.5.2',None,['Anslutningen omfattar förberedelser, QA och ansökan till produktion.','Skilj på deltagarorganisationens, leverantörens och Diggs aktiviteter.']),
 ('technical-checklist','Checklista för teknisk anslutning med helhetsleverantör',P+'vagledningar-och-rekommendationer-infor-anslutning-till-sdk/forberedelser-infor-anslutning-till-sdk/checklistor/checklista-for-teknisk-anslutning-till-sdk-med-helhetsleverantor','anslutning','deltagarorganisation','QA/produktion',None,'2026-05-29',['Bekräfta ansvar och tekniska uppgifter med helhetsleverantören.','Använd checklistan för certifikat, QA-tester och förberedelse inför produktion.']),
 ('connection-instructions','Instruktioner för anslutning till SDK',P+'anslut-som-deltagarorganisation/instruktioner-for-anslutning-till-sdk','anslutning','deltagarorganisation','QA/produktion',None,'2026-10-09',['Deltagaridentiteten ska överensstämma med Participant Identifier registrerad av accesspunktsoperatören i aktuell SMP-miljö.','Ange accesspunktsoperatör, accesspunktsidentitet och meddelandesystemets namn/version/ID.','Organisationsadministratören lägger samma publika O2O-certifikat i båda certifikatfälten och meddelar Digg; Digg publicerar i SMP.','Behörig företrädare kan underteckna digitalt eller analogt enligt instruktionerna.']),
 ('initial-form','Formulär – Anslutning av deltagarorganisationer',P+'anslut-som-deltagarorganisation/formular---anslutning-av-deltagarorganisationer-till-sdk','anslutning','deltagarorganisation','QA',None,None,['Den publika initiala anmälan beskriver organisationens avsikt och kontaktuppgifter.','Efter granskning skickar Digg underlag för anmälan till SDK QA; prototypen skickar ingen anmälan.']),
 ('participant-rules','Regelverk för deltagarorganisationer inom SDK',P+'anslutningsavtal-regelverk-samt-bilagor/regelverk-for-deltagarorganisationer-inom-sdk','regelverk','deltagarorganisation','alla','1.7',None,['Regelverket gäller tillsammans med bilagor för deltagarorganisationer.','Organisationen ansvarar för följsamhet, säkerhet och sitt meddelandeutbyte; ett PoC-resultat är inget federationsgodkännande.']),
 ('security-trust','Informationssäkerhets- och tillitsmodell',P+'vagledningar-och-rekommendationer-infor-anslutning-till-sdk/informationssakerhets--och-tillitsmodell','säkerhet','deltagarorganisation','alla',None,None,['SDK bygger på gemensamma säkerhetskrav och tillit mellan organisationer.','Organisatoriskt ansvar och tekniska skydd behövs vid utbyte av känslig information.']),
 ('glossary','Ordlista för Säker digital kommunikation',SDK+'vad-ar-saker-digital-kommunikation/ordlista-for-saker-digital-kommunikation','begrepp','alla','alla',None,None,['SDK betyder Säker digital kommunikation.','MK är meddelandeklient, MT meddelandetjänst, AP accesspunkt och SMP metadata-/certifikatpubliceringstjänst.','O2O avser organisation till organisation, och funktionsadresser avser verksamhetsfunktioner.']),
 ('participant-intro','SDK för deltagarorganisationer',P.rstrip('/'),'översikt','deltagarorganisation','alla',None,None,['SDK används för säkert informationsutbyte mellan anslutna organisationer.','Meddelandeklient, meddelandetjänst och accesspunkt har olika uppgifter i meddelandeflödet.']),
 ('addressbook-guide','SDK adressbok',P+'anslut-som-deltagarorganisation/sdk-adressbok','adressbok','deltagarorganisation','QA/produktion',None,None,['Organisationer registrerar verksamhetens funktionsadresser i adressboken.','Funktionsadressen representerar en funktion eller brevlåda, inte en personlig e-postadress.']),
 ('addressbook-admin','Handledning för administratörer i SDK adressbok',P+'anslut-som-deltagarorganisation/sdk-adressbok/handledning-for-administratorer-i-sdk-adressbok','adressbok','administratör','QA/produktion',None,None,['Användaradministratör tilldelar roller. Organisationsadministratör hanterar organisation och publika certifikat.','Adressadministratör hanterar funktionsadresser; testklientanvändare får använda testklienten.']),
 ('municipalities','Anslutna kommuner',SDK+'vilka-ar-anslutna-till-sdk/anslutna-kommuner','status','deltagarorganisation','QA/produktion',None,'2026-10-13',['Tabellen visar kommuners olika anslutningsstatus.','Kontrollera aktuell officiell status och konkreta funktionsadresser inför ett planerat informationsutbyte.']),
 ('support','Kontaktvägar för support, incident- och felhantering',SDK+'kontaktvagar-for-support-incident--och-felhantering-inom-sdk','support','alla','alla','1.6',None,['Kontrollera driftinformation och rätt komponent vid problem.','Leverantör, accesspunktsoperatör, deltagarorganisation och Digg har olika supportansvar.']),
 ('faq','Frågor och svar om SDK',P+'fragor-och-svar-om-sdk','FAQ','deltagarorganisation','alla',None,None,['SDK använder funktionsadresser; individer kan anges som referenser.','Hela SDK-meddelandet inklusive bilagor får vara högst 30 MB.','Organisationen ansvarar för lokala behörigheter och loggning av meddelandehantering.']),
 ('message-spec','SDK Innehållsspecifikation Meddelande',L+'tekniska-specifikationer/sdk-tekniska-specifikationer/sdk-innehallsspecifikation-meddelande','meddelande','leverantör av meddelandesystem','alla','1.5.2',None,['Meddelandet har metadata, avsändare/mottagare, funktionsadresser, referenser, text och bilagor.','UUID, UTC-tid, rubrikens längd, adresser och meddelandets struktur ska valideras.','Innehållsspecifikationen beskriver SDK-meddelanden; generella dokument blir inte automatiskt SDK-kompatibla genom formatbyte.']),
 ('message-examples','Exempel för SDK-meddelande',L+'tekniska-specifikationer/sdk-tekniska-specifikationer/sdk-innehallsspecifikation-meddelande/exempel-for-sdk-meddelande','meddelande','leverantör av meddelandesystem','test','3.1','2025-01-24',['Digg länkar till officiella XML-exempel, XSD och Schematron i diggsweden/sdk-docs.','Exempelfilerna innehåller historiska testadresser och ska användas som exempel.']),
 ('message-validation','Schema och innehållsvalidering',L+'tekniska-specifikationer/sdk-tekniska-specifikationer/sdk-innehallsspecifikation-meddelande/schema-och-innehallsvalidering','meddelande','leverantör av meddelandesystem','alla','1.5','2026-01-08',['XSD kontrollerar XML-struktur; ytterligare innehållsregler behövs.','Schematron-filen använder XSLT 2. Prototypen kör XSD och ett deklarerat urval av innehållsregler.']),
 ('mtmk-openapi','Open API specifikation MT/MK',L+'tekniska-beskrivningar/apier-for-sdk/sdk-api-mt-mk/open-api-specifikation-mt-mk','API','leverantör av meddelandesystem','OPEN-TEST','1.0.0','2024-12-19',['Offentlig YAML beskriver REST-operationer, datatyper och autentisering mellan MT och MK.','Nedladdad verifieringskontrakt anger OpenAPI 3.0.3 och API-version 1.0.0.']),
 ('mtmk-guide','SDK API MT/MK Implementationsguide',L+'tekniska-beskrivningar/apier-for-sdk/sdk-api-mt-mk/sdk-api-mt-mk-implementationsguide','API','leverantör av meddelandeklient','OPEN-TEST',None,None,['Verifieringstjänsten kräver ansökan och tilldelade OAuth client credentials.','En ren MK/API-konsument använder en tilldelad funktionsadress under testklienten och registrerar inte egna adresser.','OPEN-TEST tillåter bara en bifogad fil och sammanför flera textblock; validering sker asynkront så HTTP 200 är inte bevis på godkänt meddelande.']),
 ('qa-tests','Testfall deltagarorganisation för federationsgodkännande SDK',P+'anslut-som-deltagarorganisation/testfall-deltagarorganisation-for-federationsgodkannande-sdk','test','deltagarorganisation','QA',None,'2026-10-09',['Sex fall: TF 2.0.1 ta emot/kvittera, TF 2.0.2 skicka/ta emot kvittens, TF 2.1.2 ta emot strax under 30 MB, TF 3.0.1 visa/läsa, TF 3.0.2 skapa/adressera/skicka, TF 3.1.2 visa/läsa strax under 30 MB.','Testresultat sparas i sex månader; äldre tester måste göras om före ansökan.','Tester kräver QA-konfiguration, registrerade funktionsadresser och rollen testklientanvändare.']),
 ('recommended-tests','Rekommenderade testfall för deltagarorganisationer',P+'anslut-som-deltagarorganisation/rekommenderade-testfall-for-deltagarorganisationer','test','deltagarorganisation','QA',None,None,['Rekommenderade verifieringstester kompletterar obligatoriska anslutningstester.','Planera verksamhetslika tester utifrån lokala rutiner och komponenter.']),
 ('test-client','SDK testklient',P+'anslut-som-deltagarorganisation/sdk-testklient','test','deltagarorganisation/leverantör','QA/OPEN-TEST',None,None,['Testklienten hjälper organisationer och leverantörer att verifiera meddelandeutbyte.','Faktisk användning kräver ett av Digg uppsatt konto och rätt miljö/tekniska förutsättningar.']),
 ('certificates','Anslutningsinformation certifikat',L+'anslut-som-leverantor-av-meddelandesystem/anslutningsresa-for-leverantorer-av-meddelandesystem/anslutningsinformation-certifikat','certifikat','tekniskt ansvarig','alla',None,'2026-04-21',['O2O används för kryptering och signering av nyttolasten mellan organisationer.','Godkända certifikatutfärdare och rotkedjor beror på aktuell miljö.','Publika rotkedjor/truststores är inte organisationens eget operativa certifikat.']),
 ('certificate-renewal','Instruktion för förnyelse av O2O-certifikat',P+'redan-ansluten-deltagarorganisation/instruktion-for-fornyelse-av-o2o-certifikat','certifikat','organisationsadministratör','QA/produktion',None,'2026-05-08',['Organisationsadministratören lämnar det publika certifikatet i båda fälten i adressboken och notifierar Digg med angiven miljö.','Vid överenskommen tidpunkt publicerar Digg i SMP och organisationen/leverantören installerar den privata nyckeln i meddelandetjänsten.','Prototypens metadataanalys verifierar inte kedja, återkallelse, identitet eller federationsgodkännande.']),
 ('addressbook-api','SDK Adressbok API', 'https://open-test.digg.se/addressbook/api/documentation/api.html','API','API-konsument','OPEN-TEST','1.2',None,['Publika läsoperationer finns för organisationer, funktionsadresser och kodverk.','Läsningar kräver ingen autentisering. CORS-header saknas; använd en PHP-proxy med fasta tillåtna mål.','OPEN-TEST innehåller testdata; en funktionsadress i testmiljön säger inget om produktionsanslutning.']),
 ('services','Diggs digitala tjänster','https://www.digg.se/digitala-tjanster','Ena','alla','alla',None,None,['Digitala tjänster har olika anslutningsprocesser och tjänstespecifika krav.','Håll kunskapskällor och underlag kopplade till rätt tjänst.']),
 ('digital-post','Anslut till Mina meddelanden','https://www.digg.se/digitala-tjanster/digital-post/digital-post-for-dig-som-offentlig-aktor/anslut','digital post','offentlig aktör','test/produktion',None,None,['Mina meddelanden gäller digital post och har en egen ansöknings-, avtals- och testprocess.','Blanda inte digital post till invånare med SDK:s informationsutbyte mellan organisationer.']),
]

class TextExtractor(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.parts, self.skip, self.started, self.main, self.has_main = [], 0, False, 0, False
    def handle_starttag(self, tag, attrs):
        if tag == 'main': self.main += 1; self.has_main = True
        if tag in ('script','style','nav'): self.skip += 1
        if tag == 'h1' and (self.main or not self.has_main): self.started = True
        if self.started and tag in ('p','div','h1','h2','h3','h4','li','tr','pre','br'): self.parts.append('\n')
        if self.started and tag in ('td','th'): self.parts.append(' | ')
    def handle_endtag(self, tag):
        if tag in ('script','style','nav') and self.skip: self.skip -= 1
        if tag == 'main' and self.main: self.main -= 1; self.started = False
        if self.started and tag in ('p','h1','h2','h3','h4','li','tr','pre'): self.parts.append('\n')
    def handle_data(self, data):
        if self.started and not self.skip: self.parts.append(data)

def fetch(url):
    if urllib.parse.urlparse(url).hostname not in ('www.digg.se','open-test.digg.se','raw.githubusercontent.com','api.github.com'):
        raise ValueError('Host is not in material refresh allowlist')
    accept = 'application/yaml' if url.endswith('/q/openapi') else 'text/html,application/yaml,text/plain,application/json'
    req = urllib.request.Request(url, headers={'User-Agent':'DIGGA student PoC material snapshot', 'Accept':accept})
    for attempt in range(4):
        try:
            with urllib.request.urlopen(req, timeout=25) as response:
                raw=response.read(5_000_000)
                return raw, response.headers.get_content_type()
        except Exception:
            if attempt == 3: raise

def source(row):
    sid,title,url,category,role,environment,version,updated,points=row
    result=dict(id=sid,title=title,url=url,category=category,role=role,environment=environment,version=version,updated_at=updated,last_checked=TODAY,service=('Mina meddelanden' if sid=='digital-post' else 'DIGG' if sid=='services' else 'SDK'),summary=points[0],key_points=points)
    try:
        raw,_=fetch(url); text=raw.decode('utf-8','replace'); parser=TextExtractor(); parser.feed(text)
        content=''.join(parser.parts)
        # The API documentation uses a generated document with no h1; preserve its body text.
        if sid=='addressbook-api' and len(content.strip())<300:
            content=re.sub('<[^>]+>',' ',re.sub(r'<(script|style).*?</\1>','',text,flags=re.S|re.I))
        content=html.unescape(content)
        content=content.split('Hjälpte denna information dig?')[0]
        content=content.split('Hjälpte denna information dig')[0]
        content=content.split('Hjälpte denna information dig')[0]
        content=re.sub(r'[ \t\r\xa0]+',' ',content)
        content='\n'.join(line.strip() for line in content.splitlines() if line.strip()).strip()
        if len(content)<150: raise ValueError('Main content extraction was too short')
        # Hash the extracted LF text; store exactly one final newline on every OS.
        filename=f'snapshots/{sid}.txt'; (BASE/filename).write_bytes((content+'\n').encode('utf-8'))
        updated_match=re.search(r'Senast uppdaterad:\s*(\d{1,2})\s+(januari|februari|mars|april|maj|juni|juli|augusti|september|oktober|november|december)\s+(\d{4})',text)
        if updated_match:
            months=['januari','februari','mars','april','maj','juni','juli','augusti','september','oktober','november','december']
            result['updated_at']=f'{updated_match[3]}-{months.index(updated_match[2])+1:02}-{int(updated_match[1]):02}'
        result.update(status='downloaded',local_path='backend/data/sdk/'+filename,content_chars=len(content),sha256=hashlib.sha256(content.encode()).hexdigest())
        print(f'{sid}: {len(content)} chars')
    except Exception as exc:
        result.update(status='unavailable',content_chars=0,error=str(exc))
        print(f'{sid}: FAILED {exc}')
    return result

def main():
    (BASE/'snapshots').mkdir(parents=True,exist_ok=True); (BASE/'assets').mkdir(exist_ok=True); (BASE/'vendor').mkdir(exist_ok=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        sources=list(pool.map(source,SOURCE_ROWS))
    repo=json.loads(fetch('https://api.github.com/repos/diggsweden/sdk-docs/commits/main')[0]); revision=repo['sha']
    rawbase=f'https://raw.githubusercontent.com/diggsweden/sdk-docs/{revision}/Meddelandesystem/Meddelandetyper/sdk-meddelande-3/'
    assets=[]
    asset_rows=[
      ('sdk-message-valid','Officiellt SDK XML-exempel','xml','messageWithAttachments3.xml',rawbase+'sdk-meddelande-v3.1/example/messageWithAttachments3.xml','SDK Meddelande 3.1','Apache-2.0 (retain file notice)'),
      ('sdk-message-invalid-date','Officiellt XML-exempel med felaktigt datum/UUID','xml','TF2.4.1.xml',rawbase+'sdk-meddelande-testdata-v3.1/felhantering/TF2.4.1.xml','SDK testdata 3.1','Repository CC0-1.0; individual notices apply'),
      ('sdk-message-invalid-sender','Officiellt XML-exempel med felaktigt kodverk','xml','TF2.4.2.xml',rawbase+'sdk-meddelande-testdata-v3.1/felhantering/TF2.4.2.xml','SDK testdata 3.1','Repository CC0-1.0; individual notices apply'),
      ('sdk-message-xsd','SDK XML-schema','xml','infrastructure_messaging_MessageWithAttachments_3.0.xsd',rawbase+'sdk-meddelande-v3.1/sdk-schema-message-v3/infrastructure_messaging_MessageWithAttachments_3.0.xsd','3.0 namespace, package 3.1','Apache-2.0'),
      ('sdk-message-schematron','SDK Schematron – regelreferens','xml','MessageConstraints.xml',rawbase+'sdk-meddelande-v3.1/sdk-schematron-v3/MessageConstraints.xml','SDK Meddelande 3.1','Apache-2.0'),
      ('sdk-mtmk-openapi','Officiellt SDK MT/MK OpenAPI-kontrakt','openapi','sdk-mtmk.openapi.yaml','https://open-test.digg.se/sdk-api/q/openapi','API 1.0.0 / OpenAPI 3.0.3','Apache-2.0'),
      ('sdk-addressbook-openapi','Officiellt SDK Adressbok OpenAPI-kontrakt','openapi','sdk-addressbook-v1.2.openapi.yaml','https://open-test.digg.se/addressbook/api/documentation/sdk-addressbook-v1.2.openapi.yaml','1.2','CC-BY-SA-4.0'),
    ]
    for aid,title,fmt,name,url,version,license_ in asset_rows:
        raw,_=fetch(url); (BASE/'assets'/name).write_bytes(raw)
        assets.append(dict(id=aid,title=title,url=url,format=fmt,local_path='backend/data/sdk/assets/'+name,status='downloaded',version=version,license=license_,sha256=hashlib.sha256(raw).hexdigest(),bytes=len(raw),last_checked=TODAY,repository_revision=revision if 'githubusercontent' in url else None))
    # The XSD is self-contained: dependencies are checked rather than assumed.
    xsd=(BASE/'assets'/'infrastructure_messaging_MessageWithAttachments_3.0.xsd').read_text(encoding='utf-8')
    if re.search(r'<(?:xs|xsd):(?:include|import)\b',xsd): raise RuntimeError('New schema dependencies must be downloaded and pinned before release')
    symfony=json.loads(fetch('https://api.github.com/repos/symfony/yaml/commits/7.3')[0])['sha']
    tree=json.loads(fetch(f'https://api.github.com/repos/symfony/yaml/git/trees/{symfony}?recursive=1')[0])['tree']
    for item in tree:
        fname=item['path']
        if item['type']=='blob' and not fname.startswith(('Tests/','Command/','.github/')) and (fname.endswith('.php') or fname=='LICENSE'):
            target=BASE/'vendor'/'symfony-yaml'/fname; target.parent.mkdir(parents=True,exist_ok=True)
            target.write_bytes(fetch(f'https://raw.githubusercontent.com/symfony/yaml/{symfony}/{fname}')[0])
    deprecation=json.loads(fetch('https://api.github.com/repos/symfony/deprecation-contracts/commits/main')[0])['sha']
    for fname in ('function.php','LICENSE'):
        target=BASE/'vendor'/'symfony-deprecation'/fname; target.parent.mkdir(parents=True,exist_ok=True)
        target.write_bytes(fetch(f'https://raw.githubusercontent.com/symfony/deprecation-contracts/{deprecation}/{fname}')[0])
    (BASE/'vendor'/'metadata.json').write_text(json.dumps(dict(name='Symfony YAML',url='https://github.com/symfony/yaml',branch='7.3',revision=symfony,deprecation_revision=deprecation,license='MIT',purpose='Parse OpenAPI YAML locally with object and custom-tag construction disabled; PHP 8.2+, native ctype required'),indent=2),encoding='utf-8')
    sample={'data':{'type':'messages','attributes':{'messageId':'4ceeb6f1-f078-47a9-94cc-1efb03aedbec','conversationId':'4ceeb6f1-f078-47a9-94cc-1efb03aedbec','creationDateTime':'2026-10-15T08:00:00.000Z','confidentiality':False,'label':'Syntetiskt SDK-utkast från DIGGA','sender':'0203:testkommun.se','recipient':'0203:annan-testkommun.se','senderAttention':{'subOrganization':{'root':'urn:riv:infrastructure:messaging:functionalAddress','extension':'barnoungdom.0203:testkommun.se','label':'Syntetisk avsändarfunktion'}},'recipientAttention':{'subOrganization':{'root':'urn:riv:infrastructure:messaging:functionalAddress','extension':'barnoungdom.0203:annan-testkommun.se','label':'Syntetisk mottagarfunktion'}},'digitalDocument':[{'documentId':'SDK-Meddelande','documentName':'Syntetiskt underlag','index':'1','contentTextBody':['Exempel utan verkliga personuppgifter. Ingen sändning sker.']}]}}}
    sample_bytes=(json.dumps(sample,ensure_ascii=False,indent=2)+'\n').encode('utf-8'); (BASE/'assets'/'sdk-message.synthetic.json').write_bytes(sample_bytes)
    assets.append(dict(id='sdk-message-json',title='Syntetiskt lokalt MT/MK JSON-utkast',url=L+'tekniska-beskrivningar/apier-for-sdk/sdk-api-mt-mk/sdk-api-mt-mk-implementationsguide',format='json',local_path='backend/data/sdk/assets/sdk-message.synthetic.json',status='generated',version='MT/MK 1.0.0',license='Local PoC example, CC0-1.0',sha256=hashlib.sha256(sample_bytes).hexdigest(),bytes=len(sample_bytes),last_checked=TODAY,synthetic=True,notice='Ej registrerade exempelidentiteter; ingen sändning.'))
    (BASE/'assets'/'SDK-REPOSITORY-LICENSE').write_bytes(fetch(f'https://raw.githubusercontent.com/diggsweden/sdk-docs/{revision}/LICENSE')[0])
    manifest=dict(last_checked=TODAY,repository_revision=revision,sources=sources,assets=assets,coverage=dict(source_count=len(sources),downloaded_sources=sum(s['status']=='downloaded' for s in sources),schema_dependencies='self-contained XSD; no include/import',schematron='Reference downloaded. XSD plus documented rule subset runs in PHP; no XSLT 2 processor.'),notes=['Official pages are stored as main-content snapshots, not executable instructions.','Snapshots may be stale after last_checked; use original links for current procedures.','The PoC never submits forms, publishes certificates or sends SDK messages.'])
    (BASE/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(f"Sources {manifest['coverage']['downloaded_sources']}/{len(sources)}; assets {len(assets)}; pinned {revision}")

if __name__=='__main__': main()
