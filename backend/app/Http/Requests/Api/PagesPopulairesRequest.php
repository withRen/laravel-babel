<?php

namespace App\Http\Requests\Api;

/** GET /pages-populaires : seulement « taille » (pas de lot : une seule liste, les mieux classées). */
class PagesPopulairesRequest extends RequeteDeListe {}
