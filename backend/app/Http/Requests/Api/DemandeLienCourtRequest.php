<?php

namespace App\Http\Requests\Api;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /app/liens-courts : « cible » (une adresse de page ou de livre, ou une collection), « passage » et « expire_le ».
 *
 * Les adresses ne sont PAS rognées (bootstrap/app.php exclut « cible.adresse » de TrimStrings) : une adresse ne se corrige jamais.
 * Ici, seule la FORME de la demande est contrôlée ; que l'adresse soit bien écrite, que la page existe ou que la collection
 * soit visible se juge dans le service (avec les codes d'erreur du moteur, pas ERR_INVALID_PARAMETER).
 */
class DemandeLienCourtRequest extends FormRequest
{
    private const FORMATS_DATE = ['Y-m-d\TH:i:sp', 'Y-m-d\TH:i:s.up'];

    public function authorize(): bool
    {
        return true; // ouvert aux visiteurs
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $identifiant = function (string $attribut, mixed $valeur, \Closure $echec): void {
            if (! is_string($valeur) && ! is_int($valeur)) {
                $echec("L'identifiant de la collection est mal écrit.");
            }
        };

        return [
            'cible' => ['required', 'array'],
            'cible.type' => ['required', Rule::in(['adresse', 'collection'])],
            'cible.adresse' => ['required_if:cible.type,adresse', 'string'],
            'cible.bibliotheque' => ['nullable', 'string'],
            'cible.id' => ['required_if:cible.type,collection', $identifiant],
            'passage' => ['nullable', 'string', 'max:3200'],
            'expire_le' => ['nullable', 'string', function (string $attribut, mixed $valeur, \Closure $echec): void {
                if ($this->lireDate((string) $valeur) === null) {
                    $echec("La date d'expiration doit être une date ISO 8601 avec fuseau, par exemple 2026-12-20T14:05:00+01:00.");
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cible.required' => 'La cible du lien est obligatoire.',
            'cible.array' => 'La cible du lien est mal écrite.',
            'cible.type.required' => 'Le type de la cible est obligatoire (adresse ou collection).',
            'cible.type.in' => 'Le type de la cible doit être adresse ou collection.',
            'cible.adresse.required_if' => "L'adresse de la cible est obligatoire.",
            'cible.adresse.string' => "L'adresse de la cible doit être une chaîne.",
            'cible.bibliotheque.string' => "L'identifiant de la bibliothèque doit être une chaîne.",
            'cible.id.required_if' => "L'identifiant de la collection est obligatoire.",
            'passage.string' => 'Le passage doit être une chaîne.',
            'passage.max' => 'Le passage fait 3 200 caractères au plus.',
            'expire_le.string' => "La date d'expiration doit être une date ISO 8601 avec fuseau.",
        ];
    }

    /** @return array<string, mixed> la cible telle que le service l'attend */
    public function cible(): array
    {
        return (array) $this->validated('cible');
    }

    public function passage(): ?string
    {
        $passage = $this->validated('passage');

        return $passage === null || $passage === '' ? null : $passage;
    }

    public function expireLe(): ?CarbonImmutable
    {
        $valeur = $this->validated('expire_le');

        return $valeur === null ? null : $this->lireDate((string) $valeur);
    }

    private function lireDate(string $valeur): ?CarbonImmutable
    {
        foreach (self::FORMATS_DATE as $format) {
            // DateTimeImmutable renvoie false (Carbon, lui, lève une exception) sur une date mal écrite.
            $date = \DateTimeImmutable::createFromFormat($format, $valeur);
            $erreurs = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($erreurs === false || ($erreurs['warning_count'] === 0 && $erreurs['error_count'] === 0))) {
                return CarbonImmutable::instance($date);
            }
        }

        return null;
    }
}
