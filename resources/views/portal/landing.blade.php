<div>
    {{-- Hero Section with animated illustration --}}
    <div class="fade-up" style="margin-bottom: 48px;">
        <div class="card" style="overflow: hidden; padding: 0; border-radius: 12px;">
            <div style="width: 100%; height: 280px; position: relative; background: #FAF6F1; overflow: hidden;">
                <svg viewBox="0 0 1400 400" preserveAspectRatio="xMidYMid slice" style="width: 100%; height: 100%; display: block;">
                    <defs>
                        <linearGradient id="wave1-grad" x1="0" y1="0" x2="1" y2="0.5">
                            <stop offset="0%" stop-color="#1B365D" stop-opacity="0.85"/>
                            <stop offset="100%" stop-color="#2B8C8C" stop-opacity="0.7"/>
                        </linearGradient>
                        <linearGradient id="wave2-grad" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0%" stop-color="#2B8C8C" stop-opacity="0.6"/>
                            <stop offset="100%" stop-color="#A8D5D5" stop-opacity="0.5"/>
                        </linearGradient>
                        <linearGradient id="wave3-grad" x1="0" y1="0" x2="1" y2="0.3">
                            <stop offset="0%" stop-color="#E8846B" stop-opacity="0.55"/>
                            <stop offset="100%" stop-color="#F0B4A4" stop-opacity="0.4"/>
                        </linearGradient>
                        <linearGradient id="sig-grad" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0%" stop-color="#1B365D"/>
                            <stop offset="40%" stop-color="#1B365D"/>
                            <stop offset="100%" stop-color="#2B8C8C"/>
                        </linearGradient>
                    </defs>

                    {{-- Background wave shapes --}}
                    <path d="M0,320 C200,260 350,180 500,200 C650,220 700,300 850,280 C1000,260 1100,180 1250,220 C1350,245 1400,260 1400,260 L1400,400 L0,400 Z"
                          fill="url(#wave1-grad)">
                        <animate attributeName="d" dur="8s" repeatCount="indefinite" values="
                            M0,320 C200,260 350,180 500,200 C650,220 700,300 850,280 C1000,260 1100,180 1250,220 C1350,245 1400,260 L1400,400 L0,400 Z;
                            M0,300 C200,280 350,200 500,220 C650,240 700,280 850,260 C1000,240 1100,200 1250,240 C1350,260 1400,280 L1400,400 L0,400 Z;
                            M0,320 C200,260 350,180 500,200 C650,220 700,300 850,280 C1000,260 1100,180 1250,220 C1350,245 1400,260 L1400,400 L0,400 Z"/>
                    </path>

                    <path d="M0,240 C180,200 300,280 500,250 C700,220 800,160 1000,190 C1150,210 1300,280 1400,250 L1400,400 L0,400 Z"
                          fill="url(#wave2-grad)">
                        <animate attributeName="d" dur="10s" repeatCount="indefinite" values="
                            M0,240 C180,200 300,280 500,250 C700,220 800,160 1000,190 C1150,210 1300,280 1400,250 L1400,400 L0,400 Z;
                            M0,260 C180,220 300,260 500,230 C700,200 800,180 1000,210 C1150,230 1300,260 1400,230 L1400,400 L0,400 Z;
                            M0,240 C180,200 300,280 500,250 C700,220 800,160 1000,190 C1150,210 1300,280 1400,250 L1400,400 L0,400 Z"/>
                    </path>

                    <path d="M0,280 C150,320 350,220 550,260 C750,300 850,240 1050,270 C1200,290 1350,340 1400,310 L1400,400 L0,400 Z"
                          fill="url(#wave3-grad)">
                        <animate attributeName="d" dur="12s" repeatCount="indefinite" values="
                            M0,280 C150,320 350,220 550,260 C750,300 850,240 1050,270 C1200,290 1350,340 1400,310 L1400,400 L0,400 Z;
                            M0,300 C150,300 350,240 550,280 C750,280 850,260 1050,250 C1200,270 1350,320 1400,330 L1400,400 L0,400 Z;
                            M0,280 C150,320 350,220 550,260 C750,300 850,240 1050,270 C1200,290 1350,340 1400,310 L1400,400 L0,400 Z"/>
                    </path>

                    {{-- Floating orbs --}}
                    <circle cx="180" cy="90" r="14" fill="#A8D5D5" opacity="0.5">
                        <animate attributeName="cy" dur="6s" repeatCount="indefinite" values="90;75;90"/>
                        <animate attributeName="opacity" dur="6s" repeatCount="indefinite" values="0.5;0.7;0.5"/>
                    </circle>
                    <circle cx="1050" cy="120" r="10" fill="#E8846B" opacity="0.45">
                        <animate attributeName="cy" dur="7s" repeatCount="indefinite" values="120;105;120"/>
                        <animate attributeName="opacity" dur="7s" repeatCount="indefinite" values="0.45;0.65;0.45"/>
                    </circle>
                    <circle cx="1250" cy="180" r="12" fill="#F0B4A4" opacity="0.4">
                        <animate attributeName="cy" dur="5s" repeatCount="indefinite" values="180;165;180"/>
                    </circle>
                    <circle cx="350" cy="150" r="8" fill="#2B8C8C" opacity="0.35">
                        <animate attributeName="cy" dur="8s" repeatCount="indefinite" values="150;140;150"/>
                    </circle>

                    {{-- Animated signature stroke --}}
                    <path id="signature-path"
                          d="M320,190 C340,190 360,175 380,160 C400,145 410,130 430,125
                             C450,120 460,140 470,155 C480,170 490,185 510,180
                             C530,175 540,150 560,140 C580,130 590,135 610,145
                             C630,155 650,175 670,170 C690,165 700,145 720,135
                             C740,125 760,130 780,145 C800,160 810,180 830,175
                             C850,170 860,150 880,140 C900,130 920,135 940,150
                             C960,165 970,170 990,160 C1010,150 1020,130 1040,135
                             C1060,140 1070,160 1080,165"
                          fill="none" stroke="url(#sig-grad)" stroke-width="3.5"
                          stroke-linecap="round" stroke-linejoin="round"
                          style="stroke-dasharray: 1200; stroke-dashoffset: 1200;">
                        <animate attributeName="stroke-dashoffset" from="1200" to="0"
                                 dur="3s" begin="0.8s" fill="freeze"
                                 calcMode="spline" keySplines="0.4 0 0.2 1"/>
                    </path>

                    {{-- Pen dot that follows the signature --}}
                    <circle r="4" fill="#1B365D" opacity="0">
                        <animate attributeName="opacity" values="0;0;1;1;0" keyTimes="0;0.2;0.21;0.95;1" dur="3.8s" begin="0.8s" fill="freeze"/>
                        <animateMotion dur="3s" begin="0.8s" fill="freeze" calcMode="spline" keySplines="0.4 0 0.2 1">
                            <mpath href="#signature-path"/>
                        </animateMotion>
                    </circle>

                    {{-- Decorative swirl after signature --}}
                    <path d="M1080,165 C1090,168 1095,158 1090,150 C1085,142 1075,145 1078,155 C1081,165 1095,172 1110,162"
                          fill="none" stroke="#2B8C8C" stroke-width="2.5"
                          stroke-linecap="round"
                          style="stroke-dasharray: 120; stroke-dashoffset: 120;">
                        <animate attributeName="stroke-dashoffset" from="120" to="0"
                                 dur="0.6s" begin="3.6s" fill="freeze"
                                 calcMode="spline" keySplines="0.4 0 0.2 1"/>
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
