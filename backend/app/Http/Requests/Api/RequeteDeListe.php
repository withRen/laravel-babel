<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Les paramètres de découpage des listes du contrat : « lot » (à partir de 1) et « taille » (1 à 50,
 * 20 par défaut). Absents, ils prennent leur valeur par défaut ; mal écrits ou hors limites, ils sont
 * refusés (ERR_INVALID_PARAMETER) : « 1.5 », « abc », « 0 » et « 51 » ne sont pas corrigés.
 */
abstract class RequeteDeListe extends FormRequest
{
    public const TAILLE_PAR_DEFAUT = 20;

    public function authorize(): bool
    {
        return true; // porte ouverte en lecture : aucune autorisation à vérifier
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // « filled » : « ?taille= » (vide) est mal écrit, pas absent.
            'taille' => ['filled', 'integer', 'between:1,50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'taille.filled' => 'La taille doit être comprise entre 1 et 50.',
            'taille.integer' => 'La taille doit être comprise entre 1 et 50.',
            'taille.between' => 'La taille doit être comprise entre 1 et 50.',
            'lot.filled' => 'Le lot doit être un nombre entier à partir de 1.',
            'lot.integer' => 'Le lot doit être un nombre entier à partir de 1.',
            'lot.min' => 'Le lot doit être un nombre entier à partir de 1.',
        ];
    }

    public function taille(): int
    {
        return (int) ($this->validated('taille') ?? self::TAILLE_PAR_DEFAUT);
    }
}
