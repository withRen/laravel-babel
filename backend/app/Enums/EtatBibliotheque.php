<?php

namespace App\Enums;

enum EtatBibliotheque: string
{
    case EnLigne = 'en_ligne';
    case HorsLigne = 'hors_ligne';
    case Inconnu = 'inconnu';
}
