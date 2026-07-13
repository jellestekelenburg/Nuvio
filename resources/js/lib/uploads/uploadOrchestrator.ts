import { uploadInBatches } from './batch';
import { uploadMultipartFiles } from './multipart';
import { planUpload } from './plan';
import { createUploadQueue } from './queue';
import type { UploadQueueItem } from './types';

type UploadSelectionOptions = {
    files: FileList | File[];
    parentId: number | null;
    onQueueCreated?: (uploadItems: UploadQueueItem[]) => void;
    onQueueUpdated?: (uploadItems: UploadQueueItem[]) => void;
    onPlanCreated?: (plan: Awaited<ReturnType<typeof planUpload>>) => void;
};

export async function uploadSelection({
    files,
    parentId,
    onQueueCreated,
    onQueueUpdated,
    onPlanCreated,
}: UploadSelectionOptions) {
    const uploadItems = createUploadQueue(files);

    onQueueCreated?.(uploadItems);
    onQueueUpdated?.(uploadItems);

    const plan = await planUpload(parentId, uploadItems);
    onPlanCreated?.(plan);

    if (!plan.ok) {
        throw new Error(plan.errors?.[0]?.message ?? plan.message);
    }

    const plannedFilesByClientId = new Map(
        plan.files.map((file) => [file.client_id, file]),
    );

    for (const item of uploadItems) {
        const plannedFile = plannedFilesByClientId.get(item.client_id);

        if (plannedFile) {
            item.name = plannedFile.name;
        }
    }

    onQueueUpdated?.(uploadItems);

    if (plan.small_file_batches.length > 0) {
        await uploadInBatches({
            uploadId: plan.upload_id,
            batches: plan.small_file_batches,
            uploadItems,
            onQueueUpdated,
        });
    }

    if (plan.multipart_files.length > 0) {
        await uploadMultipartFiles({
            uploadId: plan.upload_id,
            files: plan.multipart_files,
            uploadItems,
            onQueueUpdated,
        });
    }

    onQueueUpdated?.(uploadItems);

    return {
        plan,
        uploadItems,
    };
}
