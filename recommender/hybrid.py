#!/usr/bin/env python3
"""Hybrid recommender for Zo Stream CSV exports or a live MySQL database."""

from __future__ import annotations

import argparse
import csv
import gzip
import json
import math
import os
import re
import sys
import tempfile
from urllib.parse import unquote, urlparse
from array import array
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path


CSV_FIELD_LIMIT = 50_000_000
csv.field_size_limit(CSV_FIELD_LIMIT)

TOKEN_RE = re.compile(r"[^\W_]+", re.UNICODE)
STOP_WORDS = {
    "a", "an", "and", "are", "as", "at", "be", "by", "chu", "for", "from",
    "he", "hi", "in", "is", "it", "kan", "leh", "of", "on", "or", "that",
    "the", "this", "tih", "to", "tur", "with", "ni",
}


MYSQL_COLUMNS = {
    "movie": (
        "id", "num", "title", "status", "isEnable", "description", "director",
        "genre", "release_on", "create_date", "poster", "isPremium",
        "isPayPerView", "isChildMode", "isAgeRestricted", "isMizo", "isKorean",
        "isHollywood", "isBollywood", "isDocumentary",
    ),
    "seasons": ("id", "movie_id", "season_number"),
    "episodes": (
        "id", "num", "season_id", "status", "is_active", "episode_number",
        "title", "thumbnail", "release_date",
    ),
    "episode": (
        "id", "num", "movie_id", "season_id", "status", "isEnable", "title",
        "img", "create_date",
    ),
    "watch_position": (
        "user_id", "movie_id", "movie_type", "position", "duration", "created_at",
        "updated_at",
    ),
    "wist_list": ("uid", "movie_id", "created_at", "updated_at"),
}


def csv_rows(path: Path):
    """Read a CSV without ever opening it for writing."""
    with path.open("r", encoding="utf-8-sig", newline="", errors="replace") as handle:
        yield from csv.DictReader(handle)


class CsvDataSource:
    def __init__(self, data_dir: Path):
        self.data_dir = data_dir

    def validate(self):
        missing = [
            f"{table}.csv"
            for table in MYSQL_COLUMNS
            if not (self.data_dir / f"{table}.csv").is_file()
        ]
        if missing:
            raise FileNotFoundError(f"Missing source CSV files: {', '.join(missing)}")

    def rows(self, table: str):
        yield from csv_rows(self.data_dir / f"{table}.csv")

    def close(self):
        pass


class MySqlDataSource:
    def __init__(self):
        try:
            import pymysql
            from pymysql.cursors import SSDictCursor
        except ImportError as exception:
            raise RuntimeError(
                "MySQL training requires PyMySQL. Install recommender/requirements.txt "
                "in the Python environment configured by RECOMMENDER_PYTHON."
            ) from exception

        settings = self._settings_from_environment()
        self.prefix = os.environ.get("RECOMMENDER_DB_PREFIX", "")
        if not re.fullmatch(r"[A-Za-z0-9_]*", self.prefix):
            raise ValueError("RECOMMENDER_DB_PREFIX contains invalid characters.")
        self.connection = pymysql.connect(
            cursorclass=SSDictCursor,
            autocommit=True,
            **settings,
        )

    @staticmethod
    def _settings_from_environment():
        settings = {
            "host": os.environ.get("RECOMMENDER_DB_HOST", "127.0.0.1"),
            "port": int(os.environ.get("RECOMMENDER_DB_PORT", "3306")),
            "user": os.environ.get("RECOMMENDER_DB_USER", "root"),
            "password": os.environ.get("RECOMMENDER_DB_PASSWORD", ""),
            "database": os.environ.get("RECOMMENDER_DB_NAME", ""),
            "charset": os.environ.get("RECOMMENDER_DB_CHARSET", "utf8mb4"),
        }
        socket = os.environ.get("RECOMMENDER_DB_SOCKET", "")
        if socket:
            settings["unix_socket"] = socket

        database_url = os.environ.get("RECOMMENDER_DB_URL", "")
        if database_url:
            parsed = urlparse(database_url)
            if parsed.scheme not in {"mysql", "mariadb"}:
                raise ValueError("RECOMMENDER_DB_URL must use mysql:// or mariadb://.")
            settings.update({
                "host": parsed.hostname or settings["host"],
                "port": parsed.port or settings["port"],
                "user": unquote(parsed.username or settings["user"]),
                "password": unquote(parsed.password or settings["password"]),
                "database": unquote(parsed.path.lstrip("/") or settings["database"]),
            })
        if not settings["database"]:
            raise ValueError("MySQL database name is missing.")
        return settings

    def validate(self):
        with self.connection.cursor() as cursor:
            for table in MYSQL_COLUMNS:
                full_name = f"{self.prefix}{table}"
                cursor.execute("SHOW TABLES LIKE %s", (full_name,))
                if cursor.fetchone() is None:
                    raise RuntimeError(f"Required MySQL table is missing: {full_name}")

    def rows(self, table: str):
        if table not in MYSQL_COLUMNS:
            raise ValueError(f"Unsupported source table: {table}")
        full_name = f"{self.prefix}{table}"
        columns = ", ".join(f"`{column}`" for column in MYSQL_COLUMNS[table])
        cursor = self.connection.cursor()
        try:
            cursor.execute(f"SELECT {columns} FROM `{full_name}`")
            yield from cursor
        finally:
            cursor.close()

    def close(self):
        self.connection.close()


