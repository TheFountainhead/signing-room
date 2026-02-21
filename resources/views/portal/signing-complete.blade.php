<div>
    <div class="fade-up" style="display: flex; align-items: center; justify-content: center; min-height: calc(100vh - 280px); padding: 48px 0;">
        <div class="card" style="max-width: 560px; width: 100%; text-align: center; padding: 64px 48px; border-radius: 12px;">
            <div style="margin-bottom: 24px;">
                <svg viewBox="0 0 120 120" width="96" height="96" style="margin: 0 auto;">
                    <circle cx="60" cy="60" r="56" fill="#E8F5E9" stroke="var(--ft-green)" stroke-width="2"/>
                    <path d="M38 62 L52 76 L82 46" stroke="var(--ft-green)" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>

            <h1 style="font-size: 2rem; margin-bottom: 16px;">Tak for din underskrift</h1>

            <p style="color: var(--ft-dark); margin-bottom: 8px; font-size: 1.125rem; line-height: 1.7;">
                Din underskrift er registreret.
            </p>
            <p style="color: var(--ft-grey); margin-bottom: 40px;">
                Du vil modtage en kopi af det signerede dokument pr. e-mail, når alle parter har underskrevet.
            </p>

            <div style="display: flex; gap: 16px; justify-content: center;">
                <a href="{{ route('signing-room.portal.dashboard') }}" class="btn-primary">
                    Mine dokumenter
                </a>
                <a href="{{ route('signing-room.portal.landing') }}" class="btn-outline">
                    Til forsiden
                </a>
            </div>
        </div>
    </div>
</div>
