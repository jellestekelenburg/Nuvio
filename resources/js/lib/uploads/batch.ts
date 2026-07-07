import axios from 'axios';
import { runWithConcurrency } from './concurrency';
import type { UploadPlanBatch, UploadQueueItem } from './types';

type UploadBatchesOptions = {
    uploadId: string;
    batches: UploadPlanBatch[];
    uploadItems: UploadQueueItem[];
    parentId: number | null;
};

export async function uploadInBatches({
    uploadId,
    batches,
    uploadItems,
    parentId,
}: UploadBatchesOptions) {
    const itemByClientId = new Map(
        uploadItems.map((item) => [item.client_id, item]),
    );

    await runWithConcurrency(batches, 3, async (batch) => {
        await uploadBatch({
            uploadId,
            batch,
            itemByClientId,
            parentId,
        });
    });
}

async function uploadBatch({
    uploadId,
    batch,
    itemByClientId,
    parentId,
}: {
    uploadId: string;
    batch: UploadPlanBatch;
    itemByClientId: Map<string, UploadQueueItem>;
    parentId: number | null;
}) {
    const form = new FormData();

    if (parentId !== null) {
        form.append('parent_id', String(parentId));
    }

    for (const clientId of batch.files) {
        const item = itemByClientId.get(clientId);

        if (!item) {
            throw new Error(`Upload item not found for client_id: ${clientId}`);
        }

        item.status = 'uploading';

        form.append('files[]', item.file);
        form.append('client_ids[]', item.client_id);
        form.append('relative_paths[]', item.relative_path);
    }

    const { data } = await axios.post(
        `/api/uploads/${uploadId}/batches/${batch.batch_id}`,
        form,
    );

    if (!data.ok) {
        throw new Error(data.message ?? 'Batch upload failed.');
    }

    for (const clientId of batch.files) {
        const item = itemByClientId.get(clientId);

        if (item) {
            item.status = 'done';
            item.progress = 100;
        }
    }
}