class MySqlDumpDataSource:
    """Stream the tables required by training from a standard mysqldump file."""

    CREATE_RE = re.compile(r"^CREATE TABLE `([^`]+)` \(")
    COLUMN_RE = re.compile(r"^\s*`([^`]+)`\s")
    INSERT_RE = re.compile(r"^INSERT INTO `([^`]+)` VALUES ")

    def __init__(self, dump_file: Path):
        self.dump_file = dump_file
        self.schemas = {}

    def validate(self):
        if not self.dump_file.is_file():
            raise FileNotFoundError(f"MySQL dump is missing: {self.dump_file}")
        self.schemas = self._read_schemas()
        missing = [table for table in MYSQL_COLUMNS if table not in self.schemas]
        if missing:
            raise RuntimeError(
                f"Required tables are missing from MySQL dump: {', '.join(missing)}"
            )

    def _read_schemas(self):
        schemas = {}
        current = None
        with self.dump_file.open("r", encoding="utf-8", errors="replace") as handle:
            for line in handle:
                match = self.CREATE_RE.match(line)
                if match:
                    current = match.group(1)
                    schemas[current] = []
                    continue
                if current is None:
                    continue
                column = self.COLUMN_RE.match(line)
                if column:
                    schemas[current].append(column.group(1))
                elif line.startswith(")"):
                    current = None
        return schemas

    def rows(self, table: str):
        columns = self.schemas.get(table)
        if columns is None:
            raise ValueError(f"Unsupported dump table: {table}")
        prefix = f"INSERT INTO `{table}` VALUES "
        with self.dump_file.open("r", encoding="utf-8", errors="replace") as handle:
            statement = ""
            collecting = False
            for line in handle:
                if not collecting and line.startswith(prefix):
                    statement = line[len(prefix):]
                    collecting = True
                elif collecting:
                    statement += line
                else:
                    continue
                if statement.rstrip().endswith(";"):
                    payload = statement.rstrip()[:-1]
                    for values in parse_mysql_values(payload):
                        if len(values) != len(columns):
                            raise RuntimeError(
                                f"Column/value mismatch while parsing dump table {table}."
                            )
                        yield dict(zip(columns, values))
                    statement = ""
                    collecting = False
            if collecting:
                raise RuntimeError(f"Incomplete INSERT statement in dump table {table}.")

    def close(self):
        pass


def parse_mysql_values(payload: str):
    escape_map = {
        "0": "\0", "b": "\b", "n": "\n", "r": "\r", "t": "\t",
        "Z": "\x1a", "\\": "\\", "'": "'", '"': '"',
    }
    index = 0
    length = len(payload)
    while index < length:
        while index < length and (payload[index].isspace() or payload[index] == ","):
            index += 1
        if index >= length:
            return
        if payload[index] != "(":
            raise RuntimeError("Invalid mysqldump VALUES tuple.")
        index += 1
        values = []
        while True:
            while index < length and payload[index].isspace():
                index += 1
            if index < length and payload[index] == "'":
                index += 1
                value = []
                while index < length:
                    character = payload[index]
                    index += 1
                    if character == "\\":
                        if index >= length:
                            raise RuntimeError("Incomplete escape in mysqldump string.")
                        escaped = payload[index]
                        index += 1
                        value.append(escape_map.get(escaped, escaped))
                    elif character == "'":
                        if index < length and payload[index] == "'":
                            value.append("'")
                            index += 1
                        else:
                            break
                    else:
                        value.append(character)
                else:
                    raise RuntimeError("Unterminated string in mysqldump VALUES.")
                parsed = "".join(value)
            else:
                start = index
                while index < length and payload[index] not in ",)":
                    index += 1
                token = payload[start:index].strip()
                parsed = None if token.upper() == "NULL" else token
            values.append(parsed)
            while index < length and payload[index].isspace():
                index += 1
            if index >= length:
                raise RuntimeError("Incomplete mysqldump VALUES tuple.")
            delimiter = payload[index]
            index += 1
            if delimiter == ")":
                yield values
                break
            if delimiter != ",":
                raise RuntimeError("Invalid delimiter in mysqldump VALUES tuple.")


def clean(value) -> str:
    return str(value or "").strip()


def parse_time(value: str) -> float:
    value = clean(value)
    if not value:
        return 0.0
    try:
        return datetime.strptime(value[:19], "%Y-%m-%d %H:%M:%S").replace(
            tzinfo=timezone.utc
        ).timestamp()
    except ValueError:
        return 0.0


def parse_date(value: str) -> float:
    value = clean(value)
    for pattern in ("%Y-%m-%d", "%Y/%m/%d", "%d-%m-%Y"):
        try:
            return datetime.strptime(value[:10], pattern).replace(tzinfo=timezone.utc).timestamp()
        except ValueError:
            pass
    return 0.0


def as_float(value, default=0.0) -> float:
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def tokenize(value: str):
    return [
        token
        for token in TOKEN_RE.findall(clean(value).casefold())
        if len(token) > 1 and token not in STOP_WORDS
    ]


def load_catalog(source):
    catalog = {}
    aliases = {}
    raw_terms = {}
    for row in source.rows("movie"):
        item_id = clean(row.get("id"))
        title = clean(row.get("title"))
        if not item_id:
            continue
        published = clean(row.get("status")).casefold() == "published"
        enabled = clean(row.get("isEnable")) == "1"
        dummy_title = title.casefold() in {"test", "testing", "demo", "sample"}
        if not (published and enabled) or dummy_title:
            continue

        item = {
            "id": item_id,
            "title": title or f"Content {item_id}",
            "status": "Published",
            "genre": clean(row.get("genre")),
            "director": clean(row.get("director")),
            "release_on": clean(row.get("release_on")),
            "created_on": clean(row.get("create_date")),
            "poster": clean(row.get("poster")),
            "premium": clean(row.get("isPremium")) == "1",
            "ppv": clean(row.get("isPayPerView")) == "1",
            "child_mode": clean(row.get("isChildMode")) == "1",
            "age_restricted": clean(row.get("isAgeRestricted")) == "1",
        }
        catalog[item_id] = item
        aliases[item_id] = item_id
        numeric_id = clean(row.get("num"))
        if numeric_id:
            # The newer season/episode tables reference movie.num, while
            # watch_position movie rows reference movie.id.
            aliases[numeric_id] = item_id

        terms = Counter()
        for token in tokenize(row.get("description")):
            terms[token] += 1.0
        for token in tokenize(row.get("title")):
            terms[token] += 2.0
        for token in tokenize(row.get("director")):
            terms[token] += 2.0
        for token in tokenize(row.get("genre")):
            terms[token] += 4.0
        for flag in ("isMizo", "isKorean", "isHollywood", "isBollywood", "isDocumentary"):
            if clean(row.get(flag)) == "1":
                terms[f"flag_{flag[2:].casefold()}"] += 5.0
        raw_terms[item_id] = terms
    return catalog, aliases, raw_terms


