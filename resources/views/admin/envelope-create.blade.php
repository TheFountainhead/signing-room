<div>
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px;">
        <h1>Nyt dokument til underskrift</h1>
    </div>

    @if(session('error'))
        <div style="background: #FFEBEE; color: var(--ft-red); padding: 16px; border-radius: 8px; margin-bottom: 24px;">
            {{ session('error') }}
        </div>
    @endif

    <form wire:submit="sendForSigning">
        {{-- Document Details --}}
        <div class="card" style="margin-bottom: 24px;">
            <h2 style="font-size: 1.25rem; margin-bottom: 20px;">Dokumentoplysninger</h2>

            <div class="form-group">
                <label class="form-label" for="title">Titel *</label>
                <input wire:model="title" type="text" id="title" class="form-input" placeholder="F.eks. Forvaltningsaftale">
                @error('title') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="description">Beskrivelse</label>
                <textarea wire:model="description" id="description" class="form-input" rows="3" placeholder="Valgfri beskrivelse af dokumentet..."></textarea>
                @error('description') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label class="form-label">PDF-dokument *</label>
                <input wire:model="document" type="file" id="document" accept=".pdf" style="display: none;">
                <label for="document"
                       style="display: flex; flex-direction: column; align-items: center; justify-content: center;
                              padding: 32px 24px; border: 2px dashed var(--ft-border); border-radius: 8px;
                              cursor: pointer; transition: all 0.15s ease; background: var(--ft-pink-light);"
                       onmouseover="this.style.borderColor='var(--ft-blue)'; this.style.background='#F0F4FF'"
                       onmouseout="this.style.borderColor='var(--ft-border)'; this.style.background='var(--ft-pink-light)'"
                       ondragover="event.preventDefault(); this.style.borderColor='var(--ft-blue)'; this.style.background='#F0F4FF'"
                       ondragleave="this.style.borderColor='var(--ft-border)'; this.style.background='var(--ft-pink-light)'"
                       ondrop="event.preventDefault(); this.style.borderColor='var(--ft-border)'; this.style.background='var(--ft-pink-light)'">
                    @if($document)
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--ft-blue)" stroke-width="1.5" style="margin-bottom: 8px;">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <path d="M9 15l2 2 4-4" stroke="var(--ft-blue)"></path>
                        </svg>
                        <span style="font-weight: 600; color: var(--ft-dark); font-size: 0.9rem;">{{ $document->getClientOriginalName() }}</span>
                        <span style="font-size: 0.8rem; color: var(--ft-grey); margin-top: 2px;">{{ number_format($document->getSize() / 1024, 0) }} KB — klik for at vælge en anden</span>
                    @else
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--ft-grey)" stroke-width="1.5" style="margin-bottom: 8px;">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="17 8 12 3 7 8"></polyline>
                            <line x1="12" y1="3" x2="12" y2="15"></line>
                        </svg>
                        <span style="font-weight: 600; color: var(--ft-dark); font-size: 0.9rem;">Klik for at vælge PDF</span>
                        <span style="font-size: 0.8rem; color: var(--ft-grey); margin-top: 2px;">eller træk og slip her — maks 10 MB</span>
                    @endif
                </label>
                <div wire:loading wire:target="document" style="margin-top: 8px;">
                    <div style="height: 4px; background: var(--ft-border); border-radius: 2px; overflow: hidden;">
                        <div style="height: 100%; width: 100%; background: var(--ft-blue); border-radius: 2px; animation: progress 1.5s ease-in-out infinite;"></div>
                    </div>
                    <span style="font-size: 0.8rem; color: var(--ft-grey);">Uploader...</span>
                </div>
                @error('document') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <style>
                @keyframes progress { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
            </style>

            <div style="display: flex; gap: 16px;">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label" for="expiresInDays">Udløber om (dage)</label>
                    <input wire:model="expiresInDays" type="number" id="expiresInDays" class="form-input" min="1" max="365">
                    @error('expiresInDays') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label" for="reminderInterval">Påmindelsesinterval (dage)</label>
                    <input wire:model="reminderInterval" type="number" id="reminderInterval" class="form-input" min="1" max="30">
                    @error('reminderInterval') <div class="form-error">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        {{-- Parties --}}
        <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                <h2 style="font-size: 1.25rem;">Underskrivere</h2>
                <button type="button" wire:click="addParty" class="btn-outline btn-sm">
                    + Tilføj underskriver
                </button>
            </div>

            {{-- Creator signs toggle --}}
            <label style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; border: 1px solid var(--ft-border); border-radius: 8px; margin-bottom: 16px; cursor: pointer; background: {{ $creatorSigns ? '#EBF5FF' : 'white' }}; transition: background 0.15s ease;">
                <div style="position: relative; width: 40px; height: 22px; flex-shrink: 0;">
                    <input type="checkbox" wire:model.live="creatorSigns" style="position: absolute; opacity: 0; width: 100%; height: 100%; cursor: pointer; margin: 0;">
                    <div style="width: 40px; height: 22px; border-radius: 11px; background: {{ $creatorSigns ? 'var(--ft-blue)' : '#D1D5DB' }}; transition: background 0.2s ease;"></div>
                    <div style="position: absolute; top: 2px; left: {{ $creatorSigns ? '20px' : '2px' }}; width: 18px; height: 18px; border-radius: 50%; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: left 0.2s ease;"></div>
                </div>
                <div>
                    <span style="font-weight: 600; font-size: 0.9rem; color: var(--ft-dark);">Jeg skal også underskrive</span>
                    <span style="font-size: 0.8rem; color: var(--ft-grey); display: block;">{{ auth()->user()->name }} ({{ auth()->user()->email }})</span>
                </div>
            </label>

            @foreach($parties as $index => $party)
                <div style="padding: 16px; border: 1px solid var(--ft-border); border-radius: 8px; margin-bottom: 12px; background: var(--ft-pink-light);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                        <span style="font-weight: 600; color: var(--ft-dark); font-size: 0.9rem;">
                            Underskriver {{ $index + 1 }}
                        </span>
                        @if(count($parties) > 1)
                            <button type="button" wire:click="removeParty({{ $index }})"
                                    style="background: none; border: none; color: var(--ft-red); cursor: pointer; font-size: 0.875rem; font-weight: 600;">
                                Fjern
                            </button>
                        @endif
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label class="form-label" style="font-size: 0.85rem;">Navn *</label>
                            <input wire:model="parties.{{ $index }}.name" type="text" class="form-input" placeholder="Fulde navn">
                            @error("parties.{$index}.name") <div class="form-error">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.85rem;">E-mail *</label>
                            <input wire:model="parties.{{ $index }}.email" type="email" class="form-input" placeholder="email@eksempel.dk">
                            @error("parties.{$index}.email") <div class="form-error">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.85rem;">Rolle</label>
                            <select wire:model="parties.{{ $index }}.role" class="form-input">
                                <option value="signer">Underskriver</option>
                                <option value="viewer">Modtager (kopi)</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.85rem;">Underskriftsrunde</label>
                            <input wire:model="parties.{{ $index }}.signing_round" type="number" class="form-input" min="1">
                            <div style="font-size: 0.75rem; color: var(--ft-grey); margin-top: 2px;">Runde 1 underskriver først</div>
                        </div>
                    </div>
                </div>
            @endforeach
            @error('parties') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        {{-- Actions --}}
        <div style="display: flex; gap: 12px; justify-content: flex-end;">
            <a href="{{ route('signing-room.admin.index') }}" class="btn-outline">Annuller</a>
            <button type="button" wire:click="saveDraft" class="btn-outline">
                Gem som kladde
            </button>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sendForSigning">Send til underskrift</span>
                <span wire:loading wire:target="sendForSigning">Sender...</span>
            </button>
        </div>
    </form>
</div>
