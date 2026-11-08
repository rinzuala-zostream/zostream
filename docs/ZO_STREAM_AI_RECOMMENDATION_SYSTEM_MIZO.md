# ZO STREAM AI RECOMMENDATION SYSTEM

## Hnathawh Dan Leh Kalphung — Technical Explanation (Mizo)

Document date: 26 September 2026  
System scope: Zo Stream API v4 homepage movie/series recommendation

---

## 1. Executive Summary

Zo Stream recommendation system hi ChatGPT ang text siamtu Generative AI a ni lo. He system hi user-te movie/series an en dan, eng content nge an duh, movie metadata leh overall popularity te zirchhuaka content remchang ber ranking siamtu **hybrid machine-learning recommender** a ni.

System-in method pathum a blend:

- **Collaborative filtering:** “He movie entu dangte'n eng nge an en tel?”
- **Content similarity:** “He movie nen genre, description, director leh category-ah eng nge inang?”
- **Popularity:** “Zo Stream user-te zingah eng content nge ngaihhlut hlawm?”

Homepage response zawng zawng AI model-in a siam lo. Section pathum chauh trained model-in a rank; section paruk chu current MySQL database atanga live rules-in a siam.

AI/model sections:

- Because You Watched
- Top Picks for You
- Similar Movies

Live database sections:

- Latest Update
- Continue Watching
- Trending Now
- New Releases
- Your Wishlist
- Next Episode

He design avang hian personalized discovery chu AI model-in a pe a, watch progress, wishlist, latest update leh episode dawt leh ang current information chu live database-in a pe.

---

## 2. System Architecture

### 2.1 Request flow

```text
Mobile / TV / Web Client
          |
          | GET /api/v4/recommendations/home
          v
HomeRecommendationController
  - authentication check
  - adult/kids mode validation
  - pagination and requested section
          |
          v
HomeRecommendationService
          |
          +-------------------------------+
          |                               |
          v                               v
LiveHomeSectionService             Python hybrid.py
  - current watch history            - load trained model
  - current wishlist                 - build live user profile
  - live shelves                     - calculate AI ranking
          |                               |
          +---------------+---------------+
                          v
             live validation + merge
                          |
                          v
                   cache + paginate
                          |
                          v
                     JSON response
```

### 2.2 Main components

- `HomeRecommendationController`: API request validate, user authenticate, section layout leh pagination handle.
- `HomeRecommendationService`: Live data leh Python model coordinate, cache leh fallback handle.
- `LiveHomeSectionService`: Current MySQL data atangin live shelves leh current user signals la.
- `recommender/hybrid.py`: Model train, load leh personalized ranking calculate.
- `hybrid_model.json.gz`: Trained catalog, vectors, neighbours, popularity leh user-derived preferences vawng.
- `HomeSectionLayoutService`: Section enable/disable, title leh display order control.

---

## 3. System Lifecycle Pahnih

Recommendation engine hi phase pahnihah hriat hran a pawimawh.

### 3.1 Training-time — model siam hun

Training chu request tinah a run lo. Scheduled job emaw manual command-in a run a, database/backup data tam tak chhiarin model file pakhat a siam.

```text
MySQL / CSV / SQL backup
          |
          v
Published + enabled catalog filter
          |
          +--> movie metadata tokenize --> TF-IDF content vectors
          |
          +--> watch/wishlist signals --> user preference strength
          |
          +--> users' co-watch pattern --> collaborative neighbours
          |
          +--> total preference --> popularity score
          v
hybrid_model.json.gz
```

### 3.2 Request-time — user-in homepage dil hun

Request a lo kalin model pum train leh a ni lo. Model awmsa chu load a ni a, current user watch history leh wishlist chauh live database atangin lak a ni.

```text
Trained shared model
       +
Current user's watch_position
       +
Current user's wist_list
       |
       v
User profile + ranking + filters
       |
       v
Personalized AI sections
```

Hei avang hian user-in tun maia movie a en emaw wishlist-ah a dah emaw chu personalization-ah model retrain hmaa signal atan hman theih a ni. Mahse movie thar pakhat content vector/collaborative neighbours-ah tel tur chuan training thar a ngai.

---

## 4. Training Data Leh Cleaning

### 4.1 Tables hman

