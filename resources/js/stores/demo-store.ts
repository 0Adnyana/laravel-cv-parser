import { create } from 'zustand';
import { ParseCvRequestError, fetchParserStatus, parseCv } from '@/lib/parse-cv';
import type { ParseCvData } from '@/types/cv-parser';

export type UiState = 'idle' | 'processing' | 'success' | 'error';

type CachedParserStatus = {
    available: boolean;
    warning: string | null;
};

const STATUS_CACHE_KEY = 'demo-parser-status';

function readCachedStatus(): CachedParserStatus | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const raw = sessionStorage.getItem(STATUS_CACHE_KEY);

        if (raw === null) {
            return null;
        }

        const parsed = JSON.parse(raw) as Partial<CachedParserStatus>;

        if (typeof parsed.available !== 'boolean') {
            sessionStorage.removeItem(STATUS_CACHE_KEY);

            return null;
        }

        return {
            available: parsed.available,
            warning: parsed.warning ?? null,
        };
    } catch {
        sessionStorage.removeItem(STATUS_CACHE_KEY);

        return null;
    }
}

function writeCachedStatus(status: CachedParserStatus): void {
    if (typeof window === 'undefined') {
        return;
    }

    sessionStorage.setItem(STATUS_CACHE_KEY, JSON.stringify(status));
}

type DemoState = {
    selectedFile: File | null;
    statusAvailable: boolean;
    statusWarning: string | null;
    statusLoading: boolean;
    statusFetched: boolean;
    uiState: UiState;
    errorMessage: string | null;
    result: ParseCvData | null;
    fetchStatus: () => Promise<void>;
    selectFile: (file: File | null) => void;
    submitParse: () => Promise<boolean>;
};

export const selectIsSubmitDisabled = (state: DemoState): boolean =>
    state.statusLoading ||
    !state.statusAvailable ||
    state.selectedFile === null ||
    state.uiState === 'processing';

let statusFetchPromise: Promise<void> | null = null;

export const useDemoStore = create<DemoState>((set, get) => ({
    selectedFile: null,
    statusAvailable: false,
    statusWarning: null,
    statusLoading: true,
    statusFetched: false,
    uiState: 'idle',
    errorMessage: null,
    result: null,

    fetchStatus: async () => {
        if (get().statusFetched) {
            return;
        }

        const cachedStatus = readCachedStatus();

        if (cachedStatus !== null) {
            if (cachedStatus.warning === 'Unable to check parser status.') {
                sessionStorage.removeItem(STATUS_CACHE_KEY);
            } else {
                set({
                    statusAvailable: cachedStatus.available,
                    statusWarning: cachedStatus.warning,
                    statusLoading: false,
                    statusFetched: true,
                });

                return;
            }
        }

        if (statusFetchPromise !== null) {
            return statusFetchPromise;
        }

        statusFetchPromise = (async () => {
            try {
                const status = await fetchParserStatus();

                writeCachedStatus(status);

                set({
                    statusAvailable: status.available,
                    statusWarning: status.warning,
                    statusFetched: true,
                });
            } catch {
                set({
                    statusAvailable: false,
                    statusWarning: 'Unable to check parser status.',
                });
            } finally {
                set({ statusLoading: false });
                statusFetchPromise = null;
            }
        })();

        return statusFetchPromise;
    },

    selectFile: (file) => {
        set({
            selectedFile: file,
            errorMessage: null,
            result: null,
            uiState: 'idle',
        });
    },

    submitParse: async () => {
        const { selectedFile, statusAvailable, uiState } = get();

        if (
            !selectedFile ||
            !statusAvailable ||
            uiState === 'processing'
        ) {
            return false;
        }

        set({
            uiState: 'processing',
            errorMessage: null,
            result: null,
        });

        try {
            const response = await parseCv(selectedFile);

            set({
                result: response.data,
                selectedFile: null,
                uiState: 'success',
            });

            return true;
        } catch (error) {
            set({
                uiState: 'error',
                errorMessage:
                    error instanceof ParseCvRequestError || error instanceof Error
                        ? error.message
                        : 'An unexpected error occurred.',
            });

            return false;
        }
    },
}));
