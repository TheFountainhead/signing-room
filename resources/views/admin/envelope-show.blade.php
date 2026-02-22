<div>
    {{-- Header --}}
    <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 32px;">
        <div>
            <a href="{{ route('signing-room.admin.index') }}" style="font-size: 0.875rem; color: var(--ft-grey); text-decoration: none; display: inline-block; margin-bottom: 8px;">
                &larr; Tilbage til oversigt
            </a>
            <h1>{{ $signingEnvelope->title }}</h1>
            @if($signingEnvelope->description)
                <p style="color: var(--ft-grey); margin-top: 8px;">{{ $signingEnvelope->description }}</p>
            @endif
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            @php
                $badgeClass = match($signingEnvelope->status) {
                    \Fountainhead\SigningRoom\Enums\EnvelopeStatus::Completed => 'badge-green',
                    \Fountainhead\SigningRoom\Enums\EnvelopeStatus::Sent,
                    \Fountainhead\SigningRoom\Enums\EnvelopeStatus::PartiallySigned => 'badge-blue',
                    \Fountainhead\SigningRoom\Enums\EnvelopeStatus::Expired,
                    \Fountainhead\SigningRoom\Enums\EnvelopeStatus::Cancelled => 'badge-red',
                    default => 'badge-gray',
                };
            @endphp
            <span class="badge {{ $badgeClass }}">{{ $signingEnvelope->status->label() }}</span>
        </div>
    </div>

    @if(session('success'))
        <div style="background: #E8F5E9; color: var(--ft-green); padding: 16px; border-radius: 8px; margin-bottom: 24px;">
            {{ session('success') }}
        </div>
    @endif

    @if(session('warning'))
        <div style="background: #FFF8E1; color: #F57F17; padding: 16px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #FFE082;">
            {{ session('warning') }}
        </div>
    @endif

    {{-- Info Cards --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px;">
        <div class="card" style="text-align: center;">
            <div style="font-size: 0.8rem; color: var(--ft-grey); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Oprettet</div>
            <div style="font-weight: 600; color: var(--ft-black);">{{ $signingEnvelope->created_at->format('j. M Y H:i') }}</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 0.8rem; color: var(--ft-grey); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Udløber</div>
            <div style="font-weight: 600; color: var(--ft-black);">
                {{ $signingEnvelope->expires_at ? $signingEnvelope->expires_at->format('j. M Y') : 'Ingen udløb' }}
            </div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 0.8rem; color: var(--ft-grey); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Runde</div>
            <div style="font-weight: 600; color: var(--ft-black);">{{ $signingEnvelope->current_round }} / {{ $signingEnvelope->total_rounds }}</div>
        </div>
        <div class="card" style="text-align: center;">
            <div style="font-size: 0.8rem; color: var(--ft-grey); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Underskrifter</div>
            <div style="font-weight: 600; color: var(--ft-black);">
                {{ $signingEnvelope->parties->where('status.value', 'signed')->count() }} / {{ $signingEnvelope->parties->where('role', 'signer')->count() }}
            </div>
        </div>
    </div>

    {{-- Action Buttons --}}
    <div style="display: flex; gap: 12px; margin-bottom: 32px;">
        @if($signingEnvelope->status === \Fountainhead\SigningRoom\Enums\EnvelopeStatus::Draft)
            <button wire:click="sendEnvelope" class="btn-primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sendEnvelope">Send til underskrift</span>
                <span wire:loading wire:target="sendEnvelope">Sender...</span>
            </button>
        @endif

        @if($signingEnvelope->original_document)
            <button wire:click="downloadOriginal" class="btn-outline btn-sm">Download original</button>
        @endif

        @if($signingEnvelope->signed_document)
            <button wire:click="downloadSigned" class="btn-primary btn-sm">Download signeret PDF</button>
        @endif

        @if(in_array($signingEnvelope->status, [\Fountainhead\SigningRoom\Enums\EnvelopeStatus::Sent, \Fountainhead\SigningRoom\Enums\EnvelopeStatus::PartiallySigned, \Fountainhead\SigningRoom\Enums\EnvelopeStatus::Draft]))
            <button wire:click="confirmCancel" class="btn-danger btn-sm">Annuller</button>
        @endif
    </div>

    {{-- Parties per Round --}}
    <div class="card" style="margin-bottom: 32px;">
        <h2 style="font-size: 1.25rem; margin-bottom: 20px;">Underskrivere</h2>

        @php
            $roundGroups = $signingEnvelope->parties->groupBy('signing_round');
        @endphp

        @foreach($roundGroups as $round => $parties)
            <div style="margin-bottom: 24px;">
                <h3 style="font-size: 1rem; font-family: 'Source Sans 3', sans-serif; color: var(--ft-grey); margin-bottom: 12px;">
                    Runde {{ $round }}
                    @if($round < $signingEnvelope->current_round)
                        <span class="badge badge-green" style="font-size: 0.7rem; margin-left: 8px;">Afsluttet</span>
                    @elseif($round == $signingEnvelope->current_round)
                        <span class="badge badge-blue" style="font-size: 0.7rem; margin-left: 8px;">Aktiv</span>
                    @else
                        <span class="badge badge-gray" style="font-size: 0.7rem; margin-left: 8px;">Venter</span>
                    @endif
                </h3>

                @foreach($parties as $party)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border: 1px solid var(--ft-border); border-radius: 8px; margin-bottom: 8px; background: var(--ft-pink-light);">
                        <div>
                            <div style="font-weight: 600; color: var(--ft-black);">{{ $party->name }}</div>
                            <div style="font-size: 0.875rem; color: var(--ft-grey);">{{ $party->email }}</div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            @php
                                $partyBadge = match($party->status) {
                                    \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Signed => 'badge-green',
                                    \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Notified,
                                    \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Viewed => 'badge-blue',
                                    \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Rejected,
                                    \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Error => 'badge-red',
                                    default => 'badge-gray',
                                };
                                $partyLabel = match($party->status) {
                                    \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Pending => 'Afventer',
                                    \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Notified => 'Notificeret',
                                    \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Viewed => 'Åbnet',
                                    \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Signed => 'Signeret',
                                    \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Rejected => 'Afvist',
                                    \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Error => 'Fejl',
                                };
                            @endphp
                            <span class="badge {{ $partyBadge }}">{{ $partyLabel }}</span>

                            @if($party->status !== \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Signed && $party->status !== \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Rejected && $signingEnvelope->status !== \Fountainhead\SigningRoom\Enums\EnvelopeStatus::Cancelled)
                                <button wire:click="sendReminder({{ $party->id }})" style="background: none; border: none; color: var(--ft-blue); cursor: pointer; font-size: 0.875rem; font-weight: 600;">
                                    Send påmindelse
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- Event Log --}}
    <div class="card">
        <h2 style="font-size: 1.25rem; margin-bottom: 20px;">Hændelseslog</h2>

        @if($signingEnvelope->events->isEmpty())
            <p style="color: var(--ft-grey);">Ingen hændelser endnu.</p>
        @else
            <div style="position: relative; padding-left: 24px;">
                {{-- Timeline line --}}
                <div style="position: absolute; left: 7px; top: 8px; bottom: 8px; width: 2px; background: var(--ft-border);"></div>

                @foreach($signingEnvelope->events as $event)
                    <div style="position: relative; padding-bottom: 20px;">
                        {{-- Dot --}}
                        @php
                            $eventValue = $event->event_type instanceof \Fountainhead\SigningRoom\Enums\SigningEventType
                                ? $event->event_type->value
                                : (string) $event->event_type;
                            $dotColor = match(true) {
                                str_contains($eventValue, 'signed'), str_contains($eventValue, 'completed') => 'var(--ft-green)',
                                str_contains($eventValue, 'rejected'), str_contains($eventValue, 'cancelled'), str_contains($eventValue, 'error'), str_contains($eventValue, 'expired') => 'var(--ft-red)',
                                str_contains($eventValue, 'sent'), str_contains($eventValue, 'notified'), str_contains($eventValue, 'viewed') => 'var(--ft-blue)',
                                default => 'var(--ft-grey)',
                            };
                            $eventLabel = match($eventValue) {
                                'envelope.created' => 'Dokument oprettet',
                                'envelope.sent' => 'Sendt til underskrift',
                                'envelope.completed' => 'Alle underskrifter modtaget',
                                'envelope.expired' => 'Udløbet',
                                'envelope.cancelled' => 'Annulleret',
                                'party.notified' => 'Notifikation sendt',
                                'party.reminded' => 'Påmindelse sendt',
                                'party.viewed' => 'Dokument åbnet',
                                'party.signed' => 'Underskrevet',
                                'party.rejected' => 'Afvist',
                                'party.error' => 'Fejl',
                                'round.advanced' => 'Runde avanceret',
                                default => $eventValue,
                            };
                        @endphp
                        <div style="position: absolute; left: -20px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: {{ $dotColor }};"></div>
                        <div>
                            <div style="font-weight: 600; color: var(--ft-black); font-size: 0.9rem;">
                                {{ $eventLabel }}
                                @if($event->party)
                                    <span style="font-weight: 400; color: var(--ft-grey);"> — {{ $event->party->name }}</span>
                                @endif
                            </div>
                            <div style="font-size: 0.8rem; color: var(--ft-grey);">
                                {{ $event->created_at->format('j. M Y H:i') }}
                                @if($event->ip_address)
                                    &middot; {{ $event->ip_address }}
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Cancel Modal --}}
    @if($showCancelModal)
        <div style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; display: flex; align-items: center; justify-content: center;">
            <div class="card" style="width: 100%; max-width: 480px;">
                <h2 style="font-size: 1.25rem; margin-bottom: 16px;">Annuller dokument</h2>
                <p style="color: var(--ft-grey); margin-bottom: 16px;">Er du sikker på at du vil annullere dette dokument? Alle underskrivere vil blive informeret.</p>
                <div class="form-group">
                    <label class="form-label">Årsag (valgfri)</label>
                    <textarea wire:model="cancellationReason" class="form-input" rows="3" placeholder="Angiv en årsag..."></textarea>
                </div>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button wire:click="$set('showCancelModal', false)" class="btn-outline btn-sm">Fortryd</button>
                    <button wire:click="cancelEnvelope" class="btn-danger btn-sm">Annuller dokument</button>
                </div>
            </div>
        </div>
    @endif
</div>
