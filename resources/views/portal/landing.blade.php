<div>
    {{-- Hero Section --}}
    <div style="display: flex; align-items: center; justify-content: center; min-height: calc(100vh - 280px); padding: 48px 0;">
        <div class="card fade-up" style="max-width: 640px; width: 100%; text-align: center; padding: 64px 48px; border-radius: 12px;">
            {{-- Hero illustration placeholder --}}
            <div style="margin-bottom: 32px;">
                <svg viewBox="0 0 120 120" width="96" height="96" style="margin: 0 auto;">
                    <circle cx="60" cy="60" r="56" fill="var(--ft-pink)" stroke="var(--ft-blue)" stroke-width="2"/>
                    <rect x="38" y="30" width="44" height="56" rx="4" fill="var(--ft-paper)" stroke="var(--ft-border)" stroke-width="1.5"/>
                    <line x1="46" y1="44" x2="74" y2="44" stroke="var(--ft-border)" stroke-width="2"/>
                    <line x1="46" y1="52" x2="74" y2="52" stroke="var(--ft-border)" stroke-width="2"/>
                    <line x1="46" y1="60" x2="66" y2="60" stroke="var(--ft-border)" stroke-width="2"/>
                    <path d="M54 72 L60 78 L74 64" stroke="var(--ft-green)" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>

            <h1 style="margin-bottom: 16px;">Underskriftrum</h1>
            <p style="color: var(--ft-dark); margin-bottom: 8px; font-size: 1.125rem; line-height: 1.7;">
                Her kan du underskrive dokumenter sikkert med MitID.
            </p>
            <p style="color: var(--ft-grey); margin-bottom: 40px; font-size: 1rem;">
                Log ind for at se dine dokumenter og foretage digitale underskrifter.
            </p>

            <a href="{{ route('signing-room.portal.dashboard') }}" class="btn-primary" style="font-size: 1.1rem; padding: 16px 40px;">
                Log på underskriftrum
            </a>

            <div style="margin-top: 32px; font-size: 0.875rem; color: var(--ft-grey);">
                <p>Sikker digital underskrift med MitID</p>
            </div>
        </div>
    </div>

    {{-- Features --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; padding: 0 0 48px;">
        <div class="card card-hover fade-up" style="text-align: center; padding: 32px;">
            <div style="font-size: 2rem; margin-bottom: 12px;">&#128274;</div>
            <h3 style="font-size: 1.125rem; margin-bottom: 8px;">Sikker signering</h3>
            <p style="color: var(--ft-grey); font-size: 0.95rem;">Alle underskrifter foretages med MitID og overholder eIDAS-forordningen.</p>
        </div>
        <div class="card card-hover fade-up" style="text-align: center; padding: 32px;">
            <div style="font-size: 2rem; margin-bottom: 12px;">&#128196;</div>
            <h3 style="font-size: 1.125rem; margin-bottom: 8px;">Juridisk bindende</h3>
            <p style="color: var(--ft-grey); font-size: 0.95rem;">Signerede dokumenter opfylder kravene til avancerede elektroniske signaturer (AES).</p>
        </div>
        <div class="card card-hover fade-up" style="text-align: center; padding: 32px;">
            <div style="font-size: 2rem; margin-bottom: 12px;">&#9889;</div>
            <h3 style="font-size: 1.125rem; margin-bottom: 8px;">Hurtigt og nemt</h3>
            <p style="color: var(--ft-grey); font-size: 0.95rem;">Modtag dokumenter, gennemse og underskriv — alt sammen online.</p>
        </div>
    </div>
</div>