def load_episode_data(source, movie_aliases):
    seasons = {}
    for row in source.rows("seasons"):
        season_id = clean(row.get("id"))
        seasons[season_id] = {
            "parent_id": movie_aliases.get(clean(row.get("movie_id")), ""),
            "season_number": int(as_float(row.get("season_number"), 0)),
        }

    episode_parent = {}
    episode_aliases = {}
    episode_catalog = {}
    new_by_number = {}
    for row in source.rows("episodes"):
        episode_id = clean(row.get("id"))
        season_id = clean(row.get("season_id"))
        season = seasons.get(season_id, {})
        published = clean(row.get("status")).casefold() == "published"
        active = clean(row.get("is_active")) == "1"
        if not episode_id or not (published and active):
            continue
        episode_catalog[episode_id] = {
            "id": episode_id,
            "legacy_id": "",
            "num": clean(row.get("num")),
            "parent_id": season.get("parent_id", ""),
            "season_id": season_id,
            "season_number": season.get("season_number", 0),
            "episode_number": int(as_float(row.get("episode_number"), 0)),
            "title": clean(row.get("title")) or f"Episode {clean(row.get('episode_number'))}",
            "status": "Published",
            "thumbnail": clean(row.get("thumbnail")),
            "release_date": clean(row.get("release_date")),
        }
        episode_aliases[episode_id] = episode_id
        episode_parent[episode_id] = season.get("parent_id", "")
        number = clean(row.get("num"))
        if number:
            new_by_number[number] = episode_id

    # The legacy and current episode tables use different IDs but share `num`.
    for row in source.rows("episode"):
        legacy_id = clean(row.get("id"))
        parent_id = movie_aliases.get(clean(row.get("movie_id")), "")
        canonical_id = new_by_number.get(clean(row.get("num")), legacy_id)
        if canonical_id not in episode_catalog:
            published = clean(row.get("status")).casefold() == "published"
            enabled = clean(row.get("isEnable")) == "1"
            if not legacy_id or not (published and enabled):
                continue
            season_id = clean(row.get("season_id"))
            season = seasons.get(season_id, {})
            episode_catalog[canonical_id] = {
                "id": canonical_id,
                "legacy_id": legacy_id,
                "num": clean(row.get("num")),
                "parent_id": parent_id,
                "season_id": season_id,
                "season_number": season.get("season_number", 0),
                "episode_number": 0,
                "title": clean(row.get("title")) or "Episode",
                "status": "Published",
                "thumbnail": clean(row.get("img")),
                "release_date": clean(row.get("create_date")),
            }
        else:
            episode_catalog[canonical_id]["legacy_id"] = legacy_id
        episode_aliases[legacy_id] = canonical_id
        episode_parent[legacy_id] = parent_id

    grouped = defaultdict(list)
    for episode_id, episode in episode_catalog.items():
        if episode["parent_id"] and episode["episode_number"] > 0:
            grouped[episode["parent_id"]].append(episode_id)
    next_episode = {}
    for episode_ids in grouped.values():
        episode_ids.sort(key=lambda eid: (
            episode_catalog[eid]["season_number"],
            episode_catalog[eid]["episode_number"],
            eid,
        ))
        for current, following in zip(episode_ids, episode_ids[1:]):
            next_episode[current] = following
    return episode_parent, episode_aliases, episode_catalog, next_episode


def watch_strength(row) -> float:
    position = max(0.0, as_float(row.get("position")))
    duration = max(0.0, as_float(row.get("duration")))
    if duration > 0:
        completion = min(position / duration, 1.0)
        return 0.25 + 2.0 * completion + (0.75 if completion >= 0.8 else 0.0)
    return 1.0 if position > 0 else 0.25


def find_max_watch_time(source) -> float:
    maximum = 0.0
    for row in source.rows("watch_position"):
        maximum = max(maximum, parse_time(row.get("updated_at") or row.get("created_at")))
    return maximum


