<?php

namespace App\Http\Requests\Api;

/** PUT /app/moi/collections/{id}/pages : la liste complète des pages, dans le nouvel ordre (même vide). */
class OrdrePagesRequest extends RequeteMembre
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'pages' => ['present', 'array', 'list'],
            'pages.*' => ['array'],
            'pages.*.adresse' => ['required', 'string'],
            'pages.*.bibliotheque' => ['sometimes', 'string', 'regex:'.self::IDENTIFIANT_BIBLIOTHEQUE],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pages.present' => 'La liste des pages est obligatoire.',
            'pages.array' => 'Les pages doivent former une liste.',
            'pages.list' => 'Les pages doivent former une liste.',
            'pages.*.array' => 'Chaque page doit être un objet {bibliotheque, adresse}.',
            'pages.*.adresse.required' => 'Chaque page doit avoir une adresse.',
            'pages.*.adresse.string' => "L'adresse doit être un texte.",
            'pages.*.bibliotheque.string' => "L'identifiant de la bibliothèque doit être un texte.",
            'pages.*.bibliotheque.regex' => "L'identifiant de la bibliothèque n'est pas valide.",
        ];
    }
}
