import axios from 'axios';
import { runWithConcurrency } from './concurrency';
import type {
    CompletedMultipartPart,
    InitiatedMultipartUpload,
    SignedMultipartPart,
    SignedMultipartPartsResponse,
    UploadPlanMultipartFile,
    UploadQueueItem,
} from './types';

type UploadMultipartFilesOptions = {
    uploadId: string;
    files: UploadPlanMultipartFile[];
    uploadItems: UploadQueueItem[];
    parentId: number | null;
    onQueueUpdated?: (uploadItems: UploadQueueItem[]) => void;
};

export async function uploadMultipartFiles({
    uploadId,
    files,
    uploadItems,
    parentId,
    onQueueUpdated,
}: UploadMultipartFilesOptions) {
    const itemByClientId = new Map(
        uploadItems.map((item) => [item.client_id, item]),
    );

    for (const plannedFile of files) {
        const uploadItem = itemByClientId.get(plannedFile.client_id);

        if (!uploadItem) {
            throw new Error(
                `Upload item not found for client_id: ${plannedFile.client_id}`,
            );
        }

        uploadItem.status = 'uploading';
        onQueueUpdated?.(uploadItems);

        await uploadMultipartFile({
            uploadId,
            plannedFile,
            uploadItem,
            parentId,
            uploadItems,
            onQueueUpdated,
        });
    }
}

async function uploadMultipartFile({
    uploadId,
    plannedFile,
    uploadItem,
    parentId,
    uploadItems,
    onQueueUpdated,
}: {
    uploadId: string;
    plannedFile: UploadPlanMultipartFile;
    uploadItem: UploadQueueItem;
    parentId: number | null;
    uploadItems: UploadQueueItem[];
    onQueueUpdated?: (uploadItems: UploadQueueItem[]) => void;
}) {
    const upload = await initiateMultipartUpload({
        uploadId,
        plannedFile,
        parentId,
    });

    const completedParts: CompletedMultipartPart[] = [];
    let uploadedBytes = 0;

    try {
        for (const partNumbers of partWindows(
            upload.part_count,
            upload.signing_window,
        )) {
            const signedParts = await signMultipartUploadParts({
                uploadId,
                uploadFileId: plannedFile.upload_file_id,
                parts: partNumbers,
            });

            await runWithConcurrency(
                signedParts.parts,
                upload.max_concurrency,
                async (signedPart) => {
                    const completedPart = await putSignedPartWithRetry(
                        uploadItem.file,
                        signedPart,
                    );

                    completedParts.push(completedPart);
                    uploadedBytes += signedPart.end - signedPart.start;

                    uploadItem.progress = Math.min(
                        100,
                        Math.round(
                            (uploadedBytes / uploadItem.file.size) * 100,
                        ),
                    );
                    onQueueUpdated?.(uploadItems);
                },
            );
        }
        completedParts.sort((a, b) => a.part_number - b.part_number);

        await completeMultipartUpload({
            uploadId,
            uploadFileId: plannedFile.upload_file_id,
            parts: completedParts,
        });

        uploadItem.status = 'done';
        uploadItem.progress = 100;
        onQueueUpdated?.(uploadItems);
    } catch (error) {
        uploadItem.status = 'failed';
        uploadItem.error =
            error instanceof Error ? error.message : 'Multipart upload failed.';
        onQueueUpdated?.(uploadItems);
        await abortMultipartUpload({
            uploadId,
            uploadFileId: plannedFile.upload_file_id,
        }).catch(() => undefined);

        throw error;
    }
}

async function initiateMultipartUpload({
    uploadId,
    plannedFile,
    parentId,
}: {
    uploadId: string;
    plannedFile: UploadPlanMultipartFile;
    parentId: number | null;
}): Promise<InitiatedMultipartUpload> {
    const { data } = await axios.post(
        `/api/uploads/${uploadId}/multipart/${plannedFile.upload_file_id}/initiate`,
        {
            parent_id: parentId,
        },
    );

    if (!data.ok) {
        throw new Error(data.message ?? 'Multipart upload initiation failed.');
    }

    return data;
}

