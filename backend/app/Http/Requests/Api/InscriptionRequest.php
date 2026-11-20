<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /app/auth/inscription (V19) : pseudo, email, mot_de_passe.
 *
 * L'unicité (email, pseudo) n'est PAS une règle de validation : un doublon est un 409
 * ERR_ALREADY_EXISTS dans le contrat, pas un 422. Elle est vérifiée dans le contrôleur.
 */
class InscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** L'email est un identifiant : on l'écrit en minuscules pour que « Ada@x.fr » et « ada@x.fr » soient le même compte. */
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
            'pseudo' => ['required', 'string', 'min:1', 'max:30'],
            'email' => ['required', 'string', 'email', 'max:255'],
            // Le mot de passe n'est ni « trimé » (bootstrap/app.php) ni limité en composition : 8 caractères au moins, c'est le contrat.
            'mot_de_passe' => ['required', 'string', 'min:8'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pseudo.required' => 'Le pseudo est obligatoire.',
            'pseudo.max' => 'Le pseudo ne doit pas dépasser 30 caractères.',
            'pseudo.string' => 'Le pseudo doit être un texte.',
            'email.required' => "L'email est obligatoire.",
            'email.email' => "L'email n'est pas valide.",
            'email.string' => "L'email n'est pas valide.",
            'email.max' => "L'email ne doit pas dépasser 255 caractères.",
            'mot_de_passe.required' => 'Le mot de passe est obligatoire.',
            'mot_de_passe.string' => 'Le mot de passe doit être un texte.',
            'mot_de_passe.min' => 'Le mot de passe doit faire au moins 8 caractères.',
        ];
    }
}