def load_preferences(
    source,
    catalog,
    movie_aliases,
    episode_parent,
    episode_aliases,
    episode_catalog,
    max_time,
):
    # user -> item -> [accumulated strength, latest timestamp]
    preferences = defaultdict(dict)
    progress = defaultdict(dict)
    recent_items = defaultdict(dict)
    recent_episodes = defaultdict(dict)
    wishlists = defaultdict(dict)
    trending_users = defaultdict(set)
    trending_strength = Counter()
    stats = Counter()
    trending_cutoff = max_time - 30.0 * 86400.0

    for row in source.rows("watch_position"):
        user_id = clean(row.get("user_id"))
        raw_item_id = clean(row.get("movie_id"))
        item_id = raw_item_id
        kind = clean(row.get("movie_type"))
        episode_id = ""
        if kind == "episode":
            episode_id = episode_aliases.get(raw_item_id, "")
            item_id = episode_parent.get(raw_item_id, "")
        elif kind == "movie":
            item_id = movie_aliases.get(item_id, "")
        if not user_id or item_id not in catalog:
            stats["watch_skipped"] += 1
            continue
        timestamp = parse_time(row.get("updated_at") or row.get("created_at"))
        strength = watch_strength(row)
        previous = preferences[user_id].get(item_id)
        if previous:
            preferences[user_id][item_id] = [
                previous[0] + strength,
                max(previous[1], timestamp),
            ]
        else:
            preferences[user_id][item_id] = [strength, timestamp]
        recent_items[user_id][item_id] = max(
            recent_items[user_id].get(item_id, 0.0), timestamp
        )
        if episode_id:
            recent_episodes[user_id][episode_id] = max(
                recent_episodes[user_id].get(episode_id, 0.0), timestamp
            )

        position = max(0.0, as_float(row.get("position")))
        duration = max(0.0, as_float(row.get("duration")))
        completion = min(position / duration, 1.0) if duration > 0 else None
        progress_id = episode_id if kind == "episode" else item_id
        if progress_id:
            progress_key = f"{kind}:{progress_id}"
            previous_progress = progress[user_id].get(progress_key)
            if not previous_progress or timestamp >= previous_progress["updated_ts"]:
                progress[user_id][progress_key] = {
                    "type": kind,
                    "id": progress_id,
                    "parent_id": item_id,
                    "position": round(position, 3),
                    "duration": round(duration, 3),
                    "completion": round(completion, 6) if completion is not None else None,
                    "updated_ts": timestamp,
                }

        if timestamp >= trending_cutoff:
            trending_users[item_id].add(user_id)
            trending_strength[item_id] += strength
        stats["watch_used"] += 1

    for row in source.rows("wist_list"):
        user_id = clean(row.get("uid"))
        item_id = movie_aliases.get(clean(row.get("movie_id")), "")
        if not user_id or item_id not in catalog:
            stats["wishlist_skipped"] += 1
            continue
        timestamp = parse_time(row.get("updated_at") or row.get("created_at"))
        max_time = max(max_time, timestamp)
        previous = preferences[user_id].get(item_id)
        if previous:
            preferences[user_id][item_id] = [
                previous[0] + 3.0,
                max(previous[1], timestamp),
            ]
        else:
            preferences[user_id][item_id] = [3.0, timestamp]
        wishlists[user_id][item_id] = max(wishlists[user_id].get(item_id, 0.0), timestamp)
        stats["wishlist_used"] += 1

    half_life = 180.0 * 86400.0
    for history in preferences.values():
        for item_id, (strength, timestamp) in list(history.items()):
            age = max(0.0, max_time - timestamp) if timestamp else half_life
            recency = 0.65 + 0.35 * math.exp(-age / half_life)
            # Repeated episode watches strengthen a parent series without dominating.
            history[item_id] = round(min(5.0, 1.0 + math.log1p(strength)) * recency, 6)

    homepage_users = {}
    all_user_ids = set(preferences) | set(progress) | set(wishlists)
    for user_id in all_user_ids:
        incomplete = [
            entry for entry in progress.get(user_id, {}).values()
            if entry["position"] > 0
            and entry["completion"] is not None
            and entry["completion"] < 0.90
        ]
        incomplete.sort(key=lambda entry: entry["updated_ts"], reverse=True)
        recent = sorted(
            recent_items.get(user_id, {}).items(), key=lambda pair: pair[1], reverse=True
        )
        # Keep only the highest watched episode per series. This prevents a
        # shelf from suggesting Episode 2 and Episode 5 of the same series.
        series_progress = {}
        for episode_id, timestamp in recent_episodes.get(user_id, {}).items():
            episode = episode_catalog.get(episode_id)
            if not episode or not episode.get("parent_id"):
                continue
            parent_id = episode["parent_id"]
            order = (
                episode.get("season_number", 0),
                episode.get("episode_number", 0),
                timestamp,
            )
            previous = series_progress.get(parent_id)
            if not previous or order > previous[0]:
                series_progress[parent_id] = (order, episode_id, timestamp)
        recent_eps = sorted(
            [(value[1], value[2]) for value in series_progress.values()],
            key=lambda pair: pair[1],
            reverse=True,
        )
        saved = sorted(
            wishlists.get(user_id, {}).items(), key=lambda pair: pair[1], reverse=True
        )
        homepage_users[user_id] = {
            "continue_watching": incomplete[:20],
            "recent_items": recent[:20],
            "recent_episodes": recent_eps[:20],
            "wishlist": [item_id for item_id, _ in saved[:50]],
        }

    trending_raw = {
        item_id: 2.0 * math.log1p(len(viewers)) + math.log1p(trending_strength[item_id])
        for item_id, viewers in trending_users.items()
    }
    trending_max = max(trending_raw.values(), default=1.0) or 1.0
    trending = [
        [item_id, round(score / trending_max, 6), len(trending_users[item_id])]
        for item_id, score in trending_raw.items()
    ]
    trending.sort(key=lambda entry: entry[1], reverse=True)
    return preferences, homepage_users, trending, stats


def build_content_vectors(raw_terms, item_ids):
    document_frequency = Counter()
    for terms in raw_terms.values():
        document_frequency.update(terms.keys())

    document_count = len(item_ids)
    vectors = {}
    for item_id in item_ids:
        weighted = {}
        for token, frequency in raw_terms[item_id].items():
            # Terms occurring in almost every item add noise rather than meaning.
            if document_frequency[token] > document_count * 0.65:
                continue
            idf = math.log((document_count + 1) / (document_frequency[token] + 1)) + 1.0
            weighted[token] = (1.0 + math.log(frequency)) * idf
        strongest = sorted(weighted.items(), key=lambda pair: pair[1], reverse=True)[:160]
        norm = math.sqrt(sum(weight * weight for _, weight in strongest)) or 1.0
        vectors[item_id] = [[token, round(weight / norm, 6)] for token, weight in strongest]
    return vectors


