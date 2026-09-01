# Zo Stream hybrid recommendation

He pipeline hian CSV emaw MySQL source chu **read-only**-in a chhiar a. Source data a edit lo va,
model thar chu `recommender/artifacts/` chhungah atomic-in a replace.

## Train

CSV atanga train nan external package a ngai lo:

```bash
python3 recommender/hybrid.py train
```

MySQL atanga train nan Python environment-ah driver install rawh:

```bash
python3 -m venv recommender/.venv
recommender/.venv/bin/pip install -r recommender/requirements.txt
```

Laravel `.env`-ah Python path dah la:

```dotenv
RECOMMENDER_PYTHON=/absolute/path/to/recommender/.venv/bin/python
RECOMMENDER_TRAIN_SOURCE=mysql
RECOMMENDER_TRAIN_TIMEOUT_SECONDS=3600
RECOMMENDER_TRAIN_SCHEDULE="0 3 * * *"
RECOMMENDER_TRAIN_TIMEZONE=Asia/Kolkata
```

Production-ah config cache hman a nih chuan `.env` tihdanglam hnuah:

```bash
php artisan config:clear
```

Laravel hian DB connection awmsa chu password command-line-a langtir lovin Python-ah a pass.
Manual train duh hunah:

```bash
php artisan recommender:train
```

## Daily `.sql.gz` backup atanga direct training

Production scheduler-in default-in `recommender:train-sql-backup` a run. He command hian:

1. configured pattern atanga `.sql.gz` latest ber a select;
2. backup freshness leh gzip integrity a check;
3. `storage/app/recommender-training/run-*` protected folder-ah `backup.sql` extract;
4. Python-in `CREATE TABLE` column order leh extended `INSERT VALUES` direct parse;
5. model train leh atomic-in a replace;
6. success emaw failure emaw hnuah temporary folder pum a remove.

Temporary MySQL DB, MySQL user thar leh live DB connection a ngai lo. Original backup file leh
production database a delete/edit lo. Manual full pipeline:

```bash
php artisan recommender:train-sql-backup
```

Backup file bik test nan:

```bash
php artisan recommender:train-sql-backup \
  --backup=/var/backups/mysql/zo_stream_db_2026-09-01.sql.gz
```

Diagnosis atan extracted SQL temporary-a vawn duh chuan `--keep-temporary-files` hman theih.

`.env`:

```dotenv
RECOMMENDER_BACKUP_PATTERN="/var/backups/mysql/zo_stream_db_*.sql.gz"
RECOMMENDER_BACKUP_MAX_AGE_HOURS=30
```

CSV fallback manual train:

```bash
php artisan recommender:train --source=csv --data-dir=/path/to/csv-folder
```

Scheduler chu default-in zan dar 3-ah a train. Server crontab-ah Laravel scheduler entry pakhat
a awm tur a ni:

```cron
* * * * * cd /absolute/path/to/zostream && php artisan schedule:run >> /dev/null 2>&1
```

Default model output:

```text
recommender/artifacts/hybrid_model.json.gz
```

Output path dang hman duh chuan:

```bash
python3 recommender/hybrid.py train --output /path/to/hybrid_model.json.gz
```

## Recommendation lak

```bash
python3 recommender/hybrid.py recommend --user-id USER_UID --limit 10
```

History nei lo user tan `--user-id` tel lovin run rawh; popular content a pe ang:

```bash
python3 recommender/hybrid.py recommend --limit 10
```

Personal data print lova model test nan:

```bash
python3 recommender/hybrid.py recommend --demo --limit 5
```

## Homepage section 8 lak

Project root atangin:

```bash
python3 recommender/hybrid.py homepage --user-id USER_UID --limit 10
```

`recommender` folder chhung atangin:

```bash
python3 hybrid.py homepage --user-id USER_UID --limit 10
```

Backend content policy pawh request-ah enforce theih a ni:

```bash
# Kids catalogue only; age-restricted content is always excluded.
python3 hybrid.py homepage --user-id USER_UID --mode kids --limit 10

# Adult mode with age-restricted content explicitly enabled.
python3 hybrid.py homepage --user-id USER_UID --mode adult --include-age-restricted --limit 10
```

Output-ah heng section-te a awm:

1. `continue_watching`: position awm, duration hriat leh completion 90% hnuai
2. `because_you_watched`: recent-a en item atanga behavior/content similarity
3. `top_picks_for_you`: personalized hybrid ranking
4. `similar_movies`: favourite item atanga content similarity
5. `trending_now`: recent ni 30 chhunga unique viewer leh watch strength
6. `new_releases`: published/enable content release date thar ber
7. `your_wishlist`: user wishlist direct
8. `next_episode`: recent series watch atanga published episode dawt leh

Safety/ranking rules:

- Movie leh episode `status = Published` chauh output-ah a lang
- Disabled content leh `Test`/`Demo`/`Sample` dummy title-te a lang lo
- `trending_now` leh `new_releases`-ah user-in a en tawh content a lang nawn lo
- Title near-duplicate chu shelf pakhat/cross-discovery shelf-ah a lang nawn lo
- `next_episode` chu series tin episode dawt leh pakhat chauh a pe

History nei lo user tan personalized section-te empty thei a; `top_picks_for_you`,
`trending_now` leh `new_releases` erawh popularity/release data hmangin a awm reng ang.

## Model-in a hman data

- `watch_position`: watch completion, recency leh user-content interaction
- `wist_list`: user intent signal chak
- `movie`: title, genre, description, director leh content flags
- `episode`, `episodes`, `seasons`: episode chu parent series/movie-ah map nan

Algorithm chu implicit collaborative similarity + TF-IDF content similarity + popularity
blend a ni. History nei lo user tan popularity, history tlem tan content similarity, history
tam tan collaborative signal weight a sang zawk.

Model file-ah user ID leh derived preference a awm avangin source database ang bawkin private-a
vawn tur a ni. Production API-in `premium`, `ppv`, child-mode leh subscription entitlement
filter a apply hnuah result a serve tur a ni.
