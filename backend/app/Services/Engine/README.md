# Emplacement du moteur

Le moteur de la Bibliothèque de Babel est fourni par l'enseignant (cours du 17/11).
Il sera rangé ici, dans `app/Services/Engine/`, et utilisé comme un service Laravel
(injection de dépendances). Sa clé d'API se lit uniquement via `config('babel.engine_key')`.

À faire quand le moteur est publié : copier le code fourni, puis l'enregistrer dans
`AppServiceProvider::register()`. Rien n'est écrit ici en attendant.
