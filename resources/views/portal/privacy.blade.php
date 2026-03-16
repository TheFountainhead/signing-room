<div>
    <style>
        .legal-page { max-width: 800px; margin: 0 auto; }
        .legal-page .card { padding: 48px; }
        .legal-page h1 { font-size: 2rem; margin-bottom: 8px; }
        .legal-page .legal-version { color: var(--ft-grey); font-style: italic; margin-bottom: 32px; font-size: 0.95rem; }
        .legal-page h2 { font-size: 1.4rem; margin-top: 40px; margin-bottom: 16px; padding-top: 24px; border-top: 1px solid var(--ft-border); }
        .legal-page h2:first-of-type { margin-top: 0; border-top: none; padding-top: 0; }
        .legal-page p { margin-bottom: 12px; }
        .legal-page ul { margin-bottom: 12px; padding-left: 24px; }
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
            <h1>Privatlivspolitik</h1>
            <p class="legal-version">Version 1.0 — gældende fra 1. maj 2026</p>

            <p style="color: var(--ft-grey); font-style: italic; margin-bottom: 32px;">Kunden og brugere opfordres til at læse privatlivspolitikken, da den beskriver, hvordan personoplysninger behandles. Hvis privatlivspolitikken ændres væsentligt, vil Frankston ApS orientere berørte brugere via e-mail.</p>

            <h2>&sect; 1 Dataansvarlig &amp; Kontakt</h2>
            <p><strong>Frankston ApS</strong>, CVR 34621195, Travervænget 3, 2920 Charlottenlund, Danmark.</p>
            <p>Kontakt: <a href="mailto:fred@frankston.io">fred@frankston.io</a> / +45 26 18 98 17</p>

            <h2>&sect; 2 Kontekster &amp; Roller</h2>
            <p>Denne politik gælder for følgende scenarier:</p>
            <ul>
                <li><strong>Admin-bruger</strong> — Du har oprettet en konto og administrerer dokumenter til signering. Kunden (konto-ejer) er dataansvarlig for de dokumenter der sendes til signering; Frankston er databehandler.</li>
                <li><strong>Underskriver</strong> — Du modtager et signeringslink via e-mail. Kunden der sendte dokumentet er dataansvarlig; Frankston er databehandler for Kunden. Du er datasubjekt.</li>
                <li><strong>Seer</strong> — Du modtager læse-adgang til et dokument. Samme roller som for underskrivere.</li>
            </ul>

            <h2>&sect; 3 Datakategorier &amp; Opbevaring</h2>
            <p><strong>Kontodata (Admin):</strong> Navn, e-mail, adgangskode (hashed), CPR (valgfrit) — opbevares så længe kontoen er aktiv. Slettes eller anonymiseres 90 dage efter kontolukning.</p>
            <p><strong>Underskriver-/seerdata:</strong> Navn, e-mail, signeringsstatus, tidsstempel — opbevares så længe det tilknyttede dokument er tilgængeligt i Tjenesten.</p>
            <p><strong>Identifikationsdata:</strong> MitID-identifikator, IP-adresse, user agent, signeringstidsstempel — opbevares som del af audit trail så længe dokumentet eksisterer.</p>
            <p><strong>Signaturdata:</strong> Krypteret signaturpakke fra Criipto/Idura (PAdES) — krypteret at-rest i databasen.</p>
            <p><strong>Dokument- &amp; signaturdata:</strong> Uploadede PDF'er og signerede PDF'er — opbevares på Frankstons servere så længe kontoen er aktiv. Hos Criipto/Idura opbevares signerede dokumenter i op til 7 dage.</p>

            <h2>&sect; 4 Formål &amp; Retsgrundlag</h2>
            <table>
                <thead><tr><th>Formål</th><th>Retsgrundlag (GDPR)</th></tr></thead>
                <tbody>
                    <tr><td>Kontooprettelse og drift</td><td>art. 6(1)(b) — opfyldelse af aftale</td></tr>
                    <tr><td>Levering af signeringstjeneste</td><td>art. 6(1)(b) — opfyldelse af aftale</td></tr>
                    <tr><td>Identifikation via MitID</td><td>art. 6(1)(b) — opfyldelse af aftale</td></tr>
                    <tr><td>Sikkerhed og forebyggelse af misbrug</td><td>art. 6(1)(f) — legitim interesse</td></tr>
                    <tr><td>Audit trail og dokumentation</td><td>art. 6(1)(f) — legitim interesse</td></tr>
                    <tr><td>Service- og funktionsmails</td><td>art. 6(1)(b) — opfyldelse af aftale</td></tr>
                    <tr><td>Overholdelse af lovkrav</td><td>art. 6(1)(c) — retlig forpligtelse</td></tr>
                </tbody>
            </table>

            <h2>&sect; 5 Opbevaringsperioder</h2>
            <table>
                <thead><tr><th>Data</th><th>Opbevaringsperiode</th></tr></thead>
                <tbody>
                    <tr><td>Aktiv konto</td><td>Data bevares så længe kontoen er aktiv</td></tr>
                    <tr><td>Lukket konto</td><td>Data slettes/anonymiseres 90 dage efter kontolukning</td></tr>
                    <tr><td>Signerede dokumenter (Criipto/Idura)</td><td>Op til 7 dage efter signering</td></tr>
                    <tr><td>Signerede dokumenter (Frankston)</td><td>Så længe kontoen er aktiv</td></tr>
                    <tr><td>Audit trail</td><td>Så længe det tilknyttede dokument eksisterer</td></tr>
                </tbody>
            </table>

            <h2>&sect; 6 Underdatabehandlere</h2>
            <table>
                <thead><tr><th>Underdatabehandler</th><th>Formål</th><th>Lokation</th></tr></thead>
                <tbody>
                    <tr><td><strong>Criipto A/S / Idura ApS</strong></td><td>MitID-autentifikation, PAdES-signering</td><td>Danmark (EU)</td></tr>
                    <tr><td><strong>Resend Inc.</strong></td><td>E-mail-levering</td><td>EU/US</td></tr>
                    <tr><td><strong>Laravel Cloud</strong></td><td>Hosting af applikation og database</td><td>EU</td></tr>
                </tbody>
            </table>
            <p>Frankston overfører ikke personoplysninger til lande uden for EU/EØS uden særskilt aftale og passende overførselsgrundlag.</p>

            <h2>&sect; 7 Datasubjekters Rettigheder</h2>
            <p>Du har følgende rettigheder i henhold til GDPR:</p>
            <ul>
                <li><strong>Indsigt</strong> — få bekræftelse på, om og hvilke personoplysninger vi behandler om dig</li>
                <li><strong>Berigtigelse</strong> — få urigtige eller ufuldstændige oplysninger rettet</li>
                <li><strong>Sletning</strong> — få dine oplysninger slettet, når de ikke længere er nødvendige</li>
                <li><strong>Begrænsning</strong> — anmode om begrænsning af behandling under visse betingelser</li>
                <li><strong>Dataportabilitet</strong> — få en kopi af dine data i struktureret, maskinlæsbart format</li>
                <li><strong>Indsigelse</strong> — mod behandling baseret på legitim interesse</li>
            </ul>
            <p><strong>For admin-brugere:</strong> Kontakt <a href="mailto:fred@frankston.io">fred@frankston.io</a>.</p>
            <p><strong>For underskrivere/seere:</strong> Kontakt den organisation (Kunden) der sendte dig dokumentet. Kunden er dataansvarlig for dine data.</p>
            <p>Svar gives inden 30 dage.</p>

            <h2>&sect; 8 Sikkerhed</h2>
            <p>Frankston sikrer dine data gennem passende tekniske og organisatoriske foranstaltninger, herunder:</p>
            <ul>
                <li>Kryptering under overførsel (TLS 1.2+)</li>
                <li>Kryptering af signaturdata at-rest i databasen</li>
                <li>UUID-baserede URL'er (ikke sekventielle ID'er)</li>
                <li>Kryptografiske tokens til signeringslinks</li>
                <li>Token-udløb for signeringslinks</li>
                <li>Webhook-signaturvalidering (HMAC)</li>
                <li>CSRF-beskyttelse på alle formularer</li>
            </ul>

            <h2>&sect; 9 Cookies</h2>
            <p>Frankston Underskriftrum bruger udelukkende <strong>strengt nødvendige</strong> førsteparts-cookies til login-session, signeringsportal-session og CSRF-beskyttelse. Der bruges ingen analytics-, marketing- eller tredjepartscookies.</p>

            <h2>&sect; 10 Ændringer</h2>
            <p>Opdateringer til denne politik offentliggøres på denne side. Væsentlige ændringer notificeres via e-mail.</p>

            <h2>&sect; 11 Kontakt &amp; Klage</h2>
            <p>Har du spørgsmål, kontakt <a href="mailto:fred@frankston.io">fred@frankston.io</a>.</p>
            <p>Du kan klage til Datatilsynet (Carl Jacobsens Vej 35, 2500 Valby — <a href="https://www.datatilsynet.dk" target="_blank" rel="noopener">datatilsynet.dk</a>).</p>

            <div class="legal-footer">
                <p>&copy; {{ date('Y') }} Frankston ApS</p>
            </div>
        </div>
    </div>
</div>
