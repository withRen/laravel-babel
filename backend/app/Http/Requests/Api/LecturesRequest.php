<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /lectures : « adresses », une liste de 1 à 50 chaînes.
 *
 * Seule la FORME de la liste est contrôlée ici. Le contenu de chaque adresse ne l'est pas : une
 * adresse refusée n'empêche pas les autres (statut 200, erreurs à sa place), c'est le contrat.
 * Les adresses ne sont ni rognées ni changées : le middleware TrimStrings les exclut
 * (bootstrap/app.php) et « "" » n'est pas converti en null pour cette route.
 */
class LecturesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'adresses' => ['required', 'array', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'adresses.*' => 'Le champ adresses doit être une liste de 1 à 50 adresses.',
        ];
    }

    /** Chaque élément doit être une chaîne, et la liste une vraie liste (pas un objet JSON). */
    public function after(): array
    {
        return [
            function (Validator $validateur): void {
                $adresses = $this->input('adresses');
                if (! is_array($adresses) || $validateur->errors()->has('adresses')) {
                    return;
                }
                if (! array_is_list($adresses) || count(array_filter($adresses, 'is_string')) !== count($adresses)) {
                    $validateur->errors()->add('adresses', 'Chaque élément de adresses doit être une chaîne.');
                }
            },
        ];
    }

    /** @return list<string> */
    public function adresses(): array
    {
        return $this->validated('adresses');
    }
}