def build_collaborative_neighbors(preferences, item_ids, history_cap=30, neighbor_cap=80):
    index = {item_id: number for number, item_id in enumerate(item_ids)}
    size = len(item_ids)
    cooccurrence = array("I", [0]) * (size * size)
    user_count = array("I", [0]) * size
    popularity = [0.0] * size

    for history in preferences.values():
        strongest = sorted(history.items(), key=lambda pair: pair[1], reverse=True)[:history_cap]
        numbers = [index[item_id] for item_id, _ in strongest if item_id in index]
        for item_id, strength in strongest:
            if item_id in index:
                number = index[item_id]
                user_count[number] += 1
                popularity[number] += strength
        for offset, left in enumerate(numbers):
            base = left * size
            for right in numbers[offset + 1 :]:
                cooccurrence[base + right] += 1
                cooccurrence[right * size + left] += 1

    neighbors = {}
    for left, item_id in enumerate(item_ids):
        candidates = []
        if user_count[left]:
            base = left * size
            for right in range(size):
                common = cooccurrence[base + right]
                if not common or not user_count[right]:
                    continue
                cosine = common / math.sqrt(user_count[left] * user_count[right])
                similarity = cosine * common / (common + 5.0)
                candidates.append((item_ids[right], similarity, common))
        candidates.sort(key=lambda value: value[1], reverse=True)
        neighbors[item_id] = [
            [other, round(similarity, 6), common]
            for other, similarity, common in candidates[:neighbor_cap]
        ]

    maximum = max(popularity, default=1.0) or 1.0
    popularity_scores = {
        item_ids[number]: round(math.log1p(score) / math.log1p(maximum), 6)
        for number, score in enumerate(popularity)
    }
    return neighbors, popularity_scores


def train(source, output: Path, source_name="csv"):
    if isinstance(source, Path):
        source = CsvDataSource(source)
    source.validate()

    catalog, movie_aliases, raw_terms = load_catalog(source)
    episode_parent, episode_aliases, episode_catalog, next_episode = load_episode_data(
        source, movie_aliases
    )
    max_time = find_max_watch_time(source)
    preferences, homepage_users, trending, stats = load_preferences(
        source,
        catalog,
        movie_aliases,
        episode_parent,
        episode_aliases,
        episode_catalog,
        max_time,
    )
    item_ids = sorted(catalog)
    vectors = build_content_vectors(raw_terms, item_ids)
    neighbors, popularity = build_collaborative_neighbors(preferences, item_ids)

    model = {
        "version": 5,
        "algorithm": "implicit-collaborative+tfidf-content+popularity",
        "source": source_name,
        "trained_at": datetime.now(timezone.utc).isoformat(),
        "source_max_time": datetime.fromtimestamp(max_time, timezone.utc).isoformat()
        if max_time else None,
        "stats": {
            **stats,
            "catalog_items": len(catalog),
            "users_with_preferences": len(preferences),
            "user_item_pairs": sum(len(history) for history in preferences.values()),
        },
        "catalog": catalog,
        "episodes": episode_catalog,
        "next_episode": next_episode,
        "content_vectors": vectors,
        "neighbors": neighbors,
        "popularity": popularity,
        "trending": trending,
        "homepage_users": homepage_users,
        "users": {
            user_id: sorted(history.items(), key=lambda pair: pair[1], reverse=True)[:100]
            for user_id, history in preferences.items()
        },
    }

    output.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=output.name + ".", suffix=".tmp", dir=output.parent
    )
    os.close(descriptor)
    try:
        with gzip.open(temporary_name, "wt", encoding="utf-8", compresslevel=6) as handle:
            json.dump(model, handle, ensure_ascii=False, separators=(",", ":"))
        os.replace(temporary_name, output)
    finally:
        if os.path.exists(temporary_name):
            os.unlink(temporary_name)
    return model


def load_model(path: Path):
    with gzip.open(path, "rt", encoding="utf-8") as handle:
        return json.load(handle)


def recommendation_weights(history_size: int):
    if history_size == 0:
        return 0.0, 0.0, 1.0
    if history_size < 3:
        return 0.15, 0.55, 0.30
    if history_size < 5:
        return 0.30, 0.45, 0.25
    return 0.50, 0.35, 0.15


def near_duplicate_title(title: str, cards) -> bool:
    normalized = clean(title).casefold()
    tokens = set(tokenize(title))
    for card in cards:
        other_title = clean(card.get("title")).casefold()
        if normalized == other_title:
            return True
        other_tokens = set(tokenize(other_title))
        smaller = min(len(tokens), len(other_tokens))
        if smaller >= 3 and len(tokens & other_tokens) / smaller >= 0.75:
            return True
    return False


def unique_movie_cards(cards, limit: int, existing=()):
    accepted = []
    comparison = list(existing)
    for card in cards:
        if not card or near_duplicate_title(card.get("title", ""), comparison):
            continue
        accepted.append(card)
        comparison.append(card)
        if len(accepted) >= limit:
            break
    return accepted


def content_allowed(item, mode="adult", include_age_restricted=False):
    if not item or clean(item.get("status")).casefold() != "published":
        return False
    if mode == "kids":
        return bool(item.get("child_mode")) and not bool(item.get("age_restricted"))
    return include_age_restricted or not bool(item.get("age_restricted"))


