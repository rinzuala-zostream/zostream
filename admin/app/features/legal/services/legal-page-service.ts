import "server-only";

import { apiClient } from "@/app/lib/api-client";

export type LegalPageSection = {
  heading: string;
  body: string;
};

export type LegalPageItem = {
  id: number | string;
  slug: string;
  eyebrow?: string | null;
  title: string;
  effective_date?: string | null;
  intro?: string | null;
  sections: LegalPageSection[];
  is_published: boolean;
  sort_order: number;
  created_at?: string;
  updated_at?: string;
};

export type LegalPagePayload = {
  slug: string;
  eyebrow?: string | null;
  title: string;
  effective_date?: string | null;
  intro?: string | null;
  sections: LegalPageSection[];
  is_published: boolean;
  sort_order: number;
};

const ADMIN_PATH = "/api/v4/admin/legal-pages";

export const legalPageService = {
  list() {
    return apiClient.get<LegalPageItem[]>(ADMIN_PATH, { cache: "no-store" });
  },

  create(payload: LegalPagePayload) {
    return apiClient.post<LegalPageItem>(ADMIN_PATH, payload);
  },

  update(id: string | number, payload: LegalPagePayload) {
    return apiClient.put<LegalPageItem>(`${ADMIN_PATH}/${id}`, payload);
  },
};
