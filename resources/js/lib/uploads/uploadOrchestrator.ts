import { uploadInBatches } from './batch';
import { uploadMultipartFiles } from './multipart';
import { planUpload } from './plan';
import { createUploadQueue } from './queue';
import type { UploadQueueItem } from './types';

type UploadSelectionOptions = {
    files: FileList | File[];
    parentId: number | null;
    onQueueCreated?: (uploadItems: UploadQueueItem[]) => void;
};

export async function uploadSelection({
    files,
    parentId,
    onQueueCreated,
}: UploadSelectionOptions) {
    const uploadItems = createUploadQueue(files);

    onQueueCreated?.(uploadItems);

    const plan = await planUpload(parentId, uploadItems);

    if (!plan.ok) {
        throw new Error(plan.errors?.[0]?.message ?? plan.message);
    }

    if (plan.small_file_batches.length > 0) {
        await uploadInBatches({
            uploadId: plan.upload_id,
            batches: plan.small_file_batches,
            uploadItems,
            parentId,
        });
    }

    if (plan.multipart_files.length > 0) {
        await uploadMultipartFiles({
            uploadId: plan.upload_id,
            files: plan.multipart_files,
            uploadItems,
            parentId,
        });
    }
}