def recommend(model, user_id: str, limit=10, mode="adult", include_age_restricted=False, history_pairs=None):
    catalog = model["catalog"]
    history = dict(model["users"].get(user_id, []) if history_pairs is None else history_pairs)
    seen = set(history)
    collaborative = defaultdict(float)
    for source, preference in history.items():
        for target, similarity, _support in model["neighbors"].get(source, []):
            if target not in seen:
                collaborative[target] += preference * similarity
    collab_max = max(collaborative.values(), default=1.0) or 1.0

    profile = defaultdict(float)
    for item_id, preference in history.items():
        for token, weight in model["content_vectors"].get(item_id, []):
            profile[token] += preference * weight
    profile_norm = math.sqrt(sum(value * value for value in profile.values())) or 1.0
    for token in profile:
        profile[token] /= profile_norm

    content_scores = {}
    for item_id, vector in model["content_vectors"].items():
        if item_id not in seen:
            content_scores[item_id] = sum(profile.get(token, 0.0) * weight for token, weight in vector)
    content_max = max(content_scores.values(), default=1.0) or 1.0

    cw, tw, pw = recommendation_weights(len(history))
    ranked = []
    for item_id, item in catalog.items():
        if item_id in seen or not content_allowed(item, mode, include_age_restricted):
            continue
        component = {
            "behavior": collaborative.get(item_id, 0.0) / collab_max,
            "content": content_scores.get(item_id, 0.0) / content_max,
            "popularity": model["popularity"].get(item_id, 0.0),
        }
        contribution = {
            "behavior": cw * component["behavior"],
            "content": tw * component["content"],
            "popularity": pw * component["popularity"],
        }
        score = sum(contribution.values())
        reason_key = max(contribution, key=contribution.get)
        reason = {
            "behavior": "Users with similar watch history",
            "content": "Similar to watched genres and content",
            "popularity": "Popular with Zo Stream viewers",
        }[reason_key]
        ranked.append({
            "id": item_id,
            "title": item["title"],
            "status": "Published",
            "genre": item["genre"],
            "poster": item["poster"],
            "premium": item["premium"],
            "ppv": item["ppv"],
            "score": round(score, 6),
            "reason": reason,
            "components": {key: round(value, 6) for key, value in component.items()},
        })
    ranked.sort(key=lambda item: item["score"], reverse=True)
    return unique_movie_cards(ranked, limit)


def movie_card(model, item_id: str, mode="adult", include_age_restricted=False, **extra):
    item = model["catalog"].get(item_id)
    if not content_allowed(item, mode, include_age_restricted):
        return None
    card = {
        "id": item_id,
        "title": item["title"],
        "status": "Published",
        "genre": item["genre"],
        "poster": item["poster"],
        "premium": item["premium"],
        "ppv": item["ppv"],
    }
    card.update(extra)
    return card


def episode_card(model, episode_id: str, mode="adult", include_age_restricted=False, **extra):
    episode = model.get("episodes", {}).get(episode_id)
    if not episode or clean(episode.get("status")).casefold() != "published":
        return None
    parent = model["catalog"].get(episode["parent_id"], {})
    if not content_allowed(parent, mode, include_age_restricted):
        return None
    card = {
        "id": episode_id,
        "legacy_id": episode.get("legacy_id", ""),
        "parent_id": episode["parent_id"],
        "series_title": parent.get("title", ""),
        "title": episode["title"],
        "status": "Published",
        "season_number": episode["season_number"],
        "episode_number": episode["episode_number"],
        "thumbnail": episode["thumbnail"],
        "premium": parent.get("premium", False),
        "ppv": parent.get("ppv", False),
    }
    card.update(extra)
    return card


def similar_items(
    model,
    anchor_id: str,
    limit=10,
    exclude=None,
    collaborative_weight=0.60,
    mode="adult",
    include_age_restricted=False,
):
    if anchor_id not in model["catalog"]:
        return []
    exclude = set(exclude or ()) | {anchor_id}
    neighbor_scores = {
        item_id: similarity
        for item_id, similarity, _support in model["neighbors"].get(anchor_id, [])
    }
    vector = dict(model["content_vectors"].get(anchor_id, []))
    content_scores = {}
    for item_id, candidate in model["content_vectors"].items():
        if item_id not in exclude:
            content_scores[item_id] = sum(
                vector.get(token, 0.0) * weight for token, weight in candidate
            )
    content_weight = 1.0 - collaborative_weight
    ranked = []
    for item_id in model["catalog"]:
        if item_id in exclude:
            continue
        behavior = neighbor_scores.get(item_id, 0.0)
        content = content_scores.get(item_id, 0.0)
        score = collaborative_weight * behavior + content_weight * content
        reason = (
            "Watched by viewers with similar taste"
            if collaborative_weight * behavior >= content_weight * content
            else "Similar genre and content"
        )
        card = movie_card(
            model,
            item_id,
            mode=mode,
            include_age_restricted=include_age_restricted,
            score=round(score, 6),
            reason=reason,
        )
        if card:
            ranked.append(card)
    ranked.sort(key=lambda item: item["score"], reverse=True)
    return unique_movie_cards(ranked, limit)