- `movie`: title, description, genre, director, release date, poster, flags leh access attributes.
- `watch_position`: user, movie/episode, playback position, duration, created/updated time.
- `wist_list`: user-in movie a save/wishlist-a dah signal.
- `seasons`: series leh season mapping.
- `episodes` and legacy `episode`: episode ID, season, order leh parent series mapping.

### 4.2 Catalog eligibility

Model-ah movie tel tur chuan:

- `status = Published`
- `isEnable = 1`
- ID a awm tur
- Title chu Test, Testing, Demo emaw Sample a ni lo tur

Episode pawh published leh active/enabled a nih a ngai. Episode watch chu a parent movie/series preference-ah map a ni.

### 4.3 ID mapping

Codebase-ah `movie.id`, `movie.num`, current episode ID leh legacy episode ID te an awm avangin aliases a siam. Hei hian watch row hlui leh schema thar chu parent content dik takah a inzawm tir.

### 4.4 Privacy note

Model file-ah user ID leh user-derived preferences an awm. Chuvangin model artifact chu source database ang bawka private data niin ngaih tur a ni; public download path-ah dah tur a ni lo.

---

## 5. User Preference Signal Siam Dan

### 5.1 Watch completion strength

Duration hriat a nih chuan:

```text
completion = position / duration
watch_strength = 0.25 + (2.0 x completion)
```

Completion 80% emaw a aia sang a nih chuan bonus `+0.75` a dawng.

Example:

- 10% en: `0.25 + 2 x 0.10 = 0.45`
- 50% en: `0.25 + 2 x 0.50 = 1.25`
- 90% en: `0.25 + 2 x 0.90 + 0.75 = 2.80`

Chuvangin movie click ringawt aiin a tawp thleng hnaih en chu interest signal chak zawk a ni.

Duration hriat lohva position > 0 a nih chuan strength `1.0`; position pawh awm loh chuan `0.25` a ni.

### 5.2 Wishlist strength

Wishlist item pakhat chuan preference strength `+3.0` a dawng. Hei hi user-in content chu en lo mah se a duh tih explicit intent signal chak tak a ni.

### 5.3 Repeated watch

Same parent movie/series watch signal tamte chu add khawm a ni. Log compression leh cap hman a nih avangin episode tam tak enna khan other preferences zawng zawng a nek lo.

### 5.4 Recency

Signal thar chu signal hlui aiin a chak zawk. Recency half-life chu ni 180:

```text
recency = 0.65 + 0.35 x exp(-age / 180 days)
```

Preference final chu roughly:

```text
preference = min(5, 1 + log(1 + accumulated_strength)) x recency
```

Old preference chu zero vek a ni lo; long-term taste vawn nan minimum influence a nei reng.

---

## 6. Content Similarity (TF-IDF)

Content method hian movie text leh flags atangin mathematical vector a siam.

### 6.1 Field weights

- Description token: weight 1
- Title token: weight 2
- Director token: weight 2
- Genre token: weight 4
- Mizo/Korean/Hollywood/Bollywood/Documentary flag: weight 5

Genre leh category flag-te chu movie identity sawi chiangtu an nih avangin description word aiin weight sang zawk an nei.

### 6.2 Token cleaning

Text chu lowercase/case-fold a ni a, punctuation leh common stop words te paih a ni. Token character pahnih aia tlem pawh paih a ni.

### 6.3 TF-IDF

TF-IDF hian:

- Movie pakhat chhunga term pawimawh a chawikan.
- Catalog zawng zawnga word common lutuk a hnuk hniam.
- Catalog 65% aia tama awm term chu noise angin a paih.
- Movie tin token/vector component chak ber 160 a vawng.

Movie pahnih vector dot-product hmangin similarity a calculate. Genre, director, description leh flags inang chuan content score a sang.

---

## 7. Collaborative Filtering

Collaborative filtering hian movie text a en lo; user-te behaviour a en.

Question a chhan chu:

> “Content A entu user-te'n Content B pawh an en fo em?”

### 7.1 Neighbour building

- User tin preference chak ber 30 a la.
- Movie pair tin common viewers a count.
- Cosine similarity a calculate.
- Common viewers tlem lutuk chu overconfidence lo turin shrinkage a apply.
- Movie tin neighbour chak ber 80 a save.

Simplified score:

```text
cosine = common_users / sqrt(viewers_A x viewers_B)
confidence = common_users / (common_users + 5)
similarity = cosine x confidence
```

