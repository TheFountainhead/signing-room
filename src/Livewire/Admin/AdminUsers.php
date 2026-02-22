<?php

namespace Fountainhead\SigningRoom\Livewire\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class AdminUsers extends Component
{
    public bool $showCreateModal = false;

    public string $name = '';

    public string $email = '';

    public string $cpr = '';

    public string $password = '';

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'cpr' => 'nullable|string|size:10',
            'password' => 'required|string|min:8',
        ];
    }

    public function createUser(): void
    {
        $this->validate();

        User::create([
            'name' => $this->name,
            'email' => $this->email,
            'cpr' => $this->cpr ?: null,
            'password' => Hash::make($this->password),
        ]);

        $this->reset(['name', 'email', 'cpr', 'password', 'showCreateModal']);

        session()->flash('success', 'Administrator oprettet.');
    }

    public function deleteUser(int $id): void
    {
        if ($id === auth()->id()) {
            session()->flash('error', 'Du kan ikke slette dig selv.');

            return;
        }

        User::findOrFail($id)->delete();

        session()->flash('success', 'Administrator slettet.');
    }

    public function render()
    {
        return view('signing-room::admin.admin-users', [
            'users' => User::orderBy('name')->get(),
        ])->layout('signing-room::layouts.admin', ['title' => 'Administratorer']);
    }
}