def build_live_user_context(model, live_data):
    episode_aliases = {}
    episode_parent = {}
    for episode_id, episode in model.get("episodes", {}).items():
        episode_aliases[episode_id] = episode_id
        episode_parent[episode_id] = episode.get("parent_id", "")
        legacy_id = clean(episode.get("legacy_id"))
        if legacy_id:
            episode_aliases[legacy_id] = episode_id
            episode_parent[legacy_id] = episode.get("parent_id", "")

    preference_rows = {}
    progress = {}
    recent_items = {}
    recent_episodes = {}
    timestamps = []
    for row in live_data.get("watch_position", []):
        raw_id = clean(row.get("movie_id"))
        kind = clean(row.get("movie_type")).casefold()
        episode_id = episode_aliases.get(raw_id, "") if kind == "episode" else ""
        item_id = episode_parent.get(raw_id, "") if kind == "episode" else raw_id
        if item_id not in model["catalog"]:
            continue
        timestamp = parse_time(row.get("updated_at") or row.get("created_at"))
        timestamps.append(timestamp)
        strength = watch_strength(row)
        previous = preference_rows.get(item_id, [0.0, 0.0])
        preference_rows[item_id] = [previous[0] + strength, max(previous[1], timestamp)]
        recent_items[item_id] = max(recent_items.get(item_id, 0.0), timestamp)
        if episode_id:
            recent_episodes[episode_id] = max(recent_episodes.get(episode_id, 0.0), timestamp)

        position = max(0.0, as_float(row.get("position")))
        duration = max(0.0, as_float(row.get("duration")))
        completion = min(position / duration, 1.0) if duration else None
        progress_id = episode_id if kind == "episode" else item_id
        if progress_id:
            key = f"{kind}:{progress_id}"
            if key not in progress or timestamp >= progress[key]["updated_ts"]:
                progress[key] = {
                    "type": kind or "movie", "id": progress_id, "parent_id": item_id,
                    "position": round(position, 3), "duration": round(duration, 3),
                    "completion": round(completion, 6) if completion is not None else None,
                    "updated_ts": timestamp,
                }

    wishlist = []
    for row in live_data.get("wishlist", []):
        item_id = clean(row.get("movie_id"))
        if item_id not in model["catalog"]:
            continue
        timestamp = parse_time(row.get("updated_at") or row.get("created_at"))
        timestamps.append(timestamp)
        previous = preference_rows.get(item_id, [0.0, 0.0])
        preference_rows[item_id] = [previous[0] + 3.0, max(previous[1], timestamp)]
        if item_id not in wishlist:
            wishlist.append(item_id)

    maximum = max(timestamps, default=0.0)
    half_life = 180.0 * 86400.0
    history = []
    for item_id, (strength, timestamp) in preference_rows.items():
        age = max(0.0, maximum - timestamp) if timestamp else half_life
        recency = 0.65 + 0.35 * math.exp(-age / half_life)
        history.append([item_id, round(min(5.0, 1.0 + math.log1p(strength)) * recency, 6)])
    history.sort(key=lambda pair: pair[1], reverse=True)

    incomplete = [entry for entry in progress.values() if entry["position"] > 0 and entry["completion"] is not None and entry["completion"] < 0.90]
    incomplete.sort(key=lambda entry: entry["updated_ts"], reverse=True)
    recent = sorted(recent_items.items(), key=lambda pair: pair[1], reverse=True)
    recent_eps = sorted(recent_episodes.items(), key=lambda pair: pair[1], reverse=True)
    return history[:100], {
        "continue_watching": incomplete[:100], "recent_items": recent[:100],
        "recent_episodes": recent_eps[:100], "wishlist": wishlist[:200],
    }


def homepage(model, user_id: str, limit=10, mode="adult", include_age_restricted=False, live_data=None):
    limit = max(1, limit)
    if live_data is not None:
        history_pairs, shelf_data = build_live_user_context(model, live_data)
    else:
        shelf_data = model.get("homepage_users", {}).get(user_id, {})
        history_pairs = model["users"].get(user_id, [])
    seen = {item_id for item_id, _preference in history_pairs}

    continue_watching = []
    continue_movies = []
    for entry in shelf_data.get("continue_watching", []):
        updated_at = (
            datetime.fromtimestamp(entry["updated_ts"], timezone.utc).isoformat()
            if entry["updated_ts"] else None
        )
        progress_fields = {
            "position": entry["position"],
            "duration": entry["duration"],
            "completion": entry["completion"],
            "updated_at": updated_at,
        }
        if entry["type"] == "episode":
            card = episode_card(
                model,
                entry["id"],
                mode=mode,
                include_age_restricted=include_age_restricted,
                **progress_fields,
            )
        else:
            card = movie_card(
                model,
                entry["id"],
                mode=mode,
                include_age_restricted=include_age_restricted,
                **progress_fields,
            )
            if card and near_duplicate_title(card["title"], continue_movies):
                continue
        if card:
            continue_watching.append(card)
            if entry["type"] != "episode":
                continue_movies.append(card)
        if len(continue_watching) >= limit:
            break

    top_picks = recommend(model, user_id, limit, mode, include_age_restricted, history_pairs)
    used = {item["id"] for item in top_picks}
    recent_items = [item_id for item_id, _timestamp in shelf_data.get("recent_items", [])]
    because_anchor = recent_items[0] if recent_items else ""
    because_candidates = similar_items(
        model,
        because_anchor,
        limit * 4,
        exclude=seen | used,
        collaborative_weight=0.65,
        mode=mode,
        include_age_restricted=include_age_restricted,
    )
    because_items = unique_movie_cards(because_candidates, limit, top_picks)
    used.update(item["id"] for item in because_items)

    favourite_anchor = next(
        (item_id for item_id, _preference in history_pairs if item_id != because_anchor),
        because_anchor,
    )
    similar_candidates = similar_items(
        model,
        favourite_anchor,
        limit * 4,
        exclude=seen | used,
        collaborative_weight=0.20,
        mode=mode,
        include_age_restricted=include_age_restricted,
    )
    similar_movies = unique_movie_cards(
        similar_candidates, limit, top_picks + because_items
    )
    used.update(item["id"] for item in similar_movies)
    discovery_cards = top_picks + because_items + similar_movies

    trending_now = []
    for item_id, score, unique_viewers in model.get("trending", []):
        if item_id in used or item_id in seen:
            continue
        card = movie_card(
            model,
            item_id,
            mode=mode,
            include_age_restricted=include_age_restricted,
            score=score,
            unique_viewers_30d=unique_viewers,
        )
        if card and not near_duplicate_title(card["title"], discovery_cards + trending_now):
            trending_now.append(card)
        if len(trending_now) >= limit:
            break
    used.update(item["id"] for item in trending_now)

    new_releases = []
    release_order = sorted(
        model["catalog"],
        key=lambda item_id: (
            parse_date(model["catalog"][item_id].get("release_on")),
            parse_date(model["catalog"][item_id].get("created_on")),
        ),
        reverse=True,
    )
    for item_id in release_order:
        if item_id in used or item_id in seen:
            continue
        item = model["catalog"][item_id]
        card = movie_card(
            model,
            item_id,
            mode=mode,
            include_age_restricted=include_age_restricted,
            release_on=item.get("release_on", ""),
        )
        if card and not near_duplicate_title(
            card["title"], discovery_cards + trending_now + new_releases
        ):
            new_releases.append(card)
        if len(new_releases) >= limit:
            break

    wishlist = []
    for item_id in shelf_data.get("wishlist", []):
        card = movie_card(
            model,
            item_id,
            mode=mode,
            include_age_restricted=include_age_restricted,
        )
        if card:
            wishlist.append(card)
        if len(wishlist) >= limit:
            break

    next_episodes = []
    added_episodes = set()
    watched_episode_ids = {
        episode_id for episode_id, _timestamp in shelf_data.get("recent_episodes", [])
    }
    for watched_episode, _timestamp in shelf_data.get("recent_episodes", []):
        next_id = model.get("next_episode", {}).get(watched_episode)
        if not next_id or next_id in added_episodes or next_id in watched_episode_ids:
            continue
        card = episode_card(
            model,
            next_id,
            mode=mode,
            include_age_restricted=include_age_restricted,
            reason="Next unwatched episode",
        )
        if card:
            next_episodes.append(card)
            added_episodes.add(next_id)
        if len(next_episodes) >= limit:
            break

    return {
        "user": user_id or "anonymous",
        "history_size": len(history_pairs),
        "continue_watching": continue_watching,
        "because_you_watched": {
            "anchor": movie_card(
                model,
                because_anchor,
                mode=mode,
                include_age_restricted=include_age_restricted,
            ),
            "items": because_items,
        },
        "top_picks_for_you": top_picks,
        "similar_movies": {
            "anchor": movie_card(
                model,
                favourite_anchor,
                mode=mode,
                include_age_restricted=include_age_restricted,
            ),
            "items": similar_movies,
        },
        "trending_now": trending_now,
        "new_releases": new_releases,
        "your_wishlist": wishlist,
        "next_episode": next_episodes,
    }


