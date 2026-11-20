<?php

namespace App\Http\Requests\Api;

use App\Exceptions\ErreurApi;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /app/moi (M9) : pseudo, email, mot_de_passe (le nouveau), mot_de_passe_actuel.
 *
 * - seuls les champs envoyés changent (« sometimes ») ; au moins un est exigé (minProperties: 1) ;
 * - changer l'email ou le mot de passe exige mot_de_passe_actuel (« required_with ») ;
 * - sa justesse (401) et l'unicité (409) sont vérifiées dans le contrôleur.
 */
class ModificationProfilRequest extends FormRequest
{
    private const CHAMPS = ['pseudo', 'email', 'mot_de_passe'];

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
            'pseudo' => ['sometimes', 'required', 'string', 'min:1', 'max:30'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255'],
            'mot_de_passe' => ['sometimes', 'required', 'string', 'min:8'],
            'mot_de_passe_actuel' => ['required_with:email,mot_de_passe', 'nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validateur): void {
                if ($validateur->errors()->isEmpty() && ! $this->hasAny(self::CHAMPS)) {
                    throw ErreurApi::une(422, 'ERR_INVALID_PARAMETER', 'Envoyez au moins un champ à modifier : pseudo, email ou mot_de_passe.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pseudo.required' => 'Le pseudo ne peut pas être vide.',
            'pseudo.max' => 'Le pseudo ne doit pas dépasser 30 caractères.',
            'pseudo.string' => 'Le pseudo doit être un texte.',
            'email.required' => "L'email ne peut pas être vide.",
            'email.email' => "L'email n'est pas valide.",
            'email.string' => "L'email n'est pas valide.",
            'email.max' => "L'email ne doit pas dépasser 255 caractères.",
            'mot_de_passe.required' => 'Le mot de passe ne peut pas être vide.',
            'mot_de_passe.string' => 'Le mot de passe doit être un texte.',
            'mot_de_passe.min' => 'Le mot de passe doit faire au moins 8 caractères.',
            'mot_de_passe_actuel.required_with' => 'Le mot de passe actuel est obligatoire pour changer l\'email ou le mot de passe.',
            'mot_de_passe_actuel.string' => 'Le mot de passe actuel doit être un texte.',
        ];
    }
}
