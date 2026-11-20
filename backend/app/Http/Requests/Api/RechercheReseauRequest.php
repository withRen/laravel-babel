<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /app/recherches-reseau : « identifiant » (UUID v4 tiré par l'application web), « texte » et « mode ».
 *
 * Seul l'identifiant a une règle Laravel. Pour « texte » et « mode », le contrat veut UNE seule erreur avec ses codes
 * (ERR_UNKNOWN_MODE, ERR_EMPTY_QUERY, ERR_TEXT_TOO_LONG), dans l'ordre mode, texte vide, longueur : c'est le moteur
 * qui les lève, comme pour POST /recherches. Ce qui n'est pas une chaîne est traité comme vide.
 */
class RechercheReseauRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Le motif est celui du contrat : UUID v4, minuscules. « \z » et non « $ » : pas de saut de ligne final toléré.
            'identifiant' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'identifiant.required' => "L'identifiant de la recherche est obligatoire (un UUID v4).",
            'identifiant.string' => "L'identifiant de la recherche doit être un UUID v4.",
            'identifiant.regex' => "L'identifiant de la recherche doit être un UUID v4 en minuscules.",
        ];
    }

    public function identifiant(): string
    {
        return (string) $this->validated('identifiant');
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
