<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /recherches : « texte » et « mode ».
 *
 * Pas de règles de validation Laravel ici, volontairement. Le contrat veut UNE seule erreur, avec ses
 * propres codes (ERR_UNKNOWN_MODE, ERR_EMPTY_QUERY, ERR_TEXT_TOO_LONG…), dans l'ordre mode, texte vide,
 * longueur : c'est exactement ce que fait le moteur quand on lui passe le texte normalisé. Une règle
 * « required » de Laravel répondrait ERR_INVALID_PARAMETER, qui n'est pas le bon code.
 * Ce qui n'est pas une chaîne (absent, null, nombre, tableau) est traité comme vide.
 */
class RechercheRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, never> */
    public function rules(): array
    {
        return [];
    }

    public function texte(): string
    {
        $texte = $this->input('texte');

        return is_string($texte) ? $texte : '';
    }

    public function mode(): string
    {
        $mode = $this->input('mode');

        return is_string($mode) ? $mode : '';
    }
}