Example: User tam takin Film A leh Film B an en dun chuan B chu A neighbour a ni. User pakhat chauhin an en dun chuan score sang lutuk a nei lo, confidence factor-in a tihniam.

### 7.2 Request-time collaborative score

User history item tin preference strength chu neighbour similarity nen multiply a, candidate movie tinah add khawm a ni.

```text
candidate_behavior_score += user_preference x neighbour_similarity
```

---

## 8. Popularity Score

Training-time popularity chu user preference strength total atangin siam a ni. Log scale leh normalization hman a nih avangin blockbuster pakhatin candidate dang zawng zawng a nek lo.

History nei lo user tan Top Picks score chu 100% popularity a ni. Hei hi cold-start fallback a ni.

Important distinction:

- Trained model `popularity` chu Top Picks blend-ah hman a ni.
- Production homepage `Trending Now` shelf chu live database-a `movie.views` descending-in siam a ni.

Chuvangin Top Picks popularity leh Trending Now shelf chu source/meaning inang lo.

---

## 9. Top Picks for You Ranking

Candidate tinah component pathum 0–1 scale-a normalize a ni:

```text
final_score =
    collaborative_weight x behavior_score
  + content_weight       x content_score
  + popularity_weight    x popularity_score
```

Weights chu user history size a zirin a insiam danglam:

```text
History 0     : Collaborative 0%  | Content 0%  | Popularity 100%
History 1–2   : Collaborative 15% | Content 55% | Popularity 30%
History 3–4   : Collaborative 30% | Content 45% | Popularity 25%
History 5+    : Collaborative 50% | Content 35% | Popularity 15%
```

Interpretation:

- User thar: Taste hriat loh avangin popular content.
- History tlem: Watched content metadata nen inang zual.
- History tam: Similar users' behaviour reliable zawk, collaborative weight sang.

User-in a en tawh content chu Top Picks candidate-ah paih a ni.

### 9.1 Reason field

Final score-ah contribution sang ber chu user-facing reason atan hman a ni:

- Users with similar watch history
- Similar to watched genres and content
- Popular with Zo Stream viewers

---

## 10. AI Shelf Pathum

### 10.1 Because You Watched

Anchor chu user-in hnuhnung ber-a a en parent movie/series.

```text
65% collaborative similarity
35% content similarity
```

Hei hi recent interest leh co-watch behaviour a ngai pawimawh.

### 10.2 Similar Movies

Anchor chu user preference list-a item chak ber zual; possible a nih chuan recent anchor nen inang lo.

```text
20% collaborative similarity
80% content similarity
```

Hei hi genre, director, description leh category resemblance a ngai pawimawh zawk.

### 10.3 Top Picks for You

Anchor pakhat chauh hmang lovin user history zawng zawng atangin profile a siam. Collaborative + content + popularity adaptive weights-in final ranking a pe.

### 10.4 Duplicate control

- Same movie ID chu seen/excluded list hmangin shelf dangah a lang nawn lo.
- Exact title duplicate a paih.
- Title token overlap 75% emaw a aia sang, token tlem zawk count 3+ a nih chuan near-duplicate angin a paih.

He rule hian sequel title inang lutuk te pawh a paih thei; diversity a siam mahse franchise discovery tihniam theihna a nei.

---

## 11. Live Database Shelves

### 11.1 Latest Update

Published/enabled movie-te `updated_at`, `create_date`, movie number order-in a pe. Special legacy account pakhat tan Mizo-only rule a awm.

### 11.2 Continue Watching

Item a lang tur chuan:

- Position > 0
- Duration hriat
- Completion < 90%

Current watch rows update-time descending-a lak an nih avangin recently watched incomplete item a hmasa.

### 11.3 Trending Now

Current production response-ah allowed movies chu `views` descending, tie-breaker `num` descending-in a pe. User-in a en tawh item exclude lo.

### 11.4 New Releases

Published/enabled leh `release_on` awm movie-te release date descending-in a pe.

### 11.5 Your Wishlist

Current user's `wist_list` order vawngin movie card a pe. Current content eligibility filter a apply.

### 11.6 Next Episode

User-in series tinah episode sang ber/hnu ber a en chu a hre a, season/episode order-a published active episode dawt leh pakhat a pe.

---

## 12. Request-Time Detailed Flow

