<?php

namespace Fountainhead\SigningRoom\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * Stand-in for the host app's \App\Models\User, which SigningRoomService::notifyCreator
 * resolves by name. Testbench has no such class, so tests alias this one to it.
 */
class User extends Model
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}
