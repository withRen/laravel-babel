# La Bibliothèque de Babel en réseau : cahier des charges final

Version du 15 novembre 2026, Damien Marill, IUT de Béziers, département MMI (BUT MMI 3, projet fil rouge).

---

## Vue d'ensemble

Version finale des quatre livrables de l'atelier, après la mise en commun. Chaque partie est un livrable. Ils se lisent dans l'ordre : le modèle de données et l'architecture découlent des stories et des fonctions.

| Livrable | Contenu | D'où il vient |
|---|---|---|
| 1. User stories | 51 stories pour 4 rôles : visiteur, membre, administrateur, autre bibliothèque | discuté et tranché en séance |
| 2. Fonctions du moteur | 8 fonctions et 10 codes d'erreur | discuté et tranché en séance |
| 3. Modèle logique des données | 14 tables et 22 règles | déduit des livrables 1 et 2 |
| 4. Architecture | diagramme de conteneurs C4, séquence de la recherche réseau, modération en réseau et migration de compte pas à pas | déduit des livrables 1 et 2 |

### Les décisions qui tiennent tout le reste

1. On ne masque jamais une page. On modère ce qui est publié autour : annotations, collections, liens courts.
2. Seuls les membres annotent, et chacun choisit si son annotation est publique ou privée.
3. Le réseau est ouvert en lecture : une autre bibliothèque lit ce qu'un visiteur peut lire. Seule la liste des textes bannis est réservée aux bibliothèques du réseau, par un jeton commun.
4. Un compte est local à une bibliothèque. On en change par un export, puis un import, qui retrouve chaque page dans la nouvelle bibliothèque.
5. Pour modérer en réseau, on s'échange le texte banni lui-même, et chaque bibliothèque décide chez elle.
6. Aucune fonction ne reçoit la clé du moteur. Seule son empreinte est publiée, pour qu'un changement de clé se voie.
7. La recherche renvoie une adresse, toujours la même pour un même texte et un même mode. La position du texte se calcule dans l'application web.
8. Une adresse mal écrite est refusée, jamais corrigée.
9. La page au hasard est un vrai tirage, sans graine.
10. Tout le monde peut créer un lien court, visiteurs compris.

### Ce qui reste à venir

Le contrat d'API commun fixera les routes de l'API publique, la forme des réponses d'erreur et le format du fichier d'export. Ce cahier des charges en est la base.

---

## Livrable 1 : les user stories

51 stories sur quatre rôles : 21 pour le visiteur, 13 pour le membre, 11 pour l'administrateur, 6 pour une autre bibliothèque. Elles reprennent ce que les trois groupes ont déposé, tranché en mise en commun.

La colonne « Origine » dit d'où vient chaque story : un groupe, plusieurs, ou une décision prise en séance. Les priorités n'ont pas été discutées en séance, elles sont proposées : **Indispensable** = sans elle, pas de bibliothèque en réseau ; **Important** = attendu à l'évaluation ; **Bonus** = valorisable. Les numéros servent de repères dans les trois autres livrables.

### Ce que la mise en commun a tranché

| Question | Décision |
|---|---|
| Ce qu'on modère | On ne masque et on ne supprime jamais une page. On modère ce qui est publié autour d'un contenu bloquant : annotations, collections, liens courts. |
| Qui annote | Les membres seulement. Chaque annotation est publique ou privée, au choix de son auteur. Une annotation publique se signale et se masque comme le reste. |
| Le réseau dans les stories | Le rôle « Autre bibliothèque » existe. Ses stories viennent de Babel 2 et Babel 3. |
| Accès entre bibliothèques | Ouvert en lecture. Une autre bibliothèque lit ce qu'un visiteur peut lire : ni jeton, ni liste de bibliothèques autorisées. Une seule exception : la liste des textes bannis, réservée aux bibliothèques du réseau par un jeton commun. |
| Comptes | Un compte est local à une bibliothèque. On change de bibliothèque en migrant son compte : export, puis import. À l'import, chaque page est retrouvée dans la nouvelle bibliothèque. |
| Modération en réseau | Une bibliothèque publie le texte banni lui-même, pas une adresse ni une empreinte. Chaque bibliothèque le cherche chez elle, puis décide. |
| Liens courts | Tout le monde peut en créer, visiteurs compris. |
| Vocabulaire | On dit « collection » pour un regroupement de pages, et « annotation » pour ce qu'un membre écrit sur une page. Un « pair » est une autre bibliothèque du réseau. |

### Visiteur

Quelqu'un qui arrive sans compte. Il peut déjà tout lire et tout chercher : la bibliothèque entière est publique par nature.

