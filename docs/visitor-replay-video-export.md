# Export vidéo des replays Visitor Intelligence

La génération MP4 relit les événements rrweb dans Chromium headless puis encode les images avec FFmpeg. Elle s’exécute dans la file dédiée `visitor-replay-export` ; les workers d’analytics, du widget et de l’agent Growth Advisor ne sont pas occupés par cet export. Les fichiers sont conservés sur le disque privé configuré par `VISITOR_INTELLIGENCE_REPLAY_VIDEO_STORAGE_DISK` (par défaut `local`) et expirent au bout de deux jours.

## Prérequis VPS

- Node.js compatible avec le moteur rrweb (Node 18 ou supérieur).
- Une installation FFmpeg accessible au compte `elchat` ; le binaire peut être précisé avec `VISITOR_INTELLIGENCE_REPLAY_VIDEO_FFMPEG_BINARY`.
- Le binaire Chrome/Chromium direct déjà utilisé pour le rendu rrweb. Définir `VISITOR_INTELLIGENCE_RRWEB_CHROMIUM_BINARY_PATH` si sa détection automatique n’est pas fiable. Les launchers Chromium Snap ne sont pas utilisés par le worker.
- Les dépendances Node du renderer installées depuis `backend/rrweb-renderer/package.json` (`@rrweb/replay` et `ws`).

Exemple Ubuntu pour FFmpeg :

```sh
sudo apt-get update
sudo apt-get install -y ffmpeg
cd /var/www/elchat/backend/rrweb-renderer
npm install --omit=dev --no-package-lock
```

Vérifier ensuite sous le même compte Unix que le worker :

```sh
sudo -u elchat node --version
sudo -u elchat ffmpeg -version
```

## Déploiement applicatif

Pour les corrections du renderer, aucune migration n’est requise. Déployer les fichiers backend/renderer modifiés. Le renderer passe en version 4 afin que les MP4 déjà prêts soient régénérés au prochain clic sur « Télécharger MP4 ». Exécuter `php artisan config:cache`, puis redémarrer le worker dédié `elchat-worker-visitor-replay-export`.

Copier la section `elchat-worker-visitor-replay-export` de `backend/docs/elchat-workers.conf` dans la configuration Supervisor du VPS, puis appliquer la nouvelle configuration :

```sh
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart elchat-worker-visitor-replay-export:*
```

Le scheduler Laravel existant exécute `visitor-intelligence:prune` toutes les heures ; il purge aussi les exports expirés. Après un changement de configuration Laravel, reconstruire le cache de configuration et redémarrer les workers (`php artisan queue:restart`).

## Limites de protection

Par défaut, un export accepte jusqu’à 64 MiB de données rrweb compressées, 256 MiB décompressées, 100 000 événements et dix minutes de replay. Le MP4 reprend exactement la largeur et la hauteur initiales enregistrées par rrweb ; le renderer ne met plus le replay à l’échelle ni dans un viewport de substitution. Un viewport dépassant la limite configurée (`VISITOR_INTELLIGENCE_REPLAY_VIDEO_MAX_OUTPUT_DIMENSION`, 4 320 px par défaut) est refusé plutôt que déformé. Pour les dimensions impaires, l’encodage H.264 utilise le format 4:4:4 afin d’éviter d’ajouter une bordure d’un pixel.

Les nouveaux enregistrements rrweb intègrent les pixels des images `<img>` disponibles au moment de leur capture, compressés en WebP. Les images sont limitées à 400 Ki caractères chacune et à 900 Ki caractères par snapshot afin de préserver la limite d’ingestion. Pour les anciens replays qui ne contiennent que des URL, Chromium peut récupérer les images du site configuré : seule l’origine exacte du site est autorisée, après résolution et épinglage sur une adresse IPv4 publique, et uniquement pour des requêtes GET de type image. Les autres requêtes HTTP(S), ainsi que les destinations privées/locales, restent bloquées pour prévenir les SSRF. Le renderer réutilise le même moteur `@rrweb/replay` que le lecteur du back-office et attend le chargement/décodage des images avant de capturer les frames.
