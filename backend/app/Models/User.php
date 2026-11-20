<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\HasApiTokens;

/**
 * UTILISATEUR du MLD. La table s'appelle `users` (Sanctum, Filament) ; `password`
 * est la colonne `mot_de_passe_hache` du MLD.
 */
#[Fillable(['pseudo', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'suspendu_le' => 'datetime',
            'derniere_connexion_le' => 'datetime',
            'cree_le' => 'datetime',
        ];
    }

    public function getFilamentName(): string
    {
        return $this->pseudo;
    }

    /**
     * Le back-office n'est ouvert qu'à ceux que la Gate « administrer » laisse passer
     * (AppServiceProvider) : la règle « qui est admin » n'est écrite qu'à un seul endroit.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return Gate::forUser($this)->allows('administrer');
    }

    /** Le rôle seul. Pour savoir si l'on PEUT administrer (compte actif), passer par la Gate. */
    public function estAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function estSuspendu(): bool
    {
        return $this->suspendu_le !== null;
    }

    /** @return HasMany<MarquePage, $this> */
    public function marquePages(): HasMany
    {
        return $this->hasMany(MarquePage::class, 'utilisateur_id');
    }

    /** @return HasMany<Collection, $this> */
    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class, 'utilisateur_id');
    }

    /** @return HasMany<Annotation, $this> */
    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class, 'utilisateur_id');
    }

    /** @return BelongsToMany<Adresse, $this, Vote> */
    public function votes(): BelongsToMany
    {
        return $this->belongsToMany(Adresse::class, 'votes', 'utilisateur_id', 'adresse_id')
            ->using(Vote::class)
            ->withPivot('valeur', 'cree_le');
    }

    /** @return HasMany<LienCourt, $this> */
    public function liensCourts(): HasMany
    {
        return $this->hasMany(LienCourt::class, 'utilisateur_id');
    }

    /** @return HasMany<Recherche, $this> */
    public function recherches(): HasMany
    {
        return $this->hasMany(Recherche::class, 'utilisateur_id');
    }

    /** @return HasMany<Signalement, $this> */
    public function signalementsEmis(): HasMany
    {
        return $this->hasMany(Signalement::class, 'auteur_id');
    }
}
