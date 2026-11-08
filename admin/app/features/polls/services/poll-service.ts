import "server-only";

import { ApiError, apiClient, type QueryParams } from "@/app/lib/api-client";

export type PollStatus = "active" | "closed";

export type PollOptionPayload = {
  option_text: string;
  sort_order?: number;
};

export type CreatePollPayload = {
  question: string;
  description?: string | null;
  status?: PollStatus;
  starts_at?: string | null;
  ends_at?: string | null;
  options: PollOptionPayload[];
};

export type PollOptionItem = {
  id?: number | string;
  poll_id?: number | string;
  option_text?: string | null;
  sort_order?: number | string | null;
  votes_count?: number | string;
  [key: string]: unknown;
};

export type PollItem = {
  id?: number | string;
  question?: string | null;
  description?: string | null;
  status?: PollStatus | string | null;
  starts_at?: string | null;
  ends_at?: string | null;
  votes_count?: number | string;
  options?: PollOptionItem[];
  created_at?: string | null;
  updated_at?: string | null;
  [key: string]: unknown;
};

export type PollVoterUser = {
  uid?: string | null;
  name?: string | null;
  mail?: string | null;
  call?: string | null;
  img?: string | null;
  [key: string]: unknown;
};

export type PollVoteItem = {
  id?: number | string;
  poll_id?: number | string;
  poll_option_id?: number | string;
  uid?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  option?: PollOptionItem | null;
  user?: PollVoterUser | null;
  [key: string]: unknown;
};

export type UpdatePollPayload = Partial<CreatePollPayload>;

export type PollResultsResponse = {
  status: boolean;
  data?: {
    poll?: PollItem;
    total_votes?: number;
  };
  message?: string;
  error?: string;
};

export type PollDeleteResponse = {
  status: boolean;
  message?: string;
  error?: string;
};

export type PollVotersResponse = {
  status: boolean;
  data?: {
    data?: PollVoteItem[];
    current_page?: number;
    first_page_url?: string | null;
    from?: number | null;
    last_page?: number;
    last_page_url?: string | null;
    next_page_url?: string | null;
    path?: string | null;
    per_page?: number;
    prev_page_url?: string | null;
    to?: number | null;
    total?: number;
    [key: string]: unknown;
  };
  message?: string;
  error?: string;
};

export type CreatePollOptionPayload = {
  option_text: string;
  sort_order?: number;
};

export type UpdatePollOptionPayload = Partial<CreatePollOptionPayload>;

export type PollListParams = {
  limit?: number;
  page?: number;
  status?: PollStatus;
  available?: boolean;
  uid?: string;
};

export type PollListResponse = {
  status: boolean;
  data?: {
    data?: PollItem[];
    [key: string]: unknown;
  };
  message?: string;
  errors?: Record<string, string[]>;
  error?: string;
};

export type PollMutationResponse = {
  status: boolean;
  message?: string;
  data?: PollItem;
  errors?: Record<string, string[]>;
  error?: string;
};

const POLLS_BASE_PATH = "/api/v4/admin/polls";
const POLL_OPTIONS_BASE_PATH = "/api/v4/admin/poll-options";

function toPollListQueryParams(
  params?: PollListParams,
): QueryParams | undefined {
  if (!params) return undefined;

  return {
    limit: params.limit,
    page: params.page,
    status: params.status,
    available: params.available,
    uid: params.uid,
  };
}

export const pollService = {
  async list(params?: PollListParams) {
    return apiClient.get<PollListResponse>(POLLS_BASE_PATH, {
      query: toPollListQueryParams(params),
    });
  },

  async create(payload: CreatePollPayload) {
    return apiClient.post<PollMutationResponse>(POLLS_BASE_PATH, payload);
  },

  async getById(id: string | number) {
    return apiClient.get<PollMutationResponse>(`${POLLS_BASE_PATH}/${id}`);
  },

  async update(id: string | number, payload: UpdatePollPayload) {
    return apiClient.put<PollMutationResponse>(
      `${POLLS_BASE_PATH}/${id}`,
      payload,
    );
  },

  async remove(id: string | number) {
    return apiClient.delete<PollDeleteResponse>(`${POLLS_BASE_PATH}/${id}`);
  },

  async results(id: string | number) {
    return apiClient.get<PollResultsResponse>(
      `${POLLS_BASE_PATH}/${id}/results`,
    );
  },

  async voters(id: string | number, page?: number) {
    return apiClient.get<PollVotersResponse>(`${POLLS_BASE_PATH}/${id}/voters`, {
      query: {
        page,
      },
    });
  },

  async createOption(
    pollId: string | number,
    payload: CreatePollOptionPayload,
  ) {
    return apiClient.post<PollMutationResponse>(
      `${POLLS_BASE_PATH}/${pollId}/options`,
      payload,
    );
  },

  async updateOption(
    optionId: string | number,
    payload: UpdatePollOptionPayload,
  ) {
    return apiClient.put<PollMutationResponse>(
      `${POLL_OPTIONS_BASE_PATH}/${optionId}`,
      payload,
    );
  },

  async removeOption(optionId: string | number) {
    return apiClient.delete<PollDeleteResponse>(
      `${POLL_OPTIONS_BASE_PATH}/${optionId}`,
    );
  },
};

export { ApiError };
