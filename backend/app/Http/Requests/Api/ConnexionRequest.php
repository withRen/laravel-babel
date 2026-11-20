<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /app/auth/connexion (M1) : email, mot_de_passe.
 *
 * On ne vérifie volontairement que la présence : valider le format de l'email ou la longueur du mot de passe
 * ici ne ferait que renseigner un pirate. Tout le reste donne la même réponse vague (401).
 */
class ConnexionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower($this->input('email'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string'],
            'mot_de_passe' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => "L'email est obligatoire.",
            'email.string' => "L'email est obligatoire.",
            'mot_de_passe.required' => 'Le mot de passe est obligatoire.',
            'mot_de_passe.string' => 'Le mot de passe est obligatoire.',
        ];
    }
}
