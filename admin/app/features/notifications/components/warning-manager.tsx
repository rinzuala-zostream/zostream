"use client";

import {
  useActionState,
  useEffect,
  useRef,
  useState,
  useTransition,
} from "react";
import {
  Bell,
  Ellipsis,
  Eye,
  Megaphone,
  RotateCcw,
  Save,
  Trash2,
  TriangleAlert,
  Wrench,
} from "lucide-react";
import { useRouter } from "next/navigation";
import { toast } from "react-toastify";
import {
  deleteWarningAction,
  saveWarningAction,
  type WarningFormState,
} from "@/app/(admin)/notifications/warning/add/actions";
import {
  warningCategories,
  type WarningCategory,
  type WarningConfig,
} from "@/app/features/notifications/warning-types";
import { cn } from "@/lib/utils";

type WarningManagerProps = {
  warning: WarningConfig | null;
};

type NoticeFields = {
  title: string;
  subtitle: string;
  buttonLabel: string;
  buttonUrl: string;
  supportPrefix: string;
  supportPhone: string;
  statusText: string;
  hintText: string;
  teamName: string;
};

const initialState: WarningFormState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "h-11 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-3 text-sm font-semibold text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const controlCardClassName =
  "flex min-h-24 min-w-0 flex-col justify-between gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-3 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition dark:border-white/10 dark:bg-white/6";
const controlLabelClassName =
  "text-sm font-semibold text-slate-800 dark:text-slate-100";

const categoryOptions: Array<{
  value: WarningCategory;
  label: string;
  description: string;
  icon: typeof TriangleAlert;
  badge: string;
  activeClassName: string;
}> = [
  {
    value: "warning",
    label: "Warning",
    description: "Urgent app warning",
    icon: TriangleAlert,
    badge: "Important Warning",
    activeClassName:
      "border-amber-300 bg-amber-50/80 text-amber-900 dark:border-amber-300/25 dark:bg-amber-300/12 dark:text-amber-100",
  },
  {
    value: "announcement",
    label: "Announcement",
    description: "News and updates",
    icon: Megaphone,
    badge: "Announcement",
    activeClassName:
      "border-sky-300 bg-sky-50/80 text-sky-900 dark:border-sky-300/25 dark:bg-sky-300/12 dark:text-sky-100",
  },
  {
    value: "maintenance",
    label: "Maintenance",
    description: "Downtime notices",
    icon: Wrench,
    badge: "Maintenance Notice",
    activeClassName:
      "border-violet-300 bg-violet-50/80 text-violet-900 dark:border-violet-300/25 dark:bg-violet-300/12 dark:text-violet-100",
  },
  {
    value: "others",
    label: "Others",
    description: "General messages",
    icon: Ellipsis,
    badge: "Zo Stream Notice",
    activeClassName:
      "border-teal-300 bg-teal-50/80 text-teal-900 dark:border-cyan-300/25 dark:bg-cyan-300/12 dark:text-cyan-100",
  },
];

const defaultNoticeFields: NoticeFields = {
  title: "Zo Stream App Maintenance Kalpui Mek A Ni",
  subtitle:
    "Zo Stream Android app-ah maintenance kalpui mek a ni. Hemi hun chhung hian app hmangin streaming a buaithlak thei a, chuvangin browser hmangin play.zostream.in atangin Zo Stream hmang chhunzawm turin kan ngen a che.",
  buttonLabel: "Open Zo Stream in Browser",
  buttonUrl: "https://play.zostream.in",
  supportPrefix: "Support mamawh chuan:",
  supportPhone: "8837076347",
  statusText: "Status: Mobile App Maintenance",
  hintText: "In hriatthiamna avangin kan lawm e.",
  teamName: "Zo Stream Team",
};

function decodeSavedHtml(value: string) {
  return value
    .replaceAll("&lt;", "<")
    .replaceAll("&gt;", ">")
    .replaceAll("&quot;", '"')
    .replaceAll("&#039;", "'")
    .replaceAll("&#096;", "`")
    .replaceAll("&amp;", "&");
}

