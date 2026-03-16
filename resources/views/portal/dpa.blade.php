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
            <h1>Standard Databehandleraftale</h1>
            <p class="legal-version">Version 1.0 — gældende fra 1. maj 2026</p>
            <p style="margin-bottom: 32px;">Bilag til <a href="{{ route('signing-room.portal.terms') }}">Forretningsbetingelser</a> for Frankston Underskriftrum.</p>

            <h2>1. Parter</h2>
            <p><strong>Den dataansvarlige ("Kunden")</strong> — Den virksomhed eller organisation, der opretter konto og sender dokumenter til signering via Frankston Underskriftrum.</p>
            <p><strong>Databehandleren</strong> — Frankston ApS, CVR 34621195, Travervænget 3, 2920 Charlottenlund, Danmark. Kontakt: <a href="mailto:fred@frankston.io">fred@frankston.io</a></p>

            <h2>2. Præambel</h2>
            <p><strong>2.1.</strong> Disse Bestemmelser fastsætter databehandlerens rettigheder og forpligtelser, når denne foretager behandling af personoplysninger på vegne af den dataansvarlige.</p>
            <p><strong>2.2.</strong> Bestemmelserne er udformet med henblik på parternes efterlevelse af artikel 28, stk. 3, i databeskyttelsesforordningen (GDPR).</p>
            <p><strong>2.3.</strong> I forbindelse med leveringen af Frankston Underskriftrum behandler databehandleren personoplysninger på vegne af den dataansvarlige i overensstemmelse med disse Bestemmelser.</p>

            <h2>3. Den dataansvarliges forpligtelser</h2>
            <p><strong>3.1.</strong> Den dataansvarlige er ansvarlig for at sikre, at behandlingen sker i overensstemmelse med GDPR, øvrig EU-ret og dansk national ret.</p>
            <p><strong>3.2.</strong> Den dataansvarlige har ret og pligt til at træffe beslutninger om formål og hjælpemidler for behandling af personoplysninger.</p>
            <p><strong>3.3.</strong> Den dataansvarlige er ansvarlig for, at der er et behandlingsgrundlag for de personoplysninger, som databehandleren instrueres i at behandle.</p>

            <h2>4. Databehandleren handler efter instruks</h2>
            <p><strong>4.1.</strong> Databehandleren må kun behandle personoplysninger efter dokumenteret instruks fra den dataansvarlige, medmindre det kræves i henhold til EU-ret eller dansk national ret.</p>
            <p><strong>4.2.</strong> Databehandleren underretter den dataansvarlige, hvis en instruks er i strid med GDPR eller anden databeskyttelseslovgivning.</p>

            <h2>5. Fortrolighed</h2>
            <p><strong>5.1.</strong> Databehandleren må kun give adgang til personoplysninger til personer, som er underlagt fortrolighed eller lovbestemt tavshedspligt, og kun i det nødvendige omfang.</p>

            <h2>6. Behandlingssikkerhed</h2>
            <p><strong>6.1.</strong> Databehandleren gennemfører passende tekniske og organisatoriske foranstaltninger, herunder:</p>
            <ul>
                <li>Kryptering under overførsel (TLS 1.2+) og at-rest (signaturdata)</li>
                <li>Session-baseret adgangskontrol med UUID-baserede URL'er og kryptografiske tokens</li>
                <li>Webhook-signaturvalidering (HMAC) og CSRF-beskyttelse</li>
                <li>Logning af alle signeringshændelser</li>
                <li>Regelmæssig backup af database og filer</li>
            </ul>

            <h2>7. Underdatabehandlere</h2>
            <p><strong>7.1.</strong> Godkendte underdatabehandlere:</p>
            <table>
                <thead><tr><th>Navn</th><th>Formål</th><th>Lokation</th></tr></thead>
                <tbody>
                    <tr><td><strong>Criipto A/S / Idura ApS</strong><br><small>CVR 35142207</small></td><td>MitID-autentifikation, PAdES-signering og dokumentforsegling</td><td>København, DK</td></tr>
                    <tr><td><strong>Resend Inc.</strong></td><td>E-mail-levering (notifikationer, påmindelser)</td><td>US (EU SCC'er)</td></tr>
                    <tr><td><strong>Laravel Cloud</strong></td><td>Hosting af applikation, database og fillagring</td><td>EU</td></tr>
                </tbody>
            </table>
            <p><strong>7.2.</strong> Ændringer i underdatabehandlere varsles med mindst <strong>30 dages</strong> varsel, så den dataansvarlige har mulighed for at gøre indsigelse.</p>

            <h2>8. Overførsel til tredjelande</h2>
            <p><strong>8.1.</strong> Resend Inc. kan behandle data i USA. Overførselsgrundlaget er EU-standardkontraktbestemmelser (SCC'er).</p>
            <p><strong>8.2.</strong> Øvrig behandling sker inden for EU/EØS.</p>

            <h2>9. Bistand til den dataansvarlige</h2>
            <p><strong>9.1.</strong> Databehandleren bistår den dataansvarlige med at besvare anmodninger fra registrerede (indsigt, sletning, berigtigelse, portabilitet, begrænsning, indsigelse).</p>
            <p><strong>9.2.</strong> Databehandleren bistår med anmeldelse af brud på persondatasikkerheden (inden 72 timer) og eventuelle konsekvensanalyser.</p>

            <h2>10. Brud på persondatasikkerheden</h2>
            <p><strong>10.1.</strong> Databehandleren underretter uden unødig forsinkelse den dataansvarlige efter at være blevet opmærksom på brud — senest <strong>24 timer</strong> efter.</p>
            <p><strong>10.2.</strong> Underretningen indeholder: karakteren af bruddet, omtrentligt antal berørte, sandsynlige konsekvenser og trufne foranstaltninger.</p>

            <h2>11. Sletning og returnering</h2>
            <p><strong>11.1.</strong> Ved ophør sletter databehandleren alle personoplysninger inden <strong>90 dage</strong> efter kontolukning og bekræfter sletningen.</p>
            <p><strong>11.2.</strong> Data som Frankston er retligt forpligtet til at opbevare (fx bogføringslovens 5-års krav) er undtaget.</p>

            <h2>12. Revision og inspektion</h2>
            <p><strong>12.1.</strong> Databehandleren stiller nødvendige oplysninger til rådighed for at påvise overholdelse af GDPR art. 28.</p>
            <p><strong>12.2.</strong> Databehandleren giver mulighed for revisioner efter rimelig forudgående aftale.</p>

            <h2>13. Ikrafttræden</h2>
            <p><strong>13.1.</strong> Bestemmelserne træder i kraft ved Kundens accept af Vilkårene og er gældende så længe behandling af personoplysninger finder sted.</p>

            <h2>14. Ændringer</h2>
            <p><strong>14.1.</strong> Væsentlige ændringer meddeles med mindst <strong>30 dages varsel</strong> via e-mail. Fortsat brug efter ikrafttrædelsesdatoen anses som accept.</p>

            <h2>Bilag A — Behandlingens karakter</h2>
            <h3>Formål</h3>
            <p>Levering af cloud-baseret elektronisk underskrift via MitID.</p>
            <h3>Aktiviteter</h3>
            <ul>
                <li>Upload og opbevaring af PDF-dokumenter</li>
                <li>Identifikation af underskrivere via MitID (Criipto/Idura)</li>
                <li>Facilitering af elektronisk signatur (PAdES-format)</li>
                <li>E-mail-kommunikation med underskrivere og seere</li>
                <li>Logning af signeringshændelser (audit trail)</li>
            </ul>
            <h3>Typer af personoplysninger</h3>
            <ul>
                <li>Navn og e-mailadresse</li>
                <li>MitID-identifikator (pseudonymiseret)</li>
                <li>IP-adresse og user agent</li>
                <li>Signeringstidsstempler</li>
                <li>Signaturpakke (krypteret)</li>
                <li>Enhver personoplysning i uploadede dokumenter</li>
            </ul>
            <p><em>Da Kunden uploader dokumenter frit, kan disse indeholde enhver type personoplysninger. Det er Kundens ansvar at sikre lovligt behandlingsgrundlag.</em></p>

            <h3>Kategorier af registrerede</h3>
            <ul>
                <li>Kundens medarbejdere (admins)</li>
                <li>Underskrivere af dokumenter</li>
                <li>Seere af dokumenter</li>
            </ul>

            <h3>Varighed</h3>
            <p>Behandlingen fortsætter så længe Kunden har en aktiv konto. Data slettes 90 dage efter kontolukning.</p>

            <div class="legal-footer">
                <p>&copy; {{ date('Y') }} Frankston ApS</p>
            </div>
        </div>
    </div>
</div>
