<div>
    {{-- Hero Section with animated illustration --}}
    <div class="fade-up" style="margin-bottom: 48px;">
        <div class="card" style="overflow: hidden; padding: 0; border-radius: 12px;">
            <div style="width: 100%; height: 280px; position: relative; overflow: hidden;">
                {{-- Original Gemini illustration with Ken Burns animation --}}
                <img src="{{ asset('assets/images/heroes/signing-room-hero.webp') }}"
                     alt=""
                     style="width: 110%; height: 110%; object-fit: cover; display: block; position: absolute; top: -5%; left: -5%; animation: heroFloat 20s ease-in-out infinite;">

                {{-- Signature stroke overlay --}}
                <svg viewBox="0 0 1400 400" preserveAspectRatio="xMidYMid slice"
                     style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none;">
                    <defs>
                        <linearGradient id="sig-grad" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0%" stop-color="rgba(255,255,255,0.9)"/>
                            <stop offset="100%" stop-color="rgba(255,241,229,0.8)"/>
                        </linearGradient>
                    </defs>
                    <path id="signature-path"
                          d="M280,220 C310,220 330,195 355,180 C380,165 395,155 415,150
                             C435,145 445,165 455,180 C465,195 475,205 495,200
                             C515,195 530,170 550,158 C570,146 585,150 605,162
                             C625,174 640,192 660,188 C680,184 695,162 715,152
                             C735,142 755,148 775,162 C795,176 808,195 828,190
                             C848,185 858,165 878,155 C898,145 918,150 938,165
                             C958,180 965,185 985,174 C1005,163 1015,148 1035,152
                             C1055,156 1065,172 1075,178"
                          fill="none" stroke="url(#sig-grad)" stroke-width="2.5"
                          stroke-linecap="round" stroke-linejoin="round" opacity="0.7"
                          style="stroke-dasharray: 1200; stroke-dashoffset: 1200;">
                        <animate attributeName="stroke-dashoffset" from="1200" to="0"
                                 dur="3.5s" begin="1.2s" fill="freeze"
                                 calcMode="spline" keySplines="0.4 0 0.2 1"/>
                    </path>
                    {{-- Finishing flourish --}}
                    <path d="M1075,178 C1085,181 1092,170 1088,162 C1084,154 1074,158 1077,168 C1080,178 1094,184 1108,174"
                          fill="none" stroke="rgba(255,255,255,0.6)" stroke-width="2"
                          stroke-linecap="round"
                          style="stroke-dasharray: 100; stroke-dashoffset: 100;">
                        <animate attributeName="stroke-dashoffset" from="100" to="0"
                                 dur="0.5s" begin="4.5s" fill="freeze"/>
                    </path>
                </svg>
            </div>
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
