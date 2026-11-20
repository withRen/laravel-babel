<?php

namespace App\Http\Requests\Api;

/**
 * GET /app/moi/marque-pages : lot, taille et deux filtres facultatifs, « bibliotheque » et « livre ».
 * Le contenu de « livre » (une adresse de livre) est jugé par le contrôleur, avec le moteur.
 */
class ListeMarquePagesRequest extends ListePagineeRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'bibliotheque' => ['filled', 'string', 'regex:'.RequeteMembre::IDENTIFIANT_BIBLIOTHEQUE],
            'livre' => ['filled', 'string', 'max:3014'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'bibliotheque.filled' => "L'identifiant de la bibliothèque n'est pas valide.",
            'bibliotheque.string' => "L'identifiant de la bibliothèque n'est pas valide.",
            'bibliotheque.regex' => "L'identifiant de la bibliothèque n'est pas valide.",
            'livre.filled' => "L'adresse du livre ne peut pas être vide.",
            'livre.string' => "L'adresse du livre doit être un texte.",
            'livre.max' => "L'adresse du livre est trop longue.",
        ];
    }
}
