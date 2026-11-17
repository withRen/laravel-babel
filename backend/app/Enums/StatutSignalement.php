<?php

namespace App\Enums;

enum StatutSignalement: string
{
    case EnAttente = 'en_attente';
    case Traite = 'traite';
    case Rejete = 'rejete';
}
