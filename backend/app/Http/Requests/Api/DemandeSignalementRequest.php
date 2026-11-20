<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /app/signalements : « cible » ({type, id}) et « motif » (1 à 500 caractères). Ouvert aux visiteurs. */
class DemandeSignalementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cible' => ['required', 'array'],
            'cible.type' => ['required', Rule::in(['annotation', 'collection', 'lien_court'])],
            'cible.id' => ['required', function (string $attribut, mixed $valeur, \Closure $echec): void {
                if (! is_string($valeur) && ! is_int($valeur)) {
                    $echec("L'identifiant du contenu est mal écrit.");
                }
            }],
            'motif' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cible.required' => 'Le contenu à signaler est obligatoire.',
            'cible.array' => 'Le contenu à signaler est mal écrit.',
            'cible.type.required' => 'Le type du contenu est obligatoire.',
            'cible.type.in' => 'On ne signale qu\'une annotation, une collection ou un lien court.',
            'cible.id.required' => "L'identifiant du contenu est obligatoire.",
            'motif.required' => 'Le motif est obligatoire.',
            'motif.string' => 'Le motif doit être une chaîne.',
            'motif.min' => 'Le motif est obligatoire.',
            'motif.max' => 'Le motif fait 500 caractères au plus.',
        ];
    }

    public function type(): string
    {
        return (string) $this->validated('cible.type');
    }

    public function idCible(): string
    {
        return (string) $this->validated('cible.id');
    }

    public function motif(): string
    {
        return (string) $this->validated('motif');
    }
}
