export type SidebarSearchItem = {
  title: string;
  href: string;
};

const tokenAliases: Record<string, readonly string[]> = {
  add: ["add", "create", "new", "insert"],
  create: ["create", "add", "new", "insert"],
  new: ["new", "add", "create", "insert"],
  edit: ["edit", "update", "change", "modify"],
  update: ["update", "edit", "change", "modify"],
  change: ["change", "edit", "update", "modify"],
  modify: ["modify", "edit", "update", "change"],
  delete: ["delete", "remove", "suspend", "clear"],
  remove: ["remove", "delete", "suspend", "clear"],
  clear: ["clear", "remove", "delete"],
  movie: ["movie", "film", "video"],
  film: ["film", "movie", "video"],
  video: ["video", "movie", "film"],
  season: ["season", "series"],
  series: ["series", "season"],
  episode: ["episode", "ep"],
  ep: ["ep", "episode"],
  subscription: ["subscription", "subscriber", "plan", "member"],
  subscriber: ["subscriber", "subscription", "member", "user"],
  member: ["member", "subscriber", "subscription", "user"],
  notification: ["notification", "notify", "alert", "message"],
  alert: ["alert", "notification", "notify", "message"],
  message: ["message", "notification", "alert"],
  scrolling: ["scrolling", "scroll", "ticker"],
  scroll: ["scroll", "scrolling", "ticker"],
  banner: ["banner", "image", "poster"],
  image: ["image", "banner", "poster"],
  device: ["device", "phone", "mobile", "tv", "browser"],
  phone: ["phone", "device", "mobile"],
  poll: ["poll", "vote", "survey"],
  vote: ["vote", "poll", "survey"],
  result: ["result", "results", "answer"],
  results: ["results", "result", "answer"],
};

function tokenize(value: string) {
  return value
    .toLowerCase()
    .replace(/['’]/g, "")
    .split(/[^a-z0-9]+/)
    .filter(Boolean);
}

function isSubsequence(needle: string, haystack: string) {
  if (!needle) return true;

  let index = 0;

  for (const char of haystack) {
    if (char === needle[index]) {
      index += 1;
      if (index === needle.length) return true;
    }
  }

  return false;
}

function tokenMatches(queryToken: string, haystackTokens: readonly string[]) {
  const aliases = tokenAliases[queryToken] ?? [queryToken];

  return aliases.some((alias) =>
    haystackTokens.some(
      (token) =>
        token === alias ||
        token.includes(alias) ||
        alias.includes(token) ||
        isSubsequence(alias, token),
    ),
  );
}

export function sidebarItemMatchesQuery(
  item: SidebarSearchItem,
  query: string,
  extraKeywords: readonly string[] = [],
) {
  const queryTokens = tokenize(query);
  if (queryTokens.length === 0) return true;

  const hrefKeywords = item.href.replace(/^\//, "").replaceAll("/", " ");
  const haystackTokens = tokenize(
    [item.title, hrefKeywords, ...extraKeywords].join(" "),
  );

  return queryTokens.every((token) => tokenMatches(token, haystackTokens));
}

export function filterSidebarItems<T extends SidebarSearchItem>(
  items: readonly T[],
  query: string,
  extraKeywords: readonly string[] = [],
) {
  const trimmedQuery = query.trim();
  if (!trimmedQuery) return [...items];

  return items.filter((item) =>
    sidebarItemMatchesQuery(item, trimmedQuery, extraKeywords),
  );
}
