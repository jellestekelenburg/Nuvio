import axios from 'axios';

export function uploadErrorMessage(error: unknown): string {
    if (axios.isAxiosError(error) && error.response) {
        const data = error.response.data;

        return (
            data.errors?.[0]?.message ??
            data.message ??
            'Upload is not allowed.'
        );
    }

    if (error instanceof Error && error.message) {
        return error.message;
    }

    return 'Upload failed.';
}
