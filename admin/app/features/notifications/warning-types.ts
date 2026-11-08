export const warningCategories = [
  "warning",
  "announcement",
  "maintenance",
  "others",
] as const;

export type WarningCategory = (typeof warningCategories)[number];

export type WarningConfig = {
  isCancelable: boolean;
  isShow: boolean;
  isShowInLatest: boolean;
  platform: "ios" | "android" | "all";
  txt: string;
};
