import type {
    ParseCvErrorResponse,
    ParseCvResponse,
    ParserStatusResponse,
} from '@/types/cv-parser';

export class ParseCvRequestError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'ParseCvRequestError';
    }
}

export async function fetchParserStatus(): Promise<ParserStatusResponse> {
    const response = await fetch('/api/v1/status', {
        headers: {
            Accept: 'application/json',
        },
    });

    if (!response.ok) {
        throw new Error('Failed to check parser status.');
    }

    return response.json();
}

export async function parseCv(file: File): Promise<ParseCvResponse> {
    const formData = new FormData();
    formData.append('cv', file);

    const response = await fetch('/api/v1/parse', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
        },
        body: formData,
    });

    const payload = (await response.json()) as
        | ParseCvResponse
        | ParseCvErrorResponse;

    if (!response.ok) {
        const error = payload as ParseCvErrorResponse;

        if (error.errors?.cv?.length) {
            throw new ParseCvRequestError(error.errors.cv[0]);
        }

        throw new ParseCvRequestError(
            error.message ?? 'Failed to parse CV.',
        );
    }

    return payload as ParseCvResponse;
}
