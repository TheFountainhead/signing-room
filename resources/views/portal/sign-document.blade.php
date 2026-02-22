<div>
    {{-- Header --}}
    <div class="fade-up" style="margin-bottom: 32px;">
        <a href="{{ route('signing-room.portal.dashboard') }}" style="font-size: 0.875rem; color: var(--ft-grey); text-decoration: none; display: inline-block; margin-bottom: 8px;">
            &larr; Tilbage til mine dokumenter
        </a>
        <h1>{{ $envelope->title }}</h1>
        @if($envelope->description)
            <p style="color: var(--ft-grey); margin-top: 8px;">{{ $envelope->description }}</p>
        @endif
    </div>

    @if(session('message'))
        <div class="card fade-up" style="background: #E3F2FD; border-color: var(--ft-blue); margin-bottom: 24px; padding: 16px 24px;">
            <p style="color: var(--ft-blue); font-weight: 600;">{{ session('message') }}</p>
        </div>
    @endif

    <div class="signing-layout fade-up">
        {{-- PDF Preview --}}
        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="padding: 16px 24px; border-bottom: 1px solid var(--ft-border); background: var(--ft-pink-light); display: flex; align-items: center; justify-content: space-between;">
                <h3 style="font-size: 1rem; font-family: 'Source Sans 3', sans-serif;">Dokumentvisning</h3>
                <a href="{{ route('signing-room.portal.pdf', $signingParty) }}" target="_blank" style="font-size: 0.85rem; font-weight: 600; color: var(--ft-blue);">
                    Åbn i nyt vindue &nearr;
                </a>
            </div>
            <div style="height: 700px; background: #F5F5F5; position: relative;">
                <iframe id="pdf-viewer" style="width: 100%; height: 100%; border: none; display: none;" title="PDF-dokument"></iframe>
                <div id="pdf-loading" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; padding: 32px; text-align: center;">
                    <div style="width: 40px; height: 40px; border: 3px solid var(--ft-border); border-top-color: var(--ft-blue); border-radius: 50%; animation: spin 0.8s linear infinite; margin-bottom: 16px;"></div>
                    <p style="color: var(--ft-grey);">Indlæser dokument...</p>
                </div>
                <div id="pdf-fallback" style="display: none; flex-direction: column; align-items: center; justify-content: center; height: 100%; padding: 32px; text-align: center;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#757575" stroke-width="1.5" style="margin-bottom: 16px;">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                    <p style="color: var(--ft-dark); font-weight: 600; margin-bottom: 8px;">Dokumentet kan ikke vises her</p>
                    <p style="color: var(--ft-grey); font-size: 0.9rem; margin-bottom: 16px;">Klik herunder for at åbne dokumentet.</p>
                    <a href="{{ route('signing-room.portal.pdf', $signingParty) }}" target="_blank" class="btn-primary">
                        Se dokument
                    </a>
                </div>
            </div>
            <script>
                (function() {
                    var pdfUrl = @json(route('signing-room.portal.pdf', $signingParty));
                    fetch(pdfUrl, { credentials: 'same-origin' })
                        .then(function(res) {
                            if (!res.ok) throw new Error('HTTP ' + res.status);
                            return res.blob();
                        })
                        .then(function(blob) {
                            var url = URL.createObjectURL(blob);
                            var viewer = document.getElementById('pdf-viewer');
                            viewer.src = url;
                            viewer.style.display = 'block';
                            document.getElementById('pdf-loading').style.display = 'none';
                        })
                        .catch(function() {
                            document.getElementById('pdf-loading').style.display = 'none';
                            document.getElementById('pdf-fallback').style.display = 'flex';
                        });
                })();
            </script>
        </div>

        {{-- Signing Panel --}}
        <div>
            {{-- Status --}}
            <div class="card" style="margin-bottom: 16px;">
                <h3 style="font-size: 1rem; font-family: 'Source Sans 3', sans-serif; margin-bottom: 16px;">Underskriftstatus</h3>

                @if($envelope->expires_at)
                    <div style="font-size: 0.875rem; color: var(--ft-grey); margin-bottom: 16px;">
                        Deadline: {{ $envelope->expires_at->format('j. M Y') }}
                        @php $daysLeft = (int) now()->diffInDays($envelope->expires_at, false); @endphp
                        @if($daysLeft > 0 && $daysLeft <= 7)
                            <span style="color: var(--ft-red); font-weight: 600;">({{ $daysLeft }} dage)</span>
                        @elseif($daysLeft > 0)
                            <span>({{ $daysLeft }} dage)</span>
                        @endif
                    </div>
                @endif

                @foreach($allParties as $round => $parties)
                    <div style="margin-bottom: 12px;">
                        <div style="font-size: 0.75rem; color: var(--ft-grey); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px;">
                            Runde {{ $round }}
                        </div>
                        @foreach($parties as $party)
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--ft-border);">
                                <span style="font-size: 0.9rem; color: var(--ft-dark);">
                                    {{ $party->name }}
                                    @if($party->id === $signingParty->id)
                                        <span style="font-weight: 600;">(dig)</span>
                                    @endif
                                </span>
                                @php
                                    $statusLabel = match($party->status) {
                                        \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Signed => 'Signeret',
                                        \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Rejected => 'Afvist',
                                        default => 'Afventer',
                                    };
                                    $statusBadge = match($party->status) {
                                        \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Signed => 'badge-green',
                                        \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Rejected => 'badge-red',
                                        default => 'badge-gray',
                                    };
                                @endphp
                                <span class="badge {{ $statusBadge }}" style="font-size: 0.75rem;">{{ $statusLabel }}</span>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- Action Buttons --}}
            @if($signingParty->status !== \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Signed && $signingParty->status !== \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Rejected)
                <div class="card">
                    <h3 style="font-size: 1rem; font-family: 'Source Sans 3', sans-serif; margin-bottom: 16px;">Handling</h3>

                    @if($signingParty->idura_signatory_href)
                        <p style="font-size: 0.9rem; color: var(--ft-grey); margin-bottom: 16px;">
                            Klik herunder for at underskrive dokumentet med MitID. Du vil blive sendt til en sikker underskriftsside.
                        </p>
                        <a href="{{ $signingParty->idura_signatory_href }}" class="btn-primary" style="display: block; text-align: center; margin-bottom: 12px;">
                            Underskriv med MitID
                        </a>
                    @elseif($signingParty->idura_signatory_id)
                        <p style="font-size: 0.9rem; color: var(--ft-grey); margin-bottom: 16px;">
                            Underskriftslinket forberedes. Genindlæs siden om et øjeblik.
                        </p>
                    @else
                        <p style="font-size: 0.9rem; color: var(--ft-grey); margin-bottom: 16px;">
                            Dokumentet afventer afsenderens godkendelse før du kan underskrive.
                        </p>
                    @endif

                    <button wire:click="$set('showRejectModal', true)" class="btn-danger" style="display: block; width: 100%; text-align: center;">
                        Afvis dokument
                    </button>
                </div>
            @elseif($signingParty->status === \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Signed)
                <div class="card" style="text-align: center;">
                    <div style="font-size: 2.5rem; margin-bottom: 12px;">&#9989;</div>
                    <h3 style="font-size: 1.125rem; margin-bottom: 8px;">Underskrevet</h3>
                    <p style="color: var(--ft-grey); font-size: 0.9rem;">Du har underskrevet dette dokument {{ $signingParty->signed_at?->format('j. M Y') }}.</p>
                </div>
            @elseif($signingParty->status === \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Rejected)
                <div class="card" style="text-align: center;">
                    <div style="font-size: 2.5rem; margin-bottom: 12px;">&#10060;</div>
                    <h3 style="font-size: 1.125rem; margin-bottom: 8px;">Afvist</h3>
                    <p style="color: var(--ft-grey); font-size: 0.9rem;">Du har afvist dette dokument.</p>
                    @if($signingParty->rejection_reason)
                        <p style="color: var(--ft-grey); font-size: 0.85rem; margin-top: 8px; font-style: italic;">
                            "{{ $signingParty->rejection_reason }}"
                        </p>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Reject Modal --}}
    @if($showRejectModal)
        <div style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; display: flex; align-items: center; justify-content: center;">
            <div class="card" style="width: 100%; max-width: 480px;">
                <h2 style="font-size: 1.25rem; margin-bottom: 16px;">Afvis dokument</h2>
                <p style="color: var(--ft-grey); margin-bottom: 8px;">
                    Du er ved at afvise: <strong>{{ $envelope->title }}</strong>
                </p>
                <p style="color: var(--ft-grey); margin-bottom: 16px;">Angiv venligst en årsag for afvisningen. Afsenderen vil blive informeret.</p>
                <div class="form-group">
                    <label class="form-label">Årsag *</label>
                    <textarea wire:model="rejectionReason" class="form-input" rows="3" placeholder="Beskriv hvorfor du afviser dokumentet..."></textarea>
                    @error('rejectionReason') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button wire:click="$set('showRejectModal', false)" class="btn-outline btn-sm">Fortryd</button>
                    <button wire:click="reject" class="btn-danger btn-sm">Afvis dokument</button>
                </div>
            </div>
        </div>
    @endif

    <style>
        @keyframes spin { to { transform: rotate(360deg); } }
        .signing-layout {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 32px;
            align-items: start;
        }
        @media (max-width: 768px) {
            .signing-layout {
                grid-template-columns: 1fr;
            }
            .signing-layout iframe {
                height: 500px !important;
            }
        }
    </style>
</div>