| N° | En tant que visiteur, je veux | afin de | Origine | Priorité |
|---|---|---|---|---|
| V1 | ouvrir une page à partir de son adresse | lire ce qu'elle contient | 3 groupes | Indispensable |
| V2 | passer à la page précédente ou suivante, y compris d'un volume ou d'un hexagone à l'autre | lire un livre en entier | 3 groupes | Indispensable |
| V3 | changer de mur, d'étagère ou de volume | explorer un hexagone comme une salle | Babel 1 et 2 | Important |
| V4 | chercher un texte | trouver la page qui le contient | 3 groupes | Indispensable |
| V5 | choisir le mode de recherche : texte seul, ou texte entouré de caractères aléatoires | voir les deux façons dont il existe dans la bibliothèque | Babel 1 | Important |
| V6 | voir le texte réellement cherché une fois converti (majuscules, accents, apostrophes) | comprendre pourquoi ma phrase a changé | Babel 1 | Indispensable |
| V7 | voir mon texte surligné dans la page trouvée | le repérer parmi les 3 200 caractères | Babel 2 | Indispensable |
| V8 | recevoir un message clair quand une adresse est refusée | corriger mon erreur au lieu de voir une page cassée | découle du livrable 2 | Indispensable |
| V9 | ouvrir une page au hasard | explorer sans but précis | 3 groupes | Important |
| V10 | copier l'adresse complète ou le lien de la page que je lis | la partager sans retaper les symboles | Babel 1 et 2 | Indispensable |
| V11 | créer un lien court vers une page, un livre entier ou une collection, avec au besoin un passage à surligner | la partager à l'oral, par message ou en QR code | Babel 2 et 3, décision | Indispensable |
| V12 | ouvrir un lien court qu'on m'a envoyé | arriver directement sur la page, sur la collection ou sur la page 1 du livre | Babel 2 | Indispensable |
| V13 | consulter les collections publiques et les pages les plus populaires | découvrir ce que les autres ont trouvé | Babel 3 | Important |
| V14 | lire les annotations publiques laissées sur une page | profiter de ce que les membres y ont vu | décision | Important |
| V15 | chercher un texte sur toutes les bibliothèques du réseau | comparer les adresses où il se cache dans chacune | Babel 2 | Indispensable |
| V16 | ouvrir la même adresse dans une autre bibliothèque | constater que chaque bibliothèque y affiche un autre texte | Babel 2 | Bonus |
| V17 | signaler un contenu publié qui me choque : une annotation, une collection ou un lien court | qu'un administrateur le vérifie | 3 groupes, cible tranchée | Indispensable |
| V18 | ne plus voir un contenu que j'ai signalé, quelle que soit la décision de l'administrateur | ne plus retomber dessus | Babel 2, revu en séance | Important |
| V19 | créer un compte (pseudo, mot de passe, et un email qui sert d'identifiant de connexion) | garder mes trouvailles | Babel 1 et 2 | Indispensable |
| V20 | lire une page « À propos » | comprendre le concept de la bibliothèque | Babel 1 | Bonus |
| V21 | parcourir les collections publiques et les pages populaires des autres bibliothèques | découvrir ce que le reste du réseau a trouvé | découle de B3 et B4 | Bonus |

### Membre

Quelqu'un qui a un compte dans cette bibliothèque. Le compte n'ouvre pas de nouvelles pages : il permet de garder, d'organiser, d'annoter et de partager des adresses.

| N° | En tant que membre, je veux | afin de | Origine | Priorité |
|---|---|---|---|---|
| M1 | me connecter avec mon email et mon mot de passe, et me déconnecter | accéder à mes données | Babel 1 | Indispensable |
| M2 | garder une page en marque-page, avec un titre facultatif | la retrouver plus tard | 3 groupes | Indispensable |
| M3 | voir la liste de mes marque-pages, en rouvrir un ou en retirer un | m'y retrouver | Babel 1 | Indispensable |
| M4 | créer une collection, y ranger des pages dans l'ordre que je veux, la renommer, la supprimer | organiser un parcours de lecture | 3 groupes | Important |
| M5 | rendre une collection publique ou privée | choisir ce que je montre aux autres | 3 groupes | Important |
| M6 | annoter une page, modifier ou supprimer mon annotation, et choisir si elle est publique ou privée | garder une trace pour moi, ou dire aux autres pourquoi la page m'a intéressé | 3 groupes, décision | Important |
| M7 | voter pour ou contre une page | montrer son intérêt | Babel 3 | Bonus |
| M8 | revoir mes recherches passées | retrouver une adresse | Babel 1 | Bonus |
| M9 | modifier mon pseudo, mon email ou mon mot de passe | garder mon compte à jour | Babel 1 et 2 | Important |
| M10 | supprimer mon compte | que mes données soient effacées (RGPD) | Babel 1 et 2 | Important |
| M11 | exporter mes données : profil, marque-pages, collections, annotations, votes, recherches et liens courts | récupérer mes données (RGPD) | décision | Important |
| M12 | importer mon export dans une autre bibliothèque du réseau, qui y retrouve chacune de mes pages | migrer mon compte sans perdre mes trouvailles, même si l'ancienne bibliothèque ferme | décision | Important |
| M13 | garder en marque-page ou dans une collection une page d'une autre bibliothèque | rassembler au même endroit mes trouvailles du réseau | découle de M12 | Important |

Tant qu'une adresse importée n'est pas convertie, elle désigne encore une page de l'ancienne bibliothèque : M13 est ce qui permet de l'ouvrir en attendant.

### Administrateur

Celui qui gère une bibliothèque. Il modère ce que les utilisateurs publient, jamais les pages calculées. Le premier administrateur est créé à l'installation, hors interface ; les suivants sont promus (A3).

| N° | En tant qu'administrateur, je veux | afin de | Origine | Priorité |
|---|---|---|---|---|
| A1 | voir et filtrer la liste des comptes | les gérer | Babel 1 | Indispensable |
| A2 | suspendre, rétablir ou supprimer un compte | écarter les membres qui abusent du service | 3 groupes | Indispensable |
| A3 | promouvoir un membre administrateur | partager la modération | Babel 1 | Bonus |
| A4 | traiter la file des signalements : masquer ou rétablir le contenu publié, ou rejeter le signalement | modérer sans parcourir la bibliothèque à la main | 3 groupes | Indispensable |
| A5 | déclarer un texte banni, ou lever un bannissement | masquer tout ce qui est publié vers ce texte, sans supprimer la page | décision | Important |
| A6 | publier mes textes bannis aux bibliothèques du réseau, et à elles seules | que chaque bibliothèque les cherche chez elle et décide, sans que la liste soit publique | Babel 2, revu en séance | Important |
| A7 | examiner les textes bannis par les autres bibliothèques et décider de les appliquer chez moi | rester maître de ma modération | décision | Important |
| A8 | configurer ma bibliothèque : nom, description, inscriptions ouvertes ou fermées | lui donner son identité | Babel 3 | Indispensable |
| A9 | voir l'état du réseau : lien avec l'annuaire, bibliothèques qui répondent, clés qui ont changé | détecter les bibliothèques injoignables, ou dont les adresses ne désignent plus les mêmes textes | Babel 2 | Important |
| A10 | limiter le nombre de requêtes sur l'API publique | protéger mon serveur de la surcharge | Babel 2 | Important |
| A11 | voir des statistiques : comptes, membres actifs, pages lues, recherches, signalements, pages les plus partagées | suivre l'usage de la bibliothèque | 3 groupes | Bonus |

### Autre bibliothèque

Un acteur machine : une bibliothèque du réseau qui appelle la nôtre. Elle lit ce qu'un visiteur peut lire, plus la liste des textes bannis (B5), et elle n'écrit rien chez nous. Ces stories sont la première version du contrat d'API commun.

| N° | En tant qu'autre bibliothèque, je veux | afin de | Origine | Priorité |
|---|---|---|---|---|
| B1 | lire une page chez vous par son adresse | l'afficher à mes utilisateurs | Babel 2 | Indispensable |
| B2 | vous demander l'adresse d'une page qui contient un texte donné | agréger les résultats d'une recherche sur tout le réseau | Babel 2 | Indispensable |
| B3 | obtenir la liste de vos collections publiques | les proposer à mes propres utilisateurs | Babel 3 | Important |
| B4 | connaître vos pages les plus populaires | enrichir les découvertes que je propose | Babel 3 | Bonus |
| B5 | relever les textes que vous avez bannis, en présentant le jeton réseau | les chercher chez moi et décider de les modérer | Babel 2 et 3, revu en séance | Important |
| B6 | connaître votre identité : identifiant, nom, description, empreinte de clé | savoir à qui je parle, vous présenter à mes utilisateurs et voir si votre clé a changé | découle de A8 et de l'annuaire | Indispensable |

Une page populaire ou bannie chez vous n'est pas à la même adresse chez moi. B3 et B4 renvoient donc des adresses, chacune avec l'identifiant de sa bibliothèque : le plus souvent la vôtre, parfois une autre (M13). Je les lis chez la bibliothèque indiquée (B1). B5 renvoie des textes, que je cherche ensuite dans ma propre bibliothèque.

### Plateforme du réseau

L'annuaire et le testeur de conformité sont fournis. Chaque bibliothèque s'annonce à l'annuaire avec son identifiant et son URL du moment, et y récupère la liste des autres. L'annonce est authentifiée par un secret que la plateforme remet à chaque bibliothèque : il prouve son identifiant auprès de l'annuaire et ne sert jamais entre bibliothèques. La plateforme remet aussi le jeton réseau, le même pour toutes : il ouvre la lecture des textes bannis (B5) et rien d'autre. La plateforme vérifie qu'une bibliothèque est présente en lisant son identité (B6). Le testeur vérifie qu'une bibliothèque respecte le contrat commun, sans connaître sa clé.

### Stories écartées ou transformées

| Story proposée | Ce qu'elle devient | Pourquoi |
|---|---|---|
| Retrouver mes marque-pages depuis n'importe quel appareil (Babel 2) | écartée | c'est déjà ce qu'apporte le compte |
| Voir un avertissement avant d'ouvrir une page masquée (Babel 2) | écartée | on ne masque pas les pages |
| Masquer une adresse derrière un avertissement (Babel 2) | écartée | même raison : la page reste lisible, on masque ce qui y renvoie (A5) |
| Signaler une page choquante (Babel 3) | transformée en V17 et A5 | on signale ce qui est publié ; l'administrateur bannit le texte |
| Choisir les bibliothèques autorisées à communiquer avec la mienne (Babel 3) | écartée | le réseau est ouvert en lecture |
| M'identifier auprès d'une autre bibliothèque (Babel 3) | transformée en M12 | les comptes sont locaux, on migre son compte |
| Recevoir l'empreinte d'un texte signalé (Babel 2) | transformée en B5 | avec une empreinte, impossible de retrouver l'adresse du texte chez soi |
| Savoir si une page a été signalée chez vous (Babel 3) | transformée en B5 | la même adresse affiche un autre texte chez le voisin |
| Recevoir un lien de réinitialisation du mot de passe (Babel 1) | écartée | hors périmètre |
| Voir le titre d'un volume, chercher un volume par son titre (Babel 1) | écartée | le moteur ne fournit pas de titre |
| Télécharger le texte d'une page en .txt (Babel 1) | transformée en M11 | on exporte ses données, pas une page qui se recalcule |

---

## Livrable 2 : les fonctions du moteur

Huit fonctions suffisent à toutes les stories du livrable 1. Trois sont fournies par le moteur, trois sont des règles communes écrites sur le serveur, deux sont des règles communes qui s'exécutent dans l'application web.

Chaque fiche donne les entrées, la sortie, les erreurs, les garanties et les stories servies (numéros du livrable 1). Aucune ne dit comment la fonction est écrite : c'est le contrat, pas le code.

### Ce que la mise en commun a tranché

| Question | Décision |
|---|---|
| La clé | Aucune fonction ne la reçoit et aucune ne la renvoie. `empreinte` n'en donne qu'une empreinte, dont on ne peut pas la déduire. |
| Deux recherches du même texte | Elles donnent toujours la même adresse, dans tous les modes. Un mode qui change d'adresse à chaque appel ne doit pas exister. |
| Ce que la recherche renvoie | L'adresse. La position du texte dans la page est calculée par l'application web (`localiser`). |
| La page au hasard | Pas de graine : un vrai tirage, jamais reproductible. L'uniformité se vérifie par des tests sur un grand nombre de tirages. |
| Une adresse mal écrite | On la refuse, on ne la corrige pas : `W04` n'est pas `w4`. |
| Page suivante au bout d'un hexagone | On continue dans l'hexagone suivant. |
| Conversion du texte | Elle a lieu avant le moteur, avec la même règle dans toutes les bibliothèques. |
| Noms des erreurs | Ceux de Babel 2 : `ERR_…`, en anglais, en majuscules. |

### Vue d'ensemble

| N° | Fonction | Rôle | Qui la fournit |
|---|---|---|---|
| F1 | `lire(adresse)` | donne le texte d'une page | le moteur |
| F2 | `chercher(texte, mode)` | donne l'adresse d'une page qui contient un texte | le moteur |
| F3 | `normaliser(texte)` | convertit une phrase quelconque en texte cherchable | règle commune, sur le serveur |
| F4 | `localiser(texte, page)` | trouve où un texte apparaît dans une page | règle commune, dans l'application web |
| F5 | `valider(adresse)` | dit si une adresse est bien écrite et existe | règle commune, sur le serveur |
| F6 | `voisine(adresse, sens, niveau)` | calcule l'adresse d'à côté | règle commune, dans l'application web |
| F7 | `auHasard()` | tire une adresse au hasard | règle commune, sur le serveur |
| F8 | `empreinte()` | donne l'empreinte de la clé, sans la révéler | le moteur |

**Rappel du vocabulaire.** Une **adresse** s'écrit `<hexagone>-w<mur>-s<étagère>-v<volume>:<page>`. L'hexagone est un nombre en base 36 (chiffres et minuscules) d'au plus 3 004 caractères, le mur va de 1 à 4, l'étagère de 1 à 5, le volume de 1 à 32, la page de 1 à 410. L'adresse d'un livre entier est la même sans `:<page>`. Elle désigne un livre dans un lien court (V11) : on ne la lit pas, on ouvre sa page 1. Une **page** contient exactement 3 200 caractères pris parmi **29 symboles** : les lettres de a à z, l'espace, la virgule et le point.

### La clé

La clé de la bibliothèque n'apparaît dans aucune signature. Le moteur la lit dans la configuration du serveur. Elle ne va ni en base, ni dans une réponse, ni dans un journal, et elle ne sort jamais du serveur. Elle ne doit pas changer après l'ouverture de la bibliothèque : la changer donnerait un autre texte à toutes les adresses déjà partagées. Si elle change malgré tout, cela doit se voir : le moteur fournit `empreinte` (F8).

### F1 : lire(adresse)

| Rubrique | Contenu |
|---|---|
| Entrée | une adresse de page ; une adresse de livre est refusée (`ERR_INVALID_ADDRESS_SYNTAX`, champ page) |
| Sortie | le texte de la page : exactement 3 200 caractères pris dans les 29 symboles |
| Erreurs | celles de `valider` (F5) : `ERR_INVALID_ADDRESS_SYNTAX`, `ERR_INVALID_HEX_CHARS`, `ERR_OUT_OF_BOUNDS` |
| Garanties | la même adresse donne le même texte aujourd'hui, demain, dans un an ; deux adresses différentes donnent deux textes différents ; la page est calculée à chaque appel, en quelques millisecondes |
| Stories | V1, V2, V3, V9, V12, V16, M3, B1 |

### F2 : chercher(texte, mode)

| Rubrique | Contenu |
|---|---|
| Entrées | un texte écrit avec les 29 symboles, de 1 à 3 200 caractères : déjà normalisé (F3) en mode `exact` ou `bruit`, texte brut d'une page en mode `page` ; un mode |
| Modes | `exact` : le texte seul, le reste de la page en espaces. `bruit` : le texte entouré de caractères pseudo-aléatoires. `page` : le texte fait exactement 3 200 caractères, on obtient l'adresse de cette page précise |
| Sortie | l'adresse d'une page qui contient le texte |
| Erreurs | `ERR_EMPTY_QUERY`, `ERR_TEXT_TOO_LONG`, `ERR_INVALID_ALPHABET`, `ERR_UNKNOWN_MODE`, `ERR_INVALID_PAGE_LENGTH` |
| Garanties | la recherche réussit toujours ; `lire` de l'adresse renvoyée contient le texte ; le même texte et le même mode donnent toujours la même adresse dans une bibliothèque, y compris en mode `bruit` (le remplissage dépend du texte, pas du hasard) |
| Stories | V4, V5, V15, B2 ; A5 et A7 (retrouver un texte banni) ; M12 (mode `page`) |

La recherche ne renvoie pas la position du texte. L'application web la calcule avec `localiser` (F4) sur la page qu'elle vient de lire.

Le mode `page` vient de la fiche moteur. Il redonne l'adresse d'une page à partir de son texte : `chercher` du texte lu à une adresse renvoie cette adresse. Le serveur n'y applique que les étapes 1 à 3 de la normalisation, qui laissent une page intacte ; réduire ou retirer des espaces en ferait un autre texte. Un texte qui ne fait pas exactement 3 200 caractères est refusé (`ERR_INVALID_PAGE_LENGTH`). L'interface ne propose que `exact` et `bruit` (V5) : le mode `page` sert à l'import d'un compte (M12), pour retrouver ici une page lue dans l'ancienne bibliothèque.

### F3 : normaliser(texte)

| Rubrique | Contenu |
|---|---|
| Entrée | n'importe quelle chaîne de caractères |
| Sortie | un texte écrit avec les 29 symboles |
| Règle | 1. passer en minuscules ; 2. retirer les accents et la cédille (é → e, ç → c) et développer les lettres liées (œ → oe, æ → ae) ; 3. remplacer tout caractère hors alphabet par une espace, saut de ligne compris ; 4. réduire les espaces qui se suivent à une seule ; 5. retirer les espaces du début et de la fin |
| Erreurs | aucune : la fonction accepte tout. Un résultat vide ou trop long est refusé ensuite par `chercher` |
| Garanties | la sortie n'utilise que les 29 symboles ; normaliser deux fois revient à normaliser une fois ; la règle est identique dans toutes les bibliothèques, elle fait partie du contrat commun |
| Stories | V4, V6, V15, B2, A5 |

Exemple : « Où est passé l'été ? » devient `ou est passe l ete`. L'apostrophe devient une espace : on obtient `l ete`, pas `lete`.

Le serveur normalise toujours avant d'appeler le moteur, y compris quand la recherche vient d'une autre bibliothèque. En mode `page`, il s'arrête à l'étape 3. Il renvoie le texte normalisé avec l'adresse, pour que l'application web montre ce qui a réellement été cherché (V6).

### F4 : localiser(texte, page)

| Rubrique | Contenu |
|---|---|
| Entrées | un texte écrit avec les 29 symboles : texte normalisé, ou extrait d'une page repris tel quel ; le texte d'une page (3 200 caractères) |
| Sortie | la liste des positions où le texte commence dans la page, de 0 à 3 199 ; une liste vide s'il n'y figure pas |
| Erreurs | aucune |
| Garanties | toutes les occurrences sont trouvées, dans l'ordre ; après une recherche réussie, la liste contient au moins une position |
| Stories | V7, V11 et V12 (passage à surligner d'un lien court) |

C'est un calcul sur 3 200 caractères, sans moteur ni clé. Il s'exécute dans l'application web : elle a déjà la page et le texte cherché, un appel au serveur n'apporterait rien. Il sert aussi pour une page lue chez une autre bibliothèque. Le serveur fait le même calcul pour une autre raison : savoir si une page contient un texte banni (A5, A7, V21).

### F5 : valider(adresse)

| Rubrique | Contenu |
|---|---|
| Entrée | une chaîne de caractères |
| Sortie | « valide », avec le niveau de l'adresse (page ou livre) ; sinon la liste de toutes les erreurs, chacune avec son code et le champ concerné |
| Erreurs signalées | `ERR_INVALID_ADDRESS_SYNTAX` (forme non respectée, majuscule hors de l'hexagone, zéro devant un nombre, champ manquant) ; `ERR_INVALID_HEX_CHARS` (hexagone écrit avec autre chose que des chiffres et des minuscules, majuscules comprises) ; `ERR_OUT_OF_BOUNDS` (mur, étagère, volume ou page hors limites, hexagone au-delà du dernier) |
| Garanties | ne lève jamais d'exception ; renvoie toutes les erreurs en une fois ; une page n'a qu'une seule écriture valide ; toute adresse de page valide est lisible par `lire` |
| Stories | V1, V8, M2, M13, B1 |

Une seule écriture par page : `w04`, `W4` ou un hexagone en majuscules sont refusés, pas corrigés. Deux marque-pages sur la même page ont ainsi exactement la même adresse.

Une adresse bien écrite peut ne pas exister : la bibliothèque a une dernière page, et le dernier hexagone n'est pas complet. C'est `ERR_OUT_OF_BOUNDS`, pas une erreur de syntaxe.

### F6 : voisine(adresse, sens, niveau)

| Rubrique | Contenu |
|---|---|
| Entrées | l'adresse de page valide de la page affichée ; un sens (`suivante` ou `precedente`) ; un niveau (`page`, `volume`, `etagere`, `mur` ou `hexagone`) |
| Sortie | l'adresse voisine à ce niveau |
| Erreurs | `ERR_LIBRARY_LIMIT` quand l'adresse calculée tombe avant la première page ou après la dernière page de la bibliothèque |
| Garanties | après la page 410 vient la page 1 du volume suivant, puis l'étagère suivante, le mur suivant, l'hexagone suivant ; à un niveau supérieur à la page, les coordonnées plus fines ne changent pas ; `precedente` de `suivante` redonne l'adresse de départ ; sans erreur, la sortie est toujours une adresse valide |
| Stories | V2, V3 |

C'est du calcul sur les coordonnées : ni moteur, ni clé, ni base. Il s'exécute dans l'application web, comme `localiser`, sur l'adresse que le serveur vient de valider. L'hexagone est un nombre en base 36 de 3 004 caractères au plus : il faut un entier sans limite de taille. La lecture ne s'arrête pas à la fin d'un hexagone, puisque l'hexagone suivant existe. Le dernier hexagone ne contient qu'une page : un saut de volume, d'étagère, de mur ou d'hexagone qui tombe au-delà donne `ERR_LIBRARY_LIMIT`.

### F7 : auHasard()

| Rubrique | Contenu |
|---|---|
| Entrée | aucune : pas de graine |
| Sortie | une adresse de page valide |
| Erreurs | aucune |
| Garanties | chaque page de la bibliothèque a la même chance de sortir ; le tirage n'est pas reproductible |
| Stories | V9 |

Le piège est l'uniformité. Un nombre aléatoire ordinaire de 64 bits ne tombe que dans les tout premiers hexagones, ceux dont le nom tient en moins de dix caractères. Or presque toutes les pages sont dans des hexagones au nom très long.

Sans graine, on ne teste pas une valeur : on teste une distribution. Sur **10 000 tirages**, les murs, étagères, volumes et pages sortent à parts égales, et le nom de l'hexagone fait 3 004 caractères dans environ 79 % des cas, 3 003 dans environ 20 %, moins dans moins de 1 %.

### F8 : empreinte()

| Rubrique | Contenu |
|---|---|
| Entrée | aucune |
| Sortie | l'empreinte de la clé : une chaîne de longueur fixe |
| Erreurs | aucune |
| Garanties | la même empreinte tant que la clé ne change pas ; deux clés différentes donnent deux empreintes différentes ; l'empreinte ne permet pas de retrouver la clé |
| Stories | B6, A9 |

La fonction ne reçoit pas la clé et ne la renvoie pas : la décision de séance tient. Chaque bibliothèque publie son empreinte avec son identité (B6). Les autres la gardent, et l'état du réseau signale un changement (A9) : les adresses enregistrées vers cette bibliothèque ne désignent alors plus les mêmes textes.

### Les codes d'erreur

Les six premiers sont ceux de Babel 2. Les quatre derniers ont été ajoutés dans le même style pour couvrir ce que la mise en commun a retenu. La correspondance HTTP est une proposition : elle sera fixée dans le contrat d'API.

| Code | Quand | Réponse HTTP proposée |
|---|---|---|
| `ERR_INVALID_ADDRESS_SYNTAX` | l'adresse ne respecte pas la forme attendue | 422 |
| `ERR_INVALID_HEX_CHARS` | l'hexagone contient un caractère interdit | 422 |
| `ERR_OUT_OF_BOUNDS` | l'adresse est bien écrite mais la page n'existe pas | 404 |
| `ERR_EMPTY_QUERY` | le texte est vide après conversion | 422 |
| `ERR_TEXT_TOO_LONG` | le texte dépasse 3 200 caractères après conversion | 422 |
| `ERR_INVALID_ALPHABET` | le moteur a reçu un caractère hors des 29 symboles | aucune : le serveur normalise avant, cette erreur signale un oubli dans le code |
| `ERR_UNKNOWN_MODE` (ajouté) | le mode de recherche n'existe pas | 422 |
| `ERR_INVALID_PAGE_LENGTH` (ajouté) | en mode `page`, le texte ne fait pas exactement 3 200 caractères | 422 |
| `ERR_LIBRARY_LIMIT` (ajouté) | il n'y a pas de page avant la première ni après la dernière | aucune : `voisine` s'exécute dans l'application web, qui désactive le bouton |
| `ERR_TOO_MANY_REQUESTS` (ajouté) | le quota de l'API publique est dépassé (A10) | 429 |

Toutes les bibliothèques répondent une erreur sous la même forme : une liste d'erreurs, chacune avec son code, le champ concerné quand il y en a un, et un message lisible.

Une réponse ne porte qu'un statut HTTP : 422 dès qu'une erreur de forme figure dans la liste, 404 quand il ne reste que `ERR_OUT_OF_BOUNDS`.

Ces codes couvrent les fonctions. Les autres cas (lien court inconnu, expiré ou masqué, bibliothèque injoignable, accès refusé, inscriptions fermées, import invalide) recevront leur code dans le contrat d'API, dans le même style.

### Ce qui n'est pas une fonction du moteur

| Proposition | Ce qu'elle devient | Pourquoi |
|---|---|---|
| Passer la clé à chaque appel (Babel 3) | écartée | la clé ne circule pas dans le code |
| Graine facultative pour la page au hasard (Babel 2) | écartée | vrai hasard, vérifié par la distribution |
| Renvoyer la position avec l'adresse (Babel 1 et 2) | devient F4 | la position se calcule dans l'application web |
| Mode de recherche « aléatoire » qui change d'adresse à chaque appel (Babel 1) | écartée | un lien vers un résultat doit toujours mener à la même page |
| Renvoyer deux adresses à chaque recherche (Babel 3) | écartée | on choisit un mode, on obtient une adresse |
| S'arrêter à la fin d'un hexagone (Babel 3) | écartée | l'hexagone suivant existe |
| Corriger `w04` en `w4` | écartée | on refuse, on ne corrige pas |
| Titre d'un volume, recherche par titre (Babel 1) | écartée | le moteur ne fournit pas de titre, et la story correspondante est écartée |
| Recherche sur tout le réseau | hors moteur | c'est une orchestration : un appel à `chercher` par bibliothèque (livrable 4) |
| Lien court, marque-page, collection | hors moteur | ce sont des données de l'application (livrable 3) |

---

## Livrable 3 : le modèle logique des données

14 tables. Aucune ne contient de page : la base ne garde que ce que les gens enregistrent, et le moteur recalcule le texte à partir de l'adresse.

Ce livrable n'a pas été repris point par point en séance. Il découle des décisions prises sur les stories (livrable 1) et sur les fonctions (livrable 2). Les numéros entre parenthèses renvoient aux stories. Le schéma est aussi fourni au format draw.io (`livrable3-mld.drawio`).

### Ce que les décisions changent dans le modèle

| Décision | Conséquence dans les tables |
|---|---|
| On ne masque jamais une page | pas de colonne « masquée » sur `ADRESSE`, pas de table des pages masquées |
| On modère ce qui est publié | `masque_le` sur `ANNOTATION`, `COLLECTION` et `LIEN_COURT` ; `SIGNALEMENT` vise un de ces trois contenus, jamais une adresse |
| Les membres annotent, en public ou en privé | table `ANNOTATION` avec `est_publique` |
| Le réseau s'échange des textes bannis | table `TEXTE_BANNI` : le texte lui-même, la bibliothèque d'origine, la décision locale |
| Le réseau est ouvert | ni liste de bibliothèques autorisées ou bloquées dans `BIBLIOTHEQUE` ; le jeton réseau, qui réserve la liste des textes bannis, est dans la configuration du serveur |
| Les comptes sont locaux, on migre | une adresse porte sa bibliothèque : à l'import, chaque adresse est convertie vers la nouvelle bibliothèque, et garde l'ancienne tant que la conversion n'a pas pu se faire |
| Tout le monde crée des liens courts | `LIEN_COURT.utilisateur_id` peut être nul |
| La position se calcule dans l'application web | aucune colonne de position ; un lien court garde le passage à surligner, pas son emplacement |
| La clé reste hors du modèle | aucune colonne ne la contient ; `BIBLIOTHEQUE.empreinte_cle` ne garde que l'empreinte que chaque bibliothèque publie |

### Les tables

La clé primaire est en gras, une clé étrangère commence par `#`.

| Table | Colonnes | Sert à |
|---|---|---|
| `UTILISATEUR` | **id**, pseudo, email, mot_de_passe_hache, role, suspendu_le, derniere_connexion_le, cree_le | V19, M1, M9, M10, A1, A2, A3 |
| `BIBLIOTHEQUE` | **id**, identifiant, nom, description, url_actuelle, est_locale, etat, echecs_consecutifs, dernier_contact_le, vue_annuaire_le, empreinte_cle, cle_changee_le | A8, A9, V15, V16, V21, M13, B6 |
| `ADRESSE` | **id**, #bibliotheque_id, hexagone, mur, etagere, volume, page, empreinte, empreinte_livre, #texte_banni_id, cree_le | tout ce qui désigne une page ou un livre |
| `MARQUE_PAGE` | **id**, #utilisateur_id, #adresse_id, titre, cree_le | M2, M3, M13 |
| `COLLECTION` | **id**, #utilisateur_id, nom, description, est_publique, masque_le, cree_le, maj_le | M4, M5, V13, B3 |
| `COLLECTION_PAGE` | **#collection_id**, **position**, #adresse_id | M4 |
| `ANNOTATION` | **id**, #utilisateur_id, #adresse_id, contenu, est_publique, masque_le, cree_le, maj_le | M6, V14 |
| `VOTE` | **#utilisateur_id**, **#adresse_id**, valeur, cree_le | M7, V13, B4 |
| `LIEN_COURT` | **id**, slug, #utilisateur_id, #collection_id, #adresse_id, passage, visites, expire_le, masque_le, cree_le | V11, V12, A11 |
| `SIGNALEMENT` | **id**, #annotation_id, #collection_id, #lien_court_id, #auteur_id, motif, statut, #traite_par_id, traite_le, cree_le | V17, V18, A4 |
| `TEXTE_BANNI` | **id**, texte, empreinte, motif, #origine_id, statut, decide_le, cree_le | A5, A6, A7, B5 |
| `RECHERCHE` | **id**, #utilisateur_id, texte, mode, portee, cree_le | M8 |
| `ACTIVITE_JOUR` | **jour**, pages_lues, recherches | A11 |
| `REGLAGE` | **cle**, valeur | A8, A10 |

### Types et contraintes (annexe du livrable 3, schéma MLD)

Légende du schéma : souligné = clé primaire, italique = clé étrangère, nul = valeur facultative. Les cardinalités se lisent du côté de chaque table : « 0..n marque-pages pour 1 utilisateur ». Aucune page, aucun texte de page, aucune clé du moteur en base.

- **UTILISATEUR** : id entier PK ; pseudo texte(50) unique ; email texte unique ; mot_de_passe_hache texte ; role `membre | admin` ; suspendu_le date nul ; derniere_connexion_le date nul ; cree_le date.
- **BIBLIOTHEQUE** : id entier PK ; identifiant texte(40) unique, stable ; nom texte ; description texte nul ; url_actuelle texte, peut changer ; est_locale booléen, une seule ligne vraie ; etat `en_ligne | hors_ligne | inconnu` ; echecs_consecutifs entier ; dernier_contact_le date nul ; vue_annuaire_le date nul ; empreinte_cle texte nul ; cle_changee_le date nul.
- **ADRESSE** : id entier PK ; bibliotheque_id FK ; hexagone texte (≤ 3 004 car.), base 36 ; mur entier 1 à 4 ; etagere entier 1 à 5 ; volume entier 1 à 32 ; page entier 1 à 410, nul = livre entier ; empreinte texte(64), SHA-256 de l'adresse ; empreinte_livre texte(64), SHA-256 de l'adresse du livre ; texte_banni_id FK nul ; cree_le date. Unique (bibliotheque_id, empreinte).
- **MARQUE_PAGE** : id PK ; utilisateur_id FK ; adresse_id FK ; titre texte(120) nul ; cree_le date. Unique (utilisateur_id, adresse_id).
- **VOTE** : utilisateur_id PK, FK ; adresse_id PK, FK ; valeur entier +1 ou −1 ; cree_le date.
- **ANNOTATION** : id PK ; utilisateur_id FK ; adresse_id FK ; contenu texte(2000) ; est_publique booléen, choix du membre ; masque_le date nul (modération) ; cree_le, maj_le date.
- **COLLECTION** : id PK ; utilisateur_id FK ; nom texte(120) ; description texte nul ; est_publique booléen, faux par défaut ; masque_le date nul (modération) ; cree_le, maj_le date.
- **COLLECTION_PAGE** : collection_id PK, FK ; position entier PK ; adresse_id FK.
- **LIEN_COURT** : id PK ; slug texte(8) unique ; utilisateur_id FK nul (visiteur) ; collection_id FK nul ; adresse_id FK nul ; passage texte(3200) nul (texte à surligner) ; visites entier ; expire_le date, nul si créé par un membre ; masque_le date nul (modération) ; cree_le date. Exactement une cible : adresse_id ou collection_id.
- **SIGNALEMENT** : id PK ; annotation_id, collection_id, lien_court_id FK nul ; auteur_id FK nul (visiteur) ; motif texte ; statut `en_attente | traite | rejete` ; traite_par_id FK nul ; traite_le date nul ; cree_le date. Exactement une cible : annotation, collection ou lien court.
- **TEXTE_BANNI** : id PK ; texte texte(3200), normalisé ; empreinte texte(64) unique, SHA-256 du texte ; motif texte nul ; origine_id FK (vers BIBLIOTHEQUE) ; statut `propose | applique | rejete` ; decide_le date nul ; cree_le date.
- **RECHERCHE** : id PK ; utilisateur_id FK ; texte texte(3200), normalisé ; mode `exact | bruit` ; portee `locale | reseau` ; cree_le date.
- **REGLAGE** : cle texte(60) PK ; valeur texte.
- **ACTIVITE_JOUR** : jour date PK ; pages_lues entier ; recherches entier.

### Les règles

#### Adresses et bibliothèques

1. **Aucune page en base.** Ni le texte d'une page, ni celui d'un livre. Une adresse suffit à retrouver le texte.
2. **Une adresse n'est enregistrée qu'une fois.** Toutes les tables qui désignent une page pointent vers `ADRESSE`. Un hexagone peut faire 3 004 caractères, et une base refuse un index unique aussi long : l'unicité porte sur (`bibliotheque_id`, `empreinte`), où l'empreinte est le **SHA-256** de l'adresse écrite sous sa forme valide.
3. **Les coordonnées sont en colonnes séparées.** `empreinte_livre`, le **SHA-256** de l'adresse sans la page, permet de retrouver tous les marque-pages d'un même livre sans index sur l'hexagone.
4. **Un livre entier est une adresse sans page.** `page` est nul. C'est ce qui permet de partager un livre de 410 pages (V11). Seul un lien court peut viser un livre : marque-pages, annotations, votes et pages de collection visent toujours une page. Ouvrir un livre, c'est ouvrir sa page 1.
5. **Notre bibliothèque est une ligne comme les autres.** Elle figure dans `BIBLIOTHEQUE` avec `est_locale` à vrai, et une seule ligne peut l'être. Une page d'ici et une page d'une autre bibliothèque se manipulent de la même façon (M13).
6. **Une bibliothèque a un identifiant stable et une URL qui change.** On ne retient jamais l'URL comme identité. `etat`, `echecs_consecutifs` et `dernier_contact_le` alimentent l'état du réseau (A9). `empreinte_cle` garde l'empreinte de clé que la bibliothèque publie (B6) ; si elle change, `cle_changee_le` est daté et l'état du réseau le signale : les adresses enregistrées vers cette bibliothèque ne désignent plus les mêmes textes. Il n'y a ni liste blanche ni liste noire.

#### Ce que les membres enregistrent

7. **Un marque-page est privé.** Un membre ne garde qu'une fois la même page : unique (`utilisateur_id`, `adresse_id`). Ce qui se montre aux autres passe par une collection, une annotation ou un lien court.
8. **Une collection est ordonnée.** La clé de `COLLECTION_PAGE` est (`collection_id`, `position`) : un texte découpé sur plusieurs pages se relit dans l'ordre, et une même page peut revenir deux fois.
9. **Une annotation appartient à un membre.** Elle fait au plus **2 000 caractères**. Son auteur choisit si elle est publique.
10. **Un vote par membre et par page.** La clé de `VOTE` est (`utilisateur_id`, `adresse_id`), la valeur vaut +1 ou −1. Les pages les plus populaires se calculent à partir des votes et du nombre de marque-pages, un total anonyme qui ne montre aucun marque-page : elles ne sont pas stockées.
11. **Un lien court a exactement une cible** : une adresse (page ou livre) ou une collection. Créé sans compte, il n'a pas d'auteur et il expire obligatoirement. `passage` garde le texte à surligner : le texte d'une recherche, ou un extrait de la page repris tel quel, espaces comprises. L'application web le retrouve dans la page avec `localiser`. Un passage ne va qu'avec une cible page.
12. **On ne garde que les recherches des membres** (M8). `portee` dit si la recherche était locale ou lancée sur le réseau. L'adresse trouvée n'est pas stockée : la même recherche la redonne. Les recherches des visiteurs ne laissent qu'un compteur.

#### Modération

13. **Un signalement vise exactement un contenu publié** : une annotation, une collection ou un lien court, par une vraie clé étrangère. Son auteur est nul quand c'est un visiteur. `traite_par_id` garde l'administrateur qui a décidé. Si l'auteur supprime le contenu signalé, ses signalements partent avec lui.
14. **Masquer, c'est dater.** `masque_le` est nul tant que le contenu est visible. Un contenu masqué n'est plus montré au public ; le rétablir, c'est remettre la date à nul. Seul un administrateur qui traite un signalement pose cette date : le signalement traité garde qui a décidé, et quand.
15. **Ne plus voir ce qu'on a signalé ne demande pas de table** (V18). Pour un membre, la liste se déduit de `SIGNALEMENT`. Pour un visiteur, elle reste dans son navigateur.
16. **Un texte banni est stocké en clair, normalisé.** Il faut le texte lui-même pour le chercher : une empreinte seule ne servirait qu'à éviter les doublons, et c'est son rôle ici. `origine_id` désigne la bibliothèque qui l'a banni en premier.
17. **Un texte banni a trois statuts.** Déclaré ici, il est `applique` tout de suite (A5). Relevé chez une autre bibliothèque, il est `propose` jusqu'à ce que l'administrateur l'applique ou le rejette (A7). Seuls les textes d'origine locale et appliqués sont publiés (A6, B5), et seulement aux bibliothèques du réseau : la route exige le jeton réseau. Un bannissement déclaré ici peut être levé : le texte passe à `rejete` et n'est plus publié.
18. **Appliquer un texte banni ne supprime rien.** Les pages restent lisibles. `ADRESSE.texte_banni_id` marque les adresses enregistrées dont la page contient un texte banni appliqué : le premier trouvé suffit. Ce qui est publié vers elles n'est plus montré au public, sans toucher à `masque_le`.

#### Vie du compte et réglages

19. **Supprimer un compte efface ses données** (M10) : marque-pages, collections, annotations, votes, recherches et liens courts partent avec lui. Ses signalements restent, sans auteur.
20. **L'export et l'import ne demandent pas de table** (M11, M12). L'export est un fichier : le profil sans le mot de passe, les marque-pages, collections, annotations, votes, recherches et liens courts, chaque adresse accompagnée de l'identifiant de sa bibliothèque. L'import ne recrée que les marque-pages, les collections et les annotations. Chaque adresse importée est ensuite convertie : la page est lue dans sa bibliothèque d'origine, puis retrouvée ici avec `chercher` en mode `page`. Tant que l'origine ne répond pas, l'adresse garde sa bibliothèque d'origine. Si la conversion fait apparaître deux marque-pages sur la même page, ils sont fusionnés.
21. **Les statistiques sont des compteurs par jour** (A11). Une ligne par page lue ferait des millions de lignes et des données personnelles pour rien.
22. **Les réglages sont des paires clé/valeur** : inscriptions ouvertes ou fermées, quota de l'API publique. Le nom et la description sont sur la ligne locale de `BIBLIOTHEQUE`. La clé du moteur et le jeton réseau n'y figurent jamais : ils sont dans la configuration du serveur.

### Comment un texte banni masque ce qui est publié

Un même texte figure dans un nombre astronomique de pages : on ne peut pas toutes les trouver. On vérifie donc les pages que quelqu'un a désignées.

1. Quand un texte banni est appliqué, le serveur le cherche avec `chercher` en modes `exact` et `bruit`, et marque les adresses obtenues si elles sont enregistrées.
2. Une tâche de fond relit les pages désignées par ce qui est déjà publié, et marque celles qui contiennent le texte. Une page d'une autre bibliothèque est lue chez elle ; si elle ne répond pas, la page est revue au passage suivant.
3. Quand un membre ou un visiteur publie (annotation publique, page ajoutée à une collection publique, lien court), le serveur lit la page visée et y cherche les textes bannis appliqués.
4. Pour un lien court vers un livre, les 410 pages sont vérifiées, et c'est l'adresse du livre qui est marquée.
5. Quand un bannissement est levé, ses marques sont retirées et les pages concernées sont revérifiées contre les autres textes appliqués.
6. Avant d'afficher une collection ou des pages populaires d'une autre bibliothèque (V21), le serveur lit les pages désignées et ne montre pas celles qui contiennent un texte banni appliqué ici. Rien n'est enregistré.

Dans une collection publique, seule la page marquée n'est plus montrée : le reste de la collection reste visible. Le texte d'une annotation n'est pas vérifié automatiquement : il se signale (V17). Le passage d'un lien court figure dans sa page : la vérification de la page le couvre. Le nom et la description d'une collection d'une autre bibliothèque relèvent de sa modération.

### Ce qui a été proposé et n'est pas retenu

| Proposition | Ce qu'elle devient | Pourquoi |
|---|---|---|
| Recopier l'adresse dans chaque table (Babel 1 et 2) | table `ADRESSE` | une seule écriture, un seul index, et la bibliothèque de la page au même endroit |
| `ADRESSE.masquee` (Babel 3) | écartée | on ne masque pas une page |
| Table des pages qu'un membre a masquées pour lui (Babel 2) | règle 15 | la liste se déduit des signalements |
| Contenus masqués par adresse ou par empreinte, partagés au réseau (Babel 2) | `TEXTE_BANNI` | une adresse ne vaut que dans une bibliothèque, une empreinte ne permet pas de chercher |
| Cible de signalement en texte libre (Babel 2) | trois clés étrangères | la base vérifie que le contenu signalé existe |
| Signaler une adresse (Babel 3) | écartée | la page existe forcément, il n'y a rien à retirer |
| Position de début et de fin du surlignage (Babel 2) | `LIEN_COURT.passage` | la position se recalcule dans l'application web |
| Une ligne par page lue (Babel 3) | `ACTIVITE_JOUR` | des compteurs suffisent aux statistiques |
| Bibliothèque bloquée ou autorisée (Babel 2 et 3) | écartée | le réseau est ouvert en lecture |
| « Chez nous » codé par une valeur vide (Babel 2) | `BIBLIOTHEQUE.est_locale` | pas de cas particulier dans les requêtes |
| Collection de marque-pages, sans ordre (Babel 1) | `COLLECTION_PAGE` ordonnée | on range des pages, dans l'ordre de lecture |
| Recherches des visiteurs enregistrées (Babel 1) | compteur par jour | pas de données personnelles sans compte |

---

## Livrable 4 : l'architecture

Un diagramme de conteneurs C4, un diagramme de séquence pour la recherche sur le réseau, et deux déroulés pas à pas : la modération en réseau et la migration d'un compte.

Ce livrable n'a pas été discuté en séance. Il reprend l'architecture de référence et l'ajuste aux décisions prises sur les stories (livrable 1) et les fonctions (livrable 2). Aucune technologie n'est nommée sur les boîtes. Un « pair » est une autre bibliothèque du réseau. Les deux schémas sont aussi fournis au format draw.io.

### Ce que les décisions changent dans l'architecture

| Décision | Conséquence |
|---|---|
| Le réseau est ouvert, une autre bibliothèque a les droits d'un visiteur | une seule API publique, protégée par une limite de débit ; sans jeton, sauf la liste des textes bannis |
| La position se calcule dans l'application web | le serveur renvoie une adresse ; l'application web lit la page, localise le texte et le surligne |
| Aucune fonction ne reçoit la clé | le moteur est un composant embarqué dans le serveur et dans ses tâches de fond, qui lit sa clé dans la configuration ; seule son empreinte est publiée |
| On s'échange des textes bannis, chaque bibliothèque décide | les tâches de fond relèvent les textes bannis des pairs, avec le jeton réseau ; personne n'écrit chez personne |
| Les comptes sont locaux, on migre | l'export et l'import passent par l'API interne ; aucune bibliothèque n'authentifie les membres d'une autre ; les tâches de fond convertissent les adresses importées |
| On refuse une adresse mal écrite | le serveur valide à l'entrée des trois portes et ne corrige rien |

### Les éléments

| Élément | Type | Rôle |
|---|---|---|
| Visiteur ou membre | personne | lit, explore, cherche, crée des liens courts ; avec un compte : marque-pages, collections, annotations |
| Administrateur | personne | gère les comptes, modère ce qui est publié, décide des textes bannis, suit le réseau |
| Application web | conteneur, dans le navigateur | lecteur de pages, navigation, recherche locale et réseau, espace du membre ; calcule les pages voisines, localise et surligne le texte cherché |
| Serveur d'application | conteneur | trois portes : l'API interne pour l'application web, l'API publique pour le réseau, le back-office pour l'administrateur ; normalise les textes et valide les adresses |
| Moteur | composant fourni, dans le serveur et ses tâches de fond | `lire`, `chercher` et `empreinte` ; lit sa clé dans la configuration du serveur |
| Base de données | conteneur | les 14 tables du livrable 3 ; aucun texte de page |
| Tâches de fond | conteneur | une tâche par pair pendant une recherche réseau, contrôle régulier des pairs, relevé de leurs textes bannis, vérification des pages publiées contre les textes bannis, conversion des adresses importées ; même code que le serveur, moteur compris |
| Serveur temps réel | conteneur | garde une connexion ouverte avec l'application web et lui pousse les résultats |
| Plateforme du réseau | système externe, fourni | annuaire des bibliothèques et testeur de conformité ; remet à chaque bibliothèque son secret d'annonce et le jeton réseau |
| Autres bibliothèques | système externe, ×9 | même contrat d'API publique, clé et contenus différents |

### Le diagramme de conteneurs (description)

Dans la frontière « Notre bibliothèque de Babel [système] » : Application web, Serveur d'application (avec le Moteur dessiné à l'intérieur, comme composant), Base de données, Tâches de fond, Serveur temps réel. À l'extérieur : Visiteur ou membre, Administrateur, Plateforme du réseau, Autres bibliothèques du réseau (×9). Légende : trait plein = appel qui attend une réponse ; pointillés = message sans attente (asynchrone).

Texte des boîtes :
- **Serveur d'application** : trois portes : API interne (comptes et données des membres), API publique (le contrat commun : ouverte en lecture, débit limité, jeton réseau pour les seuls textes bannis) et back-office d'administration. Normalise les textes, valide les adresses et refuse celles qui sont mal écrites.
- **Moteur** : `lire(adresse)`, `chercher(texte, mode)`, `empreinte()`. Il lit sa clé dans la configuration du serveur : aucune fonction ne la reçoit, elle n'est ni en base ni transmise.
- **Base de données** : comptes, adresses, marque-pages, collections, annotations, votes, liens courts, signalements, textes bannis, pairs. Aucun texte de page.
- **Plateforme du réseau** : annuaire des bibliothèques (identifiant stable + URL du moment) et testeur de conformité. Remet à chaque bibliothèque son secret d'annonce et le jeton réseau.
- **Autres bibliothèques du réseau** : même contrat d'API publique, clés et contenus différents. Chez nous : les droits d'un visiteur, plus le relevé des textes bannis.

### Les flèches

| De | Vers | Ce qui passe | Attend une réponse |
|---|---|---|---|
| Visiteur ou membre | Application web | lit, navigue, cherche, annote, partage | oui |
| Administrateur | Serveur d'application | modère et administre, par le back-office | oui |
| Application web | Serveur d'application | appels à l'API interne, avec un jeton si la personne est connectée | oui |
| Serveur d'application | Base de données | lit et écrit | oui |
| Serveur d'application | Tâches de fond | dépose une tâche par pair | non |
| Tâches de fond | Autres bibliothèques | recherche réseau (2 secondes au plus), contrôle régulier (identité, empreinte de clé), relevé des textes bannis avec le jeton réseau, lecture des pages distantes à vérifier ou à convertir | oui |
| Tâches de fond | Base de données | lit les pairs et ce qui est publié ; met à jour l'état des pairs, les textes bannis relevés, les marques sur les adresses et les adresses importées | oui |
| Tâches de fond | Serveur temps réel | publie chaque résultat dès qu'il arrive, ou l'échec du pair | non |
| Serveur temps réel | Application web | pousse les résultats, pair par pair, sur la connexion que l'application web a ouverte | non |
| Serveur d'application | Autres bibliothèques | lit une page distante, des collections publiques et des pages populaires (V21) | oui |
| Autres bibliothèques | Serveur d'application | appellent notre API publique : identité, pages, recherche, collections publiques, pages populaires, textes bannis (avec le jeton réseau) | oui |
| Serveur d'application | Plateforme du réseau | s'annonce avec son identifiant et son URL, récupère la liste des pairs | oui |
| Plateforme du réseau | Serveur d'application | vérifie la présence, en lisant l'identité, et la conformité au contrat | oui |

### Les choix à défendre

1. **Le moteur est un composant, pas un conteneur.** Il est livré comme du code appelé directement par le serveur. Les tâches de fond exécutent le même code et l'embarquent aussi. Le dessiner à part laisserait croire à des appels réseau et à une clé qui voyage.
2. **La clé ne quitte pas le serveur.** Elle est dans sa configuration, que lisent le serveur d'application et ses tâches de fond : ni en base, ni dans l'application web, ni dans une réponse. Seule son empreinte est publiée, avec l'identité (B6).
3. **L'application web ne parle qu'à nos serveurs.** Ce sont le serveur d'application et le serveur temps réel. Elle ne contacte jamais une autre bibliothèque directement : c'est notre serveur qui lit la page distante et vérifie la réponse.
4. **Une seule API publique, la même dans les 10 bibliothèques.** Elle est ouverte en lecture, sans jeton : une autre bibliothèque y voit ce qu'un visiteur voit. Une seule route fait exception : la liste des textes bannis exige le jeton réseau, commun à toutes les bibliothèques et gardé dans la configuration du serveur. Le reste est protégé par la limite de débit (A10). La même limite protège les actions sans compte : création de liens courts et signalements.
5. **Les résultats du réseau arrivent au fur et à mesure.** Attendre les 9 réponses rendrait la recherche aussi lente que la bibliothèque la plus lente, et bloquée dès qu'une est éteinte.
6. **Un pair a 2 secondes pour répondre.** Le délai est le même partout : il fait partie du contrat.
7. **Chaque réponse d'un pair est vérifiée contre le contrat** avant d'être affichée. Une réponse hors contrat compte comme un échec. Un 429 n'est pas un échec : le pair est en ligne mais occupé, et son compteur ne bouge pas.
8. **L'état des pairs est suivi en tâche de fond.** Au **troisième échec de suite**, un pair passe hors ligne et n'est plus interrogé jusqu'au prochain contrôle réussi (A9).
9. **L'administrateur travaille dans un back-office servi par le serveur**, séparé de l'application web des visiteurs. Il s'y connecte avec son compte, dont le rôle est `admin`.
10. **La surbrillance est un calcul du navigateur.** Le serveur ne renvoie pas de position : l'application web a la page et le texte cherché, elle applique `localiser`.
11. **Le réseau ne transporte aucune écriture.** Recherche, lecture, collections, textes bannis : tout se lit. La modération et les comptes restent locaux.

### La recherche sur le réseau, pas à pas

Participants du diagramme de séquence : Visiteur ou membre, Application web, Serveur d'application, Base de données, Tâches de fond, Serveur temps réel, Autres bibliothèques (×9).

1. La personne saisit « Où est passé l'été ? » et choisit « tout le réseau » (V15).
2. L'application web crée un identifiant de recherche et s'abonne à ses résultats auprès du serveur temps réel.
3. Elle lance la recherche avec l'identifiant, le texte et le mode.
4. Le serveur normalise le texte (`ou est passe l ete`).
5. Il lit en base la liste des pairs en ligne (9 au plus).
6. Il dépose une tâche par pair.
7. Il répond tout de suite : le texte normalisé, le nombre de pairs interrogés, et l'adresse trouvée chez nous par le moteur.
8. Chaque tâche interroge son pair sur l'API publique, avec 2 secondes au plus. *(en parallèle, pour chaque pair)*
9. Elle vérifie la réponse contre le contrat.
10. Elle publie le résultat.
11. Le serveur temps réel le pousse à l'application web.
12. L'application web affiche l'adresse trouvée chez ce pair.
13. Quand tous les pairs interrogés ont répondu ou que le délai global est écoulé, elle affiche le bilan : « 7 pairs sur 9 ont répondu », avec la liste des absents. Un pair déjà hors ligne n'est pas interrogé et figure parmi les absents.
14. La personne ouvre un des résultats.
15. L'application web demande la page à notre serveur, en donnant la bibliothèque et l'adresse.
16. Notre serveur lit la page chez ce pair par son API publique (il reçoit 3 200 caractères).
17. L'application web localise le texte dans la page et le surligne (V7).
18. Elle affiche la page, texte surligné.

Si un pair est éteint, trop lent ou hors contrat, la tâche publie « pair sans réponse » et augmente son compteur d'échecs (`echecs_consecutifs`, hors ligne au 3e échec de suite). S'abonner avant de lancer évite de perdre le résultat d'un pair très rapide. Le délai global est fixé par chaque bibliothèque : il ne fait pas partie du contrat.

### La modération en réseau, pas à pas

Deux bibliothèques, A et B. Leurs clés diffèrent : le même texte n'est pas à la même adresse chez l'une et chez l'autre. C'est pourquoi on s'échange le texte, pas une adresse ni une empreinte.

1. L'administrateur de A déclare un texte banni (A5). Le serveur le normalise et l'enregistre comme appliqué. Ce qui est publié chez A vers ce texte n'est plus montré au public. Les pages restent lisibles.
2. A expose sur son API publique la liste des textes qu'elle a bannis elle-même (A6). Cette route exige le jeton réseau : un visiteur ne peut pas la lire.
3. Les tâches de fond de B relèvent régulièrement cette liste chez chaque pair en ligne, en présentant le jeton réseau (B5). Un texte nouveau est enregistré chez B comme proposé.
4. L'administrateur de B trouve les propositions dans son back-office (A7). Pour chacune, le serveur de B cherche le texte dans sa propre bibliothèque et montre ce qui serait masqué.
5. Il applique ou il rejette. B reste maîtresse de sa modération.
6. B ne republie pas un texte venu d'ailleurs : chaque bibliothèque ne publie que ses propres décisions.

Deux limites à connaître. Un texte banni arrive chez les autres au prochain relevé, pas à la seconde. Et un texte figure dans un nombre astronomique de pages : on masque ce qui est publié vers les pages vérifiées, on ne peut pas toutes les trouver (livrable 3).

### La migration d'un compte, pas à pas

Un compte n'existe que dans sa bibliothèque. Pour en changer, on emporte ses données.

1. Le membre demande l'export de sa bibliothèque personnelle chez A (M11). Il reçoit un fichier : son profil, ses marque-pages, collections, annotations, votes, recherches et liens courts, chaque adresse accompagnée de l'identifiant de sa bibliothèque. Le mot de passe n'y figure pas.
2. Il crée un compte chez B (V19).
3. Il importe le fichier chez B (M12). Le serveur de B vérifie le fichier contre le contrat, valide chaque adresse, puis recrée les marque-pages, les collections et les annotations. Le reste du fichier n'est pas repris.
4. Une tâche de fond de B convertit ensuite chaque adresse importée : elle lit la page chez A, puis la retrouve chez B avec `chercher` en mode `page`. Le marque-page, la page de collection ou l'annotation désigne alors B, et plus rien ne dépend de A. Tant que A ne répond pas, l'adresse désigne encore A : B y lit la page à l'ouverture (M13) et retente la conversion plus tard.
5. Les collections et les annotations importées arrivent privées. Le membre republie ce qu'il veut, et ce qu'il publie passe par la modération de B.
6. S'il le souhaite, il supprime son compte chez A (M10).

Le format du fichier d'export fait partie du contrat commun : toutes les bibliothèques lisent le fichier des autres.

### Ce qui a été proposé et n'est pas retenu

| Proposition | Ce qu'elle devient | Pourquoi |
|---|---|---|
| Une bibliothèque seule, sans rien autour (Babel 1) | écartée | la recherche réseau, les pages distantes et l'annuaire n'auraient nulle part où se brancher |
| Le moteur dessiné comme un conteneur (3 groupes) | composant dans le serveur | il ne tourne pas à part |
| Un jeton pour les autres bibliothèques (Babel 3) | réduit à une route | le réseau est ouvert en lecture ; seul le relevé des textes bannis exige le jeton réseau |
| Attendre toutes les réponses avant d'afficher (Babel 2) | écartée | les résultats arrivent au fur et à mesure |
| Délai de 3 secondes par pair (Babel 3) | 2 secondes | un seul chiffre pour tout le réseau |
| Cache de 5 minutes sur les réponses des pairs (Babel 3) | facultatif, en mémoire du serveur | une page ne change pas, la durée n'a pas de raison d'être courte ; rien en base |
| Pas d'administrateur sur le schéma (Babel 2) | personne et back-office | 11 stories le concernent |
