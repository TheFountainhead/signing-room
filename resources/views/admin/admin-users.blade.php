<div>
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px;">
        <h1>Administratorer</h1>
        <button wire:click="$set('showCreateModal', true)" class="btn-primary">+ Ny administrator</button>
    </div>

    @if(session('success'))
        <div style="background: #E8F5E9; color: var(--ft-green); padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 600;">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div style="background: #FFEBEE; color: var(--ft-red); padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 600;">
            {{ session('error') }}
        </div>
    @endif

    <div class="card" style="padding: 0; overflow: hidden;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Navn</th>
                    <th>E-mail</th>
                    <th>CPR</th>
                    <th>Oprettet</th>
                    <th style="width: 80px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                    <tr style="cursor: default;">
                        <td style="font-weight: 600;">{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>
                            @if($user->cpr)
                                <span style="font-family: monospace; font-size: 0.85rem;">{{ substr($user->cpr, 0, 6) }}-{{ substr($user->cpr, 6) }}</span>
                            @else
                                <span style="color: var(--ft-grey);">—</span>
                            @endif
                        </td>
                        <td style="color: var(--ft-grey); font-size: 0.9rem;">{{ $user->created_at->format('j. M Y') }}</td>
                        <td>
                            @if($user->id !== auth()->id())
                                <button wire:click="deleteUser({{ $user->id }})"
                                        wire:confirm="Er du sikker på at du vil slette {{ $user->name }}?"
                                        style="background: none; border: none; color: var(--ft-red); cursor: pointer; font-size: 0.85rem; font-weight: 600;">
                                    Slet
                                </button>
                            @else
                                <span style="color: var(--ft-grey); font-size: 0.8rem;">Dig</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Create Modal --}}
    @if($showCreateModal)
        <div style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; display: flex; align-items: center; justify-content: center;">
            <div class="card" style="width: 100%; max-width: 520px;">
                <h2 style="font-size: 1.25rem; margin-bottom: 20px;">Ny administrator</h2>

                <form wire:submit="createUser">
                    <div class="form-group">
                        <label class="form-label">Fulde navn *</label>
                        <input wire:model="name" type="text" class="form-input" placeholder="Frederik Nielsen">
                        @error('name') <div class="form-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">E-mail *</label>
                        <input wire:model="email" type="email" class="form-input" placeholder="email@eksempel.dk">
                        @error('email') <div class="form-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">CPR-nummer</label>
                        <input wire:model="cpr" type="text" class="form-input" placeholder="1234567890" maxlength="10"
                               style="font-family: monospace; letter-spacing: 1px;">
                        @error('cpr') <div class="form-error">{{ $message }}</div> @enderror
                        <div style="font-size: 0.8rem; color: var(--ft-grey); margin-top: 4px;">10 cifre uden bindestreg</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Adgangskode *</label>
                        <input wire:model="password" type="password" class="form-input" placeholder="Mindst 8 tegn">
                        @error('password') <div class="form-error">{{ $message }}</div> @enderror
                    </div>

                    <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                        <button type="button" wire:click="$set('showCreateModal', false)" class="btn-outline btn-sm">Annuller</button>
                        <button type="submit" class="btn-primary btn-sm">Opret administrator</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