1. Client-in authenticated request a thawn.
2. Controller-in `auth_user_id` a check; a awm loh chuan 401.
3. `X-Mode` chu `adult` emaw `kids` a validate.
4. Page, per-page, section leh age-restriction validate.
5. Admin-configured enabled section layout a la.
6. Requested sections zinga AI shelf a awm em tih a check.
7. Live service-in required watch/wishlist signals leh live shelves a la.
8. AI shelf a ngaih chuan script/model readable a nih a check.
9. Cache key a siam.
10. Cache miss a nih chuan Python `hybrid.py homepage` subprocess a run.
11. Live user signals chu command-line-ah dah lovin JSON stdin-in a pass.
12. Python-in model load, user profile siam leh AI shelves rank.
13. Laravel-in AI item ID-te current database against a verify.
14. Current poster, cover, premium leh PPV values-in card a hydrate.
15. Python live shelves chu current MySQL live shelves-in a overwrite.
16. Controller-in requested page slice a siam a, `has_more` leh next/previous page a pe.

Default pagination chu page 1, per-page 10. Maximum per-page 50; page maximum 25.

---

## 13. Cache Design

Default cache duration chu 300 seconds.

Cache key-ah:

- Hashed user ID
- Fetch limit
- Adult/kids mode
- Age-restriction flag
- Model file modification time
- Current user signals hash

Chuvangin:

- User watch/wishlist thlak chuan signal hash a danglam.
- Model retrain/replace chuan model modification time a danglam.
- Kids leh adult results an in-mix lo.
- User ID raw chu cache key-ah a lang lo.

Live shelf values, entirnan `views`, chu AI cache version-ah tel lo. Live shelves chu request tin current DB atanga lak a ni a, cached AI payload-ah overwrite a ni.

---

## 14. Content Policy Leh Safety Filters

Recommendation serve hmain current DB-in a verify leh:

- Published content chauh
- Enabled content chauh
- Kids mode-ah child-mode content chauh
- Kids mode-ah age-restricted content engtikah mah a lang lo
- Adult mode-ah age restriction request-in explicitly phal loh chuan restricted item a lang lo

AI artifact-a poster/cover/premium/PPV values stale thei avangin current DB value-in a update leh.

Premium leh PPV chu response card-a flags chauh an ni. He recommender layer hian user subscription entitlement a check a, premium content chu automatically exclude lo.

---

## 15. Failure Handling

Heng harsatna a awm thei:

- Python binary awm/readable lo
- `hybrid.py` awm/readable lo
- Model file awm/readable lo
- Python timeout
- Invalid JSON output
- Process exit failure

Heng a thlen chuan service-in warning log a ziak a, AI shelf pathum empty-in live shelves a serve chhunzawm. Chuvangin recommender model failure hian homepage pumpui a titla lo.

Default inference timeout chu 30 seconds. Cache awm avangin request tinah Python process run a ngai kher lo.

---

## 16. Model Training Operations

### 16.1 Sources

Training command hian source pathum a support:

- MySQL
- CSV
- Extracted SQL dump

### 16.2 Production schedule

Current scheduler chu `recommender:train-sql-backup` a run. Default schedule chu zan dar 3, configured timezone angin.

Pipeline:

1. Configured pattern atanga latest `.sql.gz` backup select.
2. File freshness leh gzip integrity check.
3. Protected temporary directory-ah extract.
4. Required table schema/INSERT values parse.
5. Hybrid model train.
6. Temporary gzip JSON write.
7. Success chauhin actual model file atomic replace.
8. File group/permission apply (`0640`).
9. Temporary files cleanup.

Training overlap lo turin lock a awm. Scheduler pawhin `withoutOverlapping` leh `onOneServer` a hmang.

### 16.3 Default configuration

```text
Model path       : recommender/artifacts/hybrid_model.json.gz
Inference timeout: 30 seconds
Cache duration   : 300 seconds
Training timeout : 3600 seconds
Training schedule: 0 3 * * *
Backup max age   : 30 hours
```

---

## 17. Cold Start Behaviour

### New user, history nei lo

- Top Picks: trained popularity only.
- Because You Watched: anchor awm lo, empty.
- Similar Movies: anchor awm lo, empty.
- Live Trending/New Releases/Latest Update: awm reng thei.
- Wishlist: user-in item a save tawh chuan wishlist itself leh Top Picks preference-ah signal a pe.

### Movie thar, model train hnu lama publish

- Live Latest Update/New Releases/Trending-ah a lang thei.
- AI content vectors/neighbours-ah training dawt leh hma chuan a tel lo.