def main():
    parser = argparse.ArgumentParser(description="Train or query the Zo Stream hybrid recommender")
    subparsers = parser.add_subparsers(dest="command", required=True)

    train_parser = subparsers.add_parser("train")
    train_parser.add_argument("--source", choices=("csv", "mysql", "sql-dump"), default="csv")
    train_parser.add_argument("--data-dir", type=Path, default=Path(__file__).resolve().parent.parent)
    train_parser.add_argument("--dump-file", type=Path)
    train_parser.add_argument("--output", type=Path, default=Path(__file__).resolve().parent / "artifacts" / "hybrid_model.json.gz")

    recommend_parser = subparsers.add_parser("recommend")
    recommend_parser.add_argument("--model", type=Path, default=Path(__file__).resolve().parent / "artifacts" / "hybrid_model.json.gz")
    recommend_parser.add_argument("--user-id", default="")
    recommend_parser.add_argument("--limit", type=int, default=10)
    recommend_parser.add_argument("--demo", action="store_true", help="Use a trained user without printing their ID")

    homepage_parser = subparsers.add_parser("homepage")
    homepage_parser.add_argument("--model", type=Path, default=Path(__file__).resolve().parent / "artifacts" / "hybrid_model.json.gz")
    homepage_parser.add_argument("--user-id", default="")
    homepage_parser.add_argument("--limit", type=int, default=10)
    homepage_parser.add_argument("--mode", choices=("adult", "kids"), default="adult")
    homepage_parser.add_argument("--include-age-restricted", action="store_true")
    homepage_parser.add_argument("--live-data-stdin", action="store_true")
    homepage_parser.add_argument("--demo", action="store_true", help="Use a trained user without printing their ID")

    args = parser.parse_args()
    if args.command == "train":
        if args.source == "mysql":
            source = MySqlDataSource()
        elif args.source == "sql-dump":
            if args.dump_file is None:
                parser.error("train --source sql-dump requires --dump-file")
            source = MySqlDumpDataSource(args.dump_file.resolve())
        else:
            source = CsvDataSource(args.data_dir.resolve())
        try:
            model = train(source, args.output.resolve(), args.source)
        finally:
            source.close()
        print(json.dumps({"output": str(args.output.resolve()), **model["stats"]}, indent=2))
    elif args.command == "recommend":
        model = load_model(args.model.resolve())
        user_id = args.user_id
        if args.demo:
            user_id = next((uid for uid, history in model["users"].items() if len(history) >= 5), "")
        result = {
            "user": "[redacted demo user]" if args.demo else (user_id or "anonymous"),
            "history_size": len(model["users"].get(user_id, [])),
            "recommendations": recommend(model, user_id, max(1, args.limit)),
        }
        print(json.dumps(result, ensure_ascii=False, indent=2))
    else:
        model = load_model(args.model.resolve())
        user_id = args.user_id
        if args.demo:
            user_id = next((
                uid for uid, data in model.get("homepage_users", {}).items()
                if data.get("recent_items")
            ), "")
        live_data = json.load(sys.stdin) if args.live_data_stdin else None
        result = homepage(model, user_id, args.limit, args.mode, args.include_age_restricted, live_data)
        if args.demo:
            result["user"] = "[redacted demo user]"
        print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
