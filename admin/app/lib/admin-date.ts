const monthNames = [
  "January",
  "February",
  "March",
  "April",
  "May",
  "June",
  "July",
  "August",
  "September",
  "October",
  "November",
  "December",
] as const;

const monthLookup = new Map(
  monthNames.map((month, index) => [month.toLowerCase(), index + 1]),
);

type DateParts = {
  year: number;
  month: number;
  day: number;
};

export type AdminDateFormat = "input" | "readable";

function padDatePart(value: number) {
  return String(value).padStart(2, "0");
}

function isValidDatePart({ year, month, day }: DateParts) {
  if (year < 1 || month < 1 || month > 12 || day < 1 || day > 31) {
    return false;
  }

  const date = new Date(Date.UTC(year, month - 1, day));
  return (
    date.getUTCFullYear() === year &&
    date.getUTCMonth() === month - 1 &&
    date.getUTCDate() === day
  );
}

function datePartsFromValue(value: unknown): DateParts | null {
  if (typeof value !== "string" && typeof value !== "number") return null;

  const raw = String(value).trim();
  if (!raw) return null;

  const isoDate = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (isoDate) {
    const parts = {
      year: Number(isoDate[1]),
      month: Number(isoDate[2]),
      day: Number(isoDate[3]),
    };
    return isValidDatePart(parts) ? parts : null;
  }

  const slashDate = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (slashDate) {
    const parts = {
      year: Number(slashDate[3]),
      month: Number(slashDate[2]),
      day: Number(slashDate[1]),
    };
    return isValidDatePart(parts) ? parts : null;
  }

  const monthDate = raw.match(/^([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})$/);
  if (monthDate) {
    const month = monthLookup.get(monthDate[1].toLowerCase());
    if (!month) return null;

    const parts = {
      year: Number(monthDate[3]),
      month,
      day: Number(monthDate[2]),
    };
    return isValidDatePart(parts) ? parts : null;
  }

  const parsed = new Date(raw);
  if (Number.isNaN(parsed.getTime())) return null;

  const kolkataParts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Kolkata",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(parsed);

  const getPart = (type: Intl.DateTimeFormatPartTypes) =>
    kolkataParts.find((part) => part.type === type)?.value ?? "";

  const parts = {
    year: Number(getPart("year")),
    month: Number(getPart("month")),
    day: Number(getPart("day")),
  };

  return isValidDatePart(parts) ? parts : null;
}

export function adminDateValue(
  value: unknown,
  format: AdminDateFormat = "input",
) {
  const parts = datePartsFromValue(value);
  if (!parts) return "";

  if (format === "readable") {
    return `${monthNames[parts.month - 1]} ${parts.day}, ${parts.year}`;
  }

  return `${parts.year}-${padDatePart(parts.month)}-${padDatePart(parts.day)}`;
}

export function adminFormDateValue(
  formData: FormData,
  key: string,
  format?: AdminDateFormat,
) {
  const value = formData.get(key);
  const dateValue = adminDateValue(
    typeof value === "string" ? value : "",
    format,
  );
  return dateValue.length > 0 ? dateValue : undefined;
}
