<?php

namespace Fountainhead\SigningRoom\Enums;

enum SigningPartyRole: string
{
    case Signer = 'signer';
    case Viewer = 'viewer';
}
