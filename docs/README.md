# Documentation

| Fichier | Contenu |
| --- | --- |
| `cahier-des-charges.md` | Le cahier des charges final du 15/11/2026 (4 livrables : stories, fonctions du moteur, modèle de données, architecture). |
| `openapi.yaml` | **Le contrat d'API commun** des 10 bibliothèques. Il fait foi : le testeur de conformité compare nos réponses à ce fichier. Ne pas le modifier à la main. |
| `schemas/mld.png` | Modèle logique des données (livrable 3). |
| `schemas/c4-conteneurs.png` | Diagramme de conteneurs C4 (livrable 4). |
| `schemas/sequence-recherche-reseau.png` | Séquence de la recherche sur le réseau (livrable 4). |

Générer les types TypeScript du front depuis le contrat :
`npx openapi-typescript docs/openapi.yaml -o frontend/src/app/api/schema.d.ts`
