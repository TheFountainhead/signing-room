<div>
    <style>
        .legal-page { max-width: 800px; margin: 0 auto; }
        .legal-page .card { padding: 48px; }
        .legal-page h1 { font-size: 2rem; margin-bottom: 8px; }
        .legal-page .legal-version { color: var(--ft-grey); font-style: italic; margin-bottom: 32px; font-size: 0.95rem; }
        .legal-page h2 { font-size: 1.4rem; margin-top: 40px; margin-bottom: 16px; padding-top: 24px; border-top: 1px solid var(--ft-border); }
        .legal-page h2:first-of-type { margin-top: 0; border-top: none; padding-top: 0; }
        .legal-page h3 { font-size: 1.1rem; margin-top: 24px; margin-bottom: 8px; font-family: 'Source Sans 3', sans-serif; }
        .legal-page p { margin-bottom: 12px; }
        .legal-page ul, .legal-page ol { margin-bottom: 12px; padding-left: 24px; }
        .legal-page li { margin-bottom: 6px; }
        .legal-page table { width: 100%; border-collapse: collapse; margin: 16px 0 24px; font-size: 0.95rem; }
        .legal-page th, .legal-page td { padding: 10px 14px; border: 1px solid var(--ft-border); text-align: left; }
        .legal-page th { background: var(--ft-pink-light); font-weight: 600; }
        .legal-page strong { color: var(--ft-black); }
        .legal-page .legal-footer { margin-top: 48px; padding-top: 24px; border-top: 1px solid var(--ft-border); text-align: center; color: var(--ft-grey); font-size: 0.875rem; }
        @media (max-width: 768px) {
            .legal-page .card { padding: 24px; }
            .legal-page h1 { font-size: 1.6rem; }
            .legal-page table { font-size: 0.85rem; }
            .legal-page th, .legal-page td { padding: 8px 10px; }
        }
    </style>

    <div class="legal-page fade-up">
        <div class="card">
            <h1>Almindelige Forretningsbetingelser</h1>
            <p class="legal-version">Version 1.0 — gældende fra 1. maj 2026</p>

            <h2>1. Parter og Repræsentation</h2>
            <p><strong>1.1. Leverandør</strong> — Frankston ApS ("Frankston"), CVR-nr. 34621195, Travervænget 3, 2920 Charlottenlund, Danmark.</p>
            <p><strong>1.2. Kunde</strong> — Den juridiske person (virksomhed, forening, fond eller offentlig myndighed) der opretter en konto eller indgår aftale om brug af Tjenesten.</p>
            <p><strong>1.3. Repræsentationsforhold</strong> — Den person der opretter firmakontoen bekræfter at have ret til at handle på organisationens vegne og accepterer at hæfte for dispositioner foretaget, indtil organisationen eventuelt ratificerer kontoen.</p>
            <p><strong>1.4. Dokumentationskrav</strong> — Frankston kan kræve bevis for fuldmagt (CVR-udskrift, tegningsregel) og midlertidigt begrænse kontoen, indtil dokumentation er modtaget.</p>

            <h2>2. Definitioner</h2>
            <table>
                <thead>
                    <tr><th>Begreb</th><th>Betydning</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Tjenesten</strong></td><td>Frankstons cloud-baserede løsning til elektronisk underskrift af dokumenter via MitID, inkl. admin-panel, underskriverportal og tilhørende funktionalitet på sign.frankston.io.</td></tr>
                    <tr><td><strong>Elektronisk signatur</strong></td><td>En underskrift baseret på eID-autentifikation (MitID) med PAdES-format. Opfylder eIDAS-forordningens art. 26 (AdES).</td></tr>
                    <tr><td><strong>eID</strong></td><td>Elektronisk identifikation (MitID).</td></tr>
                    <tr><td><strong>Envelope</strong></td><td>Et dokument (PDF) sendt til signering med en eller flere underskrivere og/eller seere.</td></tr>
                    <tr><td><strong>Underskriver</strong></td><td>En person der modtager et dokument til elektronisk underskrift via Tjenesten.</td></tr>
                    <tr><td><strong>Seer</strong></td><td>En person der får læse-adgang til et dokument uden signeringsret.</td></tr>
                    <tr><td><strong>Admin</strong></td><td>En bruger med adgang til administrationspanelet på Tjenesten.</td></tr>
                </tbody>
            </table>

            <h2>3. Kontostruktur og Ansvar</h2>
            <p><strong>3.1. Dataroller</strong> — Kunden er <strong>dataansvarlig</strong> for de dokumenter og personoplysninger, der behandles via Tjenesten. Frankston er <strong>databehandler</strong>. Se den tilhørende <a href="{{ route('signing-room.portal.dpa') }}">Databehandleraftale</a>.</p>
            <p><strong>3.2. Kontoadministration</strong> — Der skal altid være mindst én Admin med fuld kontrol over kontoen. Kunden er ansvarlig for at holde kontoinformationer opdaterede.</p>
            <p><strong>3.3. Kontosikkerhed</strong> — Frankston anbefaler stærke adgangskoder til alle admin-konti. Kunden er ansvarlig for at sikre, at login-oplysninger ikke deles med uvedkommende.</p>
            <p><strong>3.4. Underskriverens ansvar</strong> — Underskriveren autentificerer sig via MitID og er selv ansvarlig for at gennemlæse dokumentet før signering. Frankston er ikke part i de aftaler, der underskrives via Tjenesten.</p>

            <h2>4. Aftalens Ikrafttræden og Accept</h2>
            <p><strong>4.1.</strong> Ved oprettelse af konto og brug af Tjenesten accepterer Kunden nærværende Vilkår samt Frankstons <a href="{{ route('signing-room.portal.privacy') }}">Privatlivspolitik</a> og <a href="{{ route('signing-room.portal.dpa') }}">Standard Databehandleraftale</a>.</p>
            <p><strong>4.2. Transaktionsmails</strong> — Frankston er berettiget til at sende vigtige servicemeddelelser og juridiske varsler via e-mail til Kunden. Sådanne meddelelser kan ikke frameldes.</p>
            <p><strong>4.3.</strong> Fortsat brug af Tjenesten efter ændringer i vilkår anses som accept af de opdaterede vilkår.</p>

            <h2>5. Tjenestens Omfang og Teknisk Compliance</h2>
            <p><strong>5.1. Signaturtype</strong> — Tjenesten anvender avanceret elektronisk signatur (AdES) i PAdES-format via MitID-autentifikation leveret af Criipto/Idura. Signaturen opfylder kravene i eIDAS-forordningens art. 26.</p>
            <p><strong>5.2. Funktionalitet</strong> — Tjenesten omfatter:</p>
            <ul>
                <li>Upload og afsendelse af PDF-dokumenter til elektronisk signering</li>
                <li>Flerparts-signering med sekventielle signeringsrunder</li>
                <li>Automatiske e-mail-notifikationer til underskrivere</li>
                <li>Påmindelsesfunktion med konfigurerbart interval</li>
                <li>Underskriverportal med PDF-visning og MitID-signering</li>
                <li>Download af originale og signerede dokumenter</li>
                <li>Komplet audit trail med tidsstempel, IP-adresse og hændelsessporing</li>
            </ul>
            <p><strong>5.3. Tilgængelighed</strong> — Frankston tilstræber høj tilgængelighed af Tjenesten men garanterer ikke specifik oppetid, medmindre andet er skriftligt aftalt.</p>
            <p><strong>5.4. Leverandørvalg</strong> — Frankston kan udskifte eID- og signaturleverandører, så længe signaturstandarden bevares eller forbedres.</p>

            <h2>6. Sikkerhed og Databehandling</h2>
            <p><strong>6.1.</strong> Al trafik til og fra platformen beskyttes med TLS 1.2+. Signaturdata krypteres ved lagring.</p>
            <p><strong>6.2.</strong> Frankstons Standard Databehandleraftale (SDBA) er en integreret del af nærværende Vilkår og accepteres ved brug af Tjenesten.</p>
            <p><strong>6.3. CPR-numre</strong> — Tjenesten behandler ikke CPR-numre direkte. MitID-identifikation håndteres af Criipto/Idura, og kun den unikke MitID-identifikator gemmes i Tjenesten.</p>
            <p><strong>6.4. Audit trail</strong> — Signeringshændelser logges med tidsstempel, IP-adresse og bruger-identifikator. Disse logdata bevares så længe dokumentet er tilgængeligt i Tjenesten.</p>

            <h2>7. Dokumentopbevaring og Sletning</h2>
            <p><strong>7.1.</strong> Signerede dokumenter opbevares hos Criipto/Idura i op til 7 dage (konfigurerbart), hvorefter de automatisk slettes fra leverandørens systemer.</p>
            <p><strong>7.2.</strong> Dokumenter (originale og signerede PDF'er) opbevares i Tjenesten, så længe Kundens konto er aktiv.</p>
            <p><strong>7.3.</strong> Ved opsigelse eller lukning af kontoen er det Kundens ansvar at eksportere og downloade alle relevante dokumenter før kontoen lukkes. Frankston kan slette dokumenter og data 90 dage efter kontolukning.</p>
            <p><strong>7.4.</strong> Kunden kan til enhver tid annullere igangværende signeringer. Sletning af individuelle dokumenter kan anmodes via kontakt til Frankston.</p>

            <h2>8. Ansvarsbegrænsning</h2>
            <p><strong>8.1.</strong> Frankstons samlede erstatningsansvar, uanset retsgrund, er begrænset til et beløb svarende til Kundens samlede betalinger for de seneste 12 måneder, dog minimum 5.000 DKK.</p>
            <p><strong>8.2.</strong> Frankston er <strong>ikke ansvarlig</strong> for indirekte tab, herunder driftstab, tabt avance, tab af data eller krav rejst af tredjemand.</p>
            <p><strong>8.3.</strong> Frankston leverer en teknisk platform til elektronisk underskrift og identifikation. Frankston er ikke part i de aftaler, der underskrives via Tjenesten, og påtager sig intet ansvar for indholdet, gyldigheden eller håndhævelsen af sådanne aftaler.</p>
            <p><strong>8.4.</strong> Kunden anerkender, at ingen online-tjeneste kan garantere absolut sikkerhed. Frankston kan ikke gøres ansvarlig for tab som følge af forsætlige hackerangreb, medmindre der er tale om grov forsømmelse.</p>
            <p><strong>8.5. Force majeure</strong> — Frankston er ikke ansvarlig for manglende opfyldelse som følge af forhold uden for Frankstons rimelige kontrol, herunder naturkatastrofer, krig, pandemi, driftsforstyrrelser hos underleverandører eller nedbrud i el- eller internetinfrastruktur.</p>

            <h2>9. Pris og Betaling</h2>
            <p><strong>9.1.</strong> Priser for brug af Tjenesten aftales individuelt mellem Frankston og Kunden.</p>
            <p><strong>9.2.</strong> Frankston forbeholder sig retten til at ændre priser med 30 dages skriftligt varsel.</p>

            <h2>10. Varighed og Opsigelse</h2>
            <p><strong>10.1.</strong> Aftalen er gældende fra kontooprettelsen og løber indtil den opsiges af en af parterne.</p>
            <p><strong>10.2.</strong> Begge parter kan opsige aftalen med 30 dages skriftligt varsel.</p>
            <p><strong>10.3.</strong> Ved opsigelse er Kunden ansvarlig for at downloade alle dokumenter før kontoen lukkes, jf. afsnit 7.3.</p>
            <p><strong>10.4.</strong> Kontoen kan slettes via Profil-indstillinger i Tjenesten eller ved skriftlig henvendelse til Frankston.</p>
            <p><strong>10.5.</strong> Frankston kan opsige aftalen med øjeblikkeligt varsel ved væsentlig misligholdelse, ulovlig brug eller manglende betaling trods påmindelse.</p>

            <h2>11. Ændring af Vilkår</h2>
            <p><strong>11.1.</strong> Frankston kan ændre Vilkårene med <strong>30 dages varsel</strong> via e-mail til den registrerede kontoadministrator. Kunden anses for at have accepteret ændringen, medmindre kontoen opsiges inden ikrafttrædelsesdatoen.</p>

            <h2>12. Lovvalg og Værneting</h2>
            <p><strong>12.1.</strong> Disse Vilkår er underlagt dansk ret. Tvister søges løst i mindelighed; kan dette ikke ske, er værneting <strong>Københavns byret</strong>.</p>

            <h2>13. Kontakt</h2>
            <p>Frankston ApS, CVR 34621195<br>E-mail: <a href="mailto:fred@frankston.io">fred@frankston.io</a><br>Telefon: +45 26 18 98 17</p>

            <div class="legal-footer">
                <p>&copy; {{ date('Y') }} Frankston ApS</p>
            </div>
        </div>
    </div>
</div>
