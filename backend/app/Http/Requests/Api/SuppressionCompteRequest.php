<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** DELETE /app/moi (M10) : le mot de passe, dans le corps, par sécurité (un jeton volé ne suffit pas à tout effacer). */
class SuppressionCompteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'mot_de_passe' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mot_de_passe.required' => 'Le mot de passe est obligatoire pour supprimer le compte.',
            'mot_de_passe.string' => 'Le mot de passe est obligatoire pour supprimer le compte.',
        ];
    }
}