### History tlem

Content similarity weight sang a ni; collaborative data tlem avanga unstable recommendation a veng.

---

## 18. Current Behaviour-a Hriat Tur Pawimawh

1. Documentation hluiin Trending Now chu recent 30-day unique viewers angin a sawi thei. Python model-ah chu metric a awm, mahse production homepage-in live `views` ordering-in a overwrite.
2. README heading-in “Homepage section 8” a tih laiin current layout-ah section 9, `latest_update` telin, a awm.
3. AI model output chu entitlement filter a ni lo; Premium/PPV flags chauh a pe.
4. Model file-ah user-derived private data a awm.
5. Python subprocess per cache miss hman a ni; persistent model server a ni lo.
6. Item-details “You may also like” endpoint chu he hybrid model a hmang lo. Title similarity, genre/category leh random fallback a hmang.
7. Reel recommendation chu `127.0.0.1:8001` service hran; homepage movie recommender nen a inang lo.

---

## 19. End-to-End Example

User A hian:

- Mizo drama Film X 90% a en.
- Korean romance Film Y 40% a en.
- Documentary Z wishlist-ah a dah.

System interpretation:

- Film X watch strength sang, 80% bonus a dawng.
- Film Y interest medium.
- Documentary Z wishlist strength `+3`, intent signal chak.
- Recent signal chu old signal aiin weight sang zawk.

Top Picks candidate C tan:

- Similar users-in C an en: behavior score sang.
- C chu drama/Mizo metadata a nei: content score sang.
- Overall popular: popularity score medium.

History size 3 a nih chuan weights 30% behavior, 45% content, 25% popularity. Candidate C final score chu component weighted sum a ni. User-in C a en tawh chuan candidate list atangin paih a ni.

Because You Watched shelf chu most recent watched Film X anchor-in 65% collaborative + 35% content a hmang. Similar Movies shelf chu favourite anchor atangin 80% content similarity a hmang.

---

## 20. Glossary

- **Candidate:** Recommend tur movie possible pakhat.
- **Anchor:** Similar items zawng nan starting movie.
- **Feature/vector:** Movie attribute-te number representation-a siam.
- **TF-IDF:** Movie pakhat tana word pawimawh, catalog zawng tana rare/common dan measure.
- **Collaborative filtering:** Similar user behaviour atanga recommendation.
- **Content-based:** Movie metadata similarity atanga recommendation.
- **Hybrid:** Method pahnih emaw a aia tam blend.
- **Cold start:** User/movie history la awm lohna.
- **Inference:** Model awmsa hmanga ranking calculate.
- **Training:** Historical data atanga model artifact siam.
- **Normalization:** Different score scale-te 0–1 range hnaihva siam.
- **Atomic replace:** Model thar complete taka write zawh hnu chauha old model thlak.

---

## 21. Code Reference Map

- Configuration: `config/recommender.php`
- API controller: `app/Http/Controllers/Api/V4/HomeRecommendationController.php`
- Orchestration/cache/fallback: `app/Services/HomeRecommendationService.php`
- Live shelves and validation: `app/Services/LiveHomeSectionService.php`
- Section arrangement: `app/Services/HomeSectionLayoutService.php`
- AI training/ranking: `recommender/hybrid.py`
- Training command: `app/Console/Commands/TrainRecommender.php`
- SQL backup pipeline: `app/Console/Commands/TrainRecommenderFromSqlBackup.php`
- Scheduler: `routes/console.php`

---

## 22. One-Page Mental Model

```text
HISTORICAL DATA (daily training)
  movie metadata + all users' watch/wishlist
                 |
                 v
  content vectors + co-watch neighbours + popularity
                 |
                 v
          compressed model file

CURRENT REQUEST
  trained model + this user's current signals
                 |
                 v
  adaptive hybrid scoring
                 |
                 v
  Because You Watched / Top Picks / Similar Movies
                 |
                 + current DB live shelves
                 |
                 v
  policy validation + fresh artwork/flags + pagination
                 |
                 v
             client response
```

Final summary: Zo Stream AI recommender chu **shared historical learning** leh **current individual behaviour** a inzawm tir. Model hian discovery ranking a siam; live Laravel queries hian current state, progress leh catalog safety a vawng. Failure a awm pawhin live homepage a function chhunzawm turin graceful fallback a nei.
