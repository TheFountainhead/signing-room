<div>
    {{-- Hero Section with illustration --}}
    <div class="fade-up" style="margin-bottom: 48px;">
        <div class="card" style="overflow: hidden; padding: 0; border-radius: 12px;">
            <img src="{{ asset('assets/images/heroes/signing-room-hero.webp') }}"
                 alt=""
                 style="width: 100%; height: 280px; object-fit: cover; display: block;">
            <div style="padding: 48px; text-align: center;">
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

                <div style="margin-top: 24px; font-size: 0.875rem; color: var(--ft-grey);">
                    <p>Sikker digital underskrift med MitID</p>
                </div>
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
