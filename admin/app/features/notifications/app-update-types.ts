export const appUpdatePlatforms = [
  "update",
  "ios_update",
  "lg_tv_update",
  "sam_tv_update",
  "tv_update",
] as const;

export type AppUpdatePlatform = (typeof appUpdatePlatforms)[number];

export type AppUpdateConfig = {
  platform: AppUpdatePlatform;
  enabled: boolean;
  force: boolean;
  url: string;
  version: string | number;
};
