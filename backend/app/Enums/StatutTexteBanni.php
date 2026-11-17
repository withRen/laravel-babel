<?php

namespace App\Enums;

enum StatutTexteBanni: string
{
    case Propose = 'propose';
    case Applique = 'applique';
    case Rejete = 'rejete';
}