export async function signMultipartUploadParts({
    uploadId,
    uploadFileId,
    parts,
}: {
    uploadId: string;
    uploadFileId: string;
    parts: number[];
}): Promise<SignedMultipartPartsResponse> {
    const { data } = await axios.post(
        `/api/uploads/${uploadId}/multipart/${uploadFileId}/parts/sign`,
        { parts },
    );

    if (!data.ok) {
        throw new Error(data.message ?? 'Multipart parts signing failed.');
    }

    return data;
}

/**
 * Splits multipart part numbers into sequential signing windows.
 *
 * Each window contains up to `windowSize` part numbers, starting at 1 and
 * ending at `partCount`. The final window may contain fewer parts when the
 * total part count is not evenly divisible by the window size.
 */
function partWindows(partCount: number, windowSize: number): number[][] {
    const windows: number[][] = [];

    for (
        let partNumber = 1;
        partNumber <= partCount;
        partNumber += windowSize
    ) {
        const end = Math.min(partNumber + windowSize - 1, partCount);
        const window: number[] = [];

        for (let current = partNumber; current <= end; current++) {
            window.push(current);
        }

        windows.push(window);
    }

    return windows;
}

/**
 * Uploads a single multipart chunk to its signed S3 URL.
 *
 * The chunk is sliced from the original file using the signed part byte range.
 * A successful upload must expose an ETag header, which is returned with the
 * part number so the multipart upload can be completed later.
 */
async function putSignedPart(
    file: File,
    signedPart: SignedMultipartPart,
): Promise<CompletedMultipartPart> {
    const blob = file.slice(signedPart.start, signedPart.end);

    const response = await fetch(signedPart.url, {
        method: 'PUT',
        body: blob,
    });

    if (!response.ok) {
        throw new Error(`Part ${signedPart.part_number} upload failed.`);
    }

    const etag = response.headers.get('ETag');

    if (!etag) {
        throw new Error(
            'S3 did not expose the ETag header. Check bucket CORS ExposeHeaders.',
        );
    }

    return {
        part_number: signedPart.part_number,
        etag,
    };
}

/**
 * Uploads a signed multipart chunk, retrying transient failures.
 *
 * Each failed attempt waits a little longer before retrying. If all attempts
 * fail, the last upload error is thrown to the caller.
 */
async function putSignedPartWithRetry(
    file: File,
    signedPart: SignedMultipartPart,
    maxAttempts = 3,
): Promise<CompletedMultipartPart> {
    let lastError: unknown;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        try {
            return await putSignedPart(file, signedPart);
        } catch (error) {
            lastError = error;

            if (attempt < maxAttempts) {
                await sleep(300 * attempt);
            }
        }
    }

    throw lastError;
}

/**
 * Pauses async execution for the given number of milliseconds.
 *
 * Useful for delaying retry attempts between failed uploads.
 */
function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}

async function completeMultipartUpload({
    uploadId,
    uploadFileId,
    parts,
}: {
    uploadId: string;
    uploadFileId: string;
    parts: CompletedMultipartPart[];
}) {
    const { data } = await axios.post(
        `/api/uploads/${uploadId}/multipart/${uploadFileId}/complete`,
        { parts },
    );

    if (!data.ok) {
        throw new Error(data.message ?? 'Multipart completion failed.');
    }

    return data;
}

async function abortMultipartUpload({
    uploadId,
    uploadFileId,
}: {
    uploadId: string;
    uploadFileId: string;
}) {
    const { data } = await axios.post(
        `/api/uploads/${uploadId}/multipart/${uploadFileId}/abort`,
    );

    if (!data.ok) {
        throw new Error(data.message ?? 'Multipart abort failed.');
    }

    return data;
}