function savedHtmlMatch(html: string, pattern: RegExp) {
  const match = html.match(pattern);
  return match ? decodeSavedHtml(match[1].trim()) : null;
}

function editorValuesFromWarning(warning: WarningConfig | null) {
  if (!warning?.txt) {
    return {
      category: "warning" as WarningCategory,
      fields: defaultNoticeFields,
    };
  }

  const html = warning.txt;
  const categoryMatch = html.match(
    /class=["']notify-card\s+(warning|announcement|maintenance|others)["']/i,
  );
  const category = warningCategories.includes(
    categoryMatch?.[1] as WarningCategory,
  )
    ? (categoryMatch?.[1] as WarningCategory)
    : "warning";

  return {
    category,
    fields: {
      title:
        savedHtmlMatch(html, /class=["']title["'][^>]*>([\s\S]*?)<\/h2>/i) ??
        defaultNoticeFields.title,
      subtitle:
        savedHtmlMatch(
          html,
          /class=["']subtitle["'][^>]*>([\s\S]*?)<\/p>/i,
        ) ?? defaultNoticeFields.subtitle,
      buttonLabel:
        savedHtmlMatch(
          html,
          /class=["']web-link["'][^>]*>([\s\S]*?)<\/a>/i,
        ) ?? defaultNoticeFields.buttonLabel,
      buttonUrl:
        savedHtmlMatch(
          html,
          /class=["']web-link["'][^>]*href=["']([^"']*)["']/i,
        ) ?? defaultNoticeFields.buttonUrl,
      supportPrefix:
        savedHtmlMatch(
          html,
          /class=["']support-text["'][^>]*>([\s\S]*?)\s*<strong>/i,
        ) ?? defaultNoticeFields.supportPrefix,
      supportPhone:
        savedHtmlMatch(
          html,
          /class=["']support-text["'][^>]*>[\s\S]*?<strong>([\s\S]*?)<\/strong>/i,
        ) ?? defaultNoticeFields.supportPhone,
      statusText:
        savedHtmlMatch(
          html,
          /class=["']mini-pill["'][^>]*>[\s\S]*?<\/span>([\s\S]*?)<\/div>/i,
        ) ?? defaultNoticeFields.statusText,
      hintText:
        savedHtmlMatch(
          html,
          /class=["']hint["'][^>]*>([\s\S]*?)<br\s*\/?>/i,
        ) ?? defaultNoticeFields.hintText,
      teamName:
        savedHtmlMatch(
          html,
          /class=["']hint["'][^>]*>[\s\S]*?<strong>([\s\S]*?)<\/strong>/i,
        ) ?? defaultNoticeFields.teamName,
    },
  };
}

function escapeHtml(value: string) {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function escapeAttribute(value: string) {
  return escapeHtml(value).replaceAll("`", "&#096;");
}

function templateTheme(category: WarningCategory) {
  switch (category) {
    case "announcement":
      return {
        accent: "#38bdf8",
        accentSoft: "rgba(56, 189, 248, 0.15)",
        pulse: "rgba(56, 189, 248, 0.45)",
        cardBg: "#082f49",
        border: "#075985",
        surface: "#0c4a6e",
      };
    case "maintenance":
      return {
        accent: "#a78bfa",
        accentSoft: "rgba(167, 139, 250, 0.15)",
        pulse: "rgba(167, 139, 250, 0.45)",
        cardBg: "#1e1b4b",
        border: "#4c1d95",
        surface: "#312e81",
      };
    case "others":
      return {
        accent: "#2dd4bf",
        accentSoft: "rgba(45, 212, 191, 0.15)",
        pulse: "rgba(45, 212, 191, 0.45)",
        cardBg: "#042f2e",
        border: "#0f766e",
        surface: "#134e4a",
      };
    case "warning":
    default:
      return {
        accent: "#f59e0b",
        accentSoft: "rgba(245, 158, 11, 0.16)",
        pulse: "rgba(245, 158, 11, 0.45)",
        cardBg: "#1c1917",
        border: "#78350f",
        surface: "#292524",
      };
  }
}

function buildNoticeHtml(
  category: WarningCategory,
  option: (typeof categoryOptions)[number],
  fields: NoticeFields,
) {
  const theme = templateTheme(category);
  const title = escapeHtml(fields.title);
  const subtitle = escapeHtml(fields.subtitle);
  const buttonLabel = escapeHtml(fields.buttonLabel);
  const buttonUrl = escapeAttribute(fields.buttonUrl);
  const supportPrefix = escapeHtml(fields.supportPrefix);
  const supportPhone = escapeHtml(fields.supportPhone);
  const statusText = escapeHtml(fields.statusText);
  const hintText = escapeHtml(fields.hintText);
  const teamName = escapeHtml(fields.teamName);
  const badge = escapeHtml(option.badge);

  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Zo Stream - ${badge}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    :root {
      --accent: ${theme.accent};
      --accent-soft: ${theme.accentSoft};
      --pulse: ${theme.pulse};
      --text-main: #e5e7eb;
      --text-muted: #9ca3af;
      --border: ${theme.border};
      --card-bg: ${theme.cardBg};
      --surface: ${theme.surface};
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh;
      background: transparent;
      color: var(--text-main);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }
    .notify-card {
      width: 100%;
      max-width: 390px;
      background: var(--card-bg);
      border-radius: ${category === "announcement" ? "24px" : category === "warning" ? "16px" : "18px"};
      border: 1px solid var(--border);
      padding: ${category === "others" ? "18px" : "20px"};
      box-shadow: 0 18px 40px rgba(0, 0, 0, 0.45);
      animation: slideUp 0.5s ease;
    }
    @keyframes slideUp {
      from { transform: translateY(25px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 11px;
      border-radius: 999px;
      font-size: 11px;
      text-transform: uppercase;
      background: var(--accent-soft);
      color: var(--accent);
      margin-bottom: 12px;
      font-weight: 700;
      letter-spacing: .04em;
    }
    .badge-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--accent);
      animation: pulse 1.8s infinite;
    }
    @keyframes pulse {
      0% { box-shadow: 0 0 0 0 var(--pulse); }
      70% { box-shadow: 0 0 0 10px transparent; }
      100% { box-shadow: 0 0 0 0 transparent; }
    }
    .title {
      font-size: ${category === "announcement" ? "21px" : "19px"};
      font-weight: 700;
      margin-bottom: 8px;
      line-height: 1.35;
    }
    .subtitle {
      font-size: 13px;
      color: var(--text-muted);
      line-height: 1.7;
      margin-bottom: 14px;
    }
    .web-link {
      display: block;
      width: 100%;
      text-align: center;
      text-decoration: none;
      color: #020617;
      background: var(--accent);
      padding: 10px 14px;
      border-radius: 12px;
      font-size: 13px;
      font-weight: 700;
      margin-bottom: 12px;
    }
    .support-text {
      text-align: center;
      font-size: 12px;
      color: var(--text-muted);
      margin-bottom: 12px;
      line-height: 1.5;
    }
    .support-text strong,
    .hint strong {
      color: #f8fafc;
      font-weight: 700;
    }
    .mini-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 10px;
      border-radius: 999px;
      font-size: 11px;
      background: var(--surface);
      border: 1px solid rgba(148, 163, 184, 0.25);
      color: var(--text-muted);
    }
    .dot-blue {
      width: 7px;
      height: 7px;
      border-radius: 999px;
      background: var(--accent);
    }
    .hint {
      text-align: right;
      font-size: 11px;
      color: var(--text-muted);
      margin-top: 10px;
      line-height: 1.5;
    }
    .notify-card.warning {
      border-top: 4px solid var(--accent);
    }
    .notify-card.announcement {
      text-align: center;
    }
    .notify-card.announcement .badge,
    .notify-card.announcement .mini-pill {
      margin-left: auto;
      margin-right: auto;
    }
    .notify-card.announcement .hint {
      text-align: center;
    }
    .notify-card.maintenance {
      border-style: dashed;
    }
    .notify-card.maintenance .web-link {
      box-shadow: 0 0 0 4px var(--accent-soft);
    }
    .notify-card.others .badge-dot {
      animation: none;
    }
    .notify-card.others .web-link {
      background: transparent;
      border: 1px solid var(--accent);
      color: var(--accent);
    }
  </style>
</head>
<body>
  <div class="notify-card ${category}">
    <div class="badge">
      <span class="badge-dot"></span>
      ${badge}
    </div>
    <h2 class="title">${title}</h2>
    <p class="subtitle">${subtitle}</p>
    <a class="web-link" href="${buttonUrl}" target="_blank" rel="noopener">${buttonLabel}</a>
    <p class="support-text">${supportPrefix} <strong>${supportPhone}</strong></p>
    <div class="mini-pill">
      <span class="dot-blue"></span>
      ${statusText}
    </div>
    <p class="hint">
      ${hintText}<br>
      <strong>${teamName}</strong>
    </p>
  </div>
</body>
</html>`;
}

function StatusMessage({ state }: { state: WarningFormState }) {
  if (state.status === "idle") return null;

  return (
    <div
      className={cn(
        "rounded-md border px-4 py-3 text-sm font-semibold",
        state.status === "success"
          ? "border-teal-200 bg-teal-50/90 text-teal-800 dark:border-cyan-300/25 dark:bg-cyan-300/10 dark:text-cyan-100"
          : "border-rose-200 bg-rose-50/90 text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100",
      )}
    >
      {state.message}
    </div>
  );
}

function ToggleField({
  name,
  checked,
  onChange,
  label,
}: {
  name: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  label: string;
}) {
  return (
    <label
      className={cn(
        controlCardClassName,
        "group hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:hover:bg-white/10",
      )}
    >
      <span className={controlLabelClassName}>{label}</span>
      <input
        type="checkbox"
        name={name}
        checked={checked}
        onChange={(event) => onChange(event.target.checked)}
        className="peer sr-only"
      />
      <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
    </label>
  );
}

function FieldInput({
  label,
  value,
  onChange,
  type = "text",
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  type?: "text" | "url";
}) {
  return (
    <label className="block min-w-0">
      <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
        {label}
      </span>
      <input
        type={type}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className={inputClassName}
      />
    </label>
  );
}

function FieldTextarea({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <label className="block min-w-0">
      <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
        {label}
      </span>
      <textarea
        rows={4}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className={cn(inputClassName, "resize-y")}
      />
    </label>
  );
}

export function WarningManager({ warning }: WarningManagerProps) {
  const router = useRouter();
  const formRef = useRef<HTMLFormElement>(null);
  const lastToastKeyRef = useRef("");
  const savedEditorValues = editorValuesFromWarning(warning);
  const [selectedCategory, setSelectedCategory] =
    useState<WarningCategory>(savedEditorValues.category);
  const [noticeFields, setNoticeFields] =
    useState<NoticeFields>(savedEditorValues.fields);
  const [platform, setPlatform] = useState(warning?.platform ?? "all");
  const [isShow, setIsShow] = useState(warning?.isShow ?? true);
  const [isCancelable, setIsCancelable] = useState(
    warning?.isCancelable ?? true,
  );
  const [isShowInLatest, setIsShowInLatest] = useState(
    warning?.isShowInLatest ?? true,
  );
  const selectedCategoryOption =
    categoryOptions.find((option) => option.value === selectedCategory) ??
    categoryOptions[0];
  const selectedHtml = buildNoticeHtml(
    selectedCategory,
    selectedCategoryOption,
    noticeFields,
  );
  const previewHtml = selectedHtml;
  const [state, formAction, isPending] = useActionState(
    saveWarningAction,
    initialState,
  );
  const [deleteState, setDeleteState] =
    useState<WarningFormState>(initialState);
  const [isDeletePending, startDeleteTransition] = useTransition();

  useEffect(() => {
    if (state.status === "success") {
      router.refresh();
    }
  }, [router, state.status, state.resetKey]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(state.message || "Warning saved.");
      return;
    }

    toast.error(state.message || "Warning could not be saved.");
  }, [state.message, state.resetKey, state.status]);

  const resetForm = () => {
    formRef.current?.reset();
    const latest = editorValuesFromWarning(warning);
    setSelectedCategory(latest.category);
    setNoticeFields(latest.fields);
    setPlatform(warning?.platform ?? "all");
    setIsShow(warning?.isShow ?? true);
    setIsCancelable(warning?.isCancelable ?? true);
    setIsShowInLatest(warning?.isShowInLatest ?? true);
  };

  const updateNoticeField = (key: keyof NoticeFields, value: string) => {
    setNoticeFields((current) => ({
      ...current,
      [key]: value,
    }));
  };

  const deleteWarning = () => {
    if (!window.confirm("Delete the current warning content?")) {
      return;
    }

    startDeleteTransition(async () => {
      const result = await deleteWarningAction();
      setDeleteState(result);

      if (result.status === "success") {
        toast.success(result.message);
        router.refresh();
        return;
      }

      toast.error(result.message);
    });
  };

  return (
    <div className="space-y-5 pb-28">
      <form
        ref={formRef}
        action={formAction}
        className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]"
      >
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-amber-700 dark:border-white/10 dark:bg-white/8 dark:text-amber-200">
            <TriangleAlert className="size-4" />
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-amber-700 dark:text-amber-200">
              Realtime Database
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Warning configuration
            </h2>
          </div>
        </div>

        <div className="space-y-4">
          <StatusMessage state={state} />

          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            {categoryOptions.map((option) => {
              const Icon = option.icon;
              const isSelected = selectedCategory === option.value;

              return (
                <button
                  key={option.value}
                  type="button"
                  onClick={() => {
                    setSelectedCategory(option.value);
                    setDeleteState(initialState);
                  }}
                  className={cn(
                    "flex min-h-24 min-w-0 items-start gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-3 text-left shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:-translate-y-0.5 hover:border-[rgba(15,23,42,0.22)] hover:bg-white/68 dark:border-white/10 dark:bg-white/6 dark:hover:bg-white/10",
                    isSelected
                      ? option.activeClassName
                      : "text-slate-700 dark:text-slate-200",
                  )}
                  aria-pressed={isSelected}
                >
                  <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-white/70 text-current shadow-[inset_0_1px_0_rgba(255,255,255,0.76)] dark:bg-white/10">
                    <Icon className="size-4" />
                  </span>
                  <span className="min-w-0">
                    <span className="block text-sm font-bold">
                      {option.label}
                    </span>
                    <span className="mt-1 block text-xs font-semibold opacity-75">
                      {option.description}
                    </span>
                    <span className="mt-2 inline-flex rounded-md bg-white/58 px-2 py-1 text-[11px] font-bold uppercase tracking-[0.12em] opacity-85 dark:bg-white/10">
                      {isSelected ? "Selected" : "Choose"}
                    </span>
                  </span>
                </button>
              );
            })}
          </div>

          <div className="grid items-stretch gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <label className={controlCardClassName}>
              <span className={controlLabelClassName}>Which Platform</span>
              <select
                name="platform"
                value={platform}
                onChange={(event) =>
                  setPlatform(event.target.value as WarningConfig["platform"])
                }
                className={selectClassName}
              >
                <option value="ios">ios</option>
                <option value="android">android</option>
                <option value="all">all</option>
              </select>
            </label>
            <ToggleField
              name="isShow"
              label="Show warning"
              checked={isShow}
              onChange={setIsShow}
            />
            <ToggleField
              name="isCancelable"
              label="Cancelable"
              checked={isCancelable}
              onChange={setIsCancelable}
            />
            <ToggleField
              name="isShowInLatest"
              label="Show in latest"
              checked={isShowInLatest}
              onChange={setIsShowInLatest}
            />
          </div>

          <input type="hidden" name="txt" value={selectedHtml} />

          <div className="grid gap-3 lg:grid-cols-2">
            <FieldInput
              label="Title"
              value={noticeFields.title}
              onChange={(value) => updateNoticeField("title", value)}
            />
            <FieldInput
              label="Button text"
              value={noticeFields.buttonLabel}
              onChange={(value) => updateNoticeField("buttonLabel", value)}
            />
            <FieldInput
              label="Button link"
              type="url"
              value={noticeFields.buttonUrl}
              onChange={(value) => updateNoticeField("buttonUrl", value)}
            />
            <FieldInput
              label="Support phone"
              value={noticeFields.supportPhone}
              onChange={(value) => updateNoticeField("supportPhone", value)}
            />
          </div>

          <FieldTextarea
            label="Paragraph"
            value={noticeFields.subtitle}
            onChange={(value) => updateNoticeField("subtitle", value)}
          />

          <div className="grid gap-3 lg:grid-cols-2">
            <FieldInput
              label="Support text"
              value={noticeFields.supportPrefix}
              onChange={(value) => updateNoticeField("supportPrefix", value)}
            />
            <FieldInput
              label="Status pill"
              value={noticeFields.statusText}
              onChange={(value) => updateNoticeField("statusText", value)}
            />
            <FieldInput
              label="Bottom note"
              value={noticeFields.hintText}
              onChange={(value) => updateNoticeField("hintText", value)}
            />
            <FieldInput
              label="Team name"
              value={noticeFields.teamName}
              onChange={(value) => updateNoticeField("teamName", value)}
            />
          </div>

          <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto]">
            <button
              type="button"
              onClick={deleteWarning}
              disabled={!warning || isDeletePending}
              className="inline-flex min-h-12 items-center justify-center gap-2 rounded-md bg-rose-600 px-4 text-sm font-bold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50 sm:justify-self-start"
            >
              <Trash2 className="size-4" />
              {isDeletePending ? "Deleting..." : "Delete"}
            </button>

            <button
              type="button"
              onClick={resetForm}
              className="inline-flex min-h-12 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/58 px-4 text-sm font-bold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:-translate-y-0.5 hover:border-[rgba(15,23,42,0.22)] hover:bg-white/80 hover:text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12 dark:hover:text-cyan-200"
            >
              <RotateCcw className="size-4" />
              Reset
            </button>

            <button
              type="submit"
              disabled={isPending}
              className="inline-flex min-h-12 items-center justify-center gap-2 rounded-md bg-slate-950 px-5 text-sm font-bold text-white shadow-[0_14px_34px_rgba(15,23,42,0.22)] transition hover:-translate-y-0.5 hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-slate-950 dark:hover:bg-cyan-100"
            >
              <Save className="size-4" />
              {isPending ? "Saving..." : `Save ${selectedCategoryOption.label}`}
            </button>
          </div>

          <StatusMessage state={deleteState} />
        </div>
      </form>

      <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
            {selectedCategoryOption.value === "announcement" ? (
              <Bell className="size-4" />
            ) : (
              <Eye className="size-4" />
            )}
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              Preview
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Rendered {selectedCategoryOption.label}
            </h2>
          </div>
        </div>

        {previewHtml ? (
          <iframe
            title="Warning preview"
            sandbox=""
            srcDoc={previewHtml}
            className="h-[28rem] w-full rounded-md border border-[rgba(15,23,42,0.14)] bg-white dark:border-white/10"
          />
        ) : (
          <div className="rounded-md border border-dashed border-[rgba(15,23,42,0.18)] bg-white/38 px-4 py-8 text-center text-sm font-semibold text-slate-500 dark:border-white/12 dark:bg-white/6 dark:text-slate-400">
            No warning content to preview.
          </div>
        )}
      </section>
    </div>
  );
}
