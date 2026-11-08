import "server-only";

import { apiClient } from "@/app/lib/api-client";

export type HomeSectionItem = {
  key: string;
  title: string;
  position: number;
  is_enabled: boolean;
};

type HomeSectionResponse = {
  sections: HomeSectionItem[];
};

const ADMIN_PATH = "/api/v4/admin/home-sections";

export const homeSectionService = {
  async list() {
    const response = await apiClient.get<HomeSectionResponse>(ADMIN_PATH, {
      cache: "no-store",
    });
    return response.sections;
  },

  async update(sections: Pick<HomeSectionItem, "key" | "title">[]) {
    const response = await apiClient.put<HomeSectionResponse>(ADMIN_PATH, {
      sections,
    });
    return response.sections;
  },
};
