import axios from 'axios';
import { runWithConcurrency } from './concurrency';
import type { UploadPlanBatch, UploadQueueItem } from './types';

type UploadBatchesOptions = {
    uploadId: string;
    batches: UploadPlanBatch[];
    uploadItems: UploadQueueItem[];
    onQueueUpdated?: (uploadItems: UploadQueueItem[]) => void;
};

export async function uploadInBatches({
    uploadId,
    batches,
    uploadItems,
    onQueueUpdated,
}: UploadBatchesOptions) {
    const itemByClientId = new Map(
        uploadItems.map((item) => [item.client_id, item]),
    );

    await runWithConcurrency(batches, 3, async (batch) => {
        await uploadBatch({
            uploadId,
            batch,
            itemByClientId,
            uploadItems,
            onQueueUpdated,
        });
    });
}

async function uploadBatch({
    uploadId,
    batch,
    itemByClientId,
    uploadItems,
    onQueueUpdated,
}: {
    uploadId: string;
    batch: UploadPlanBatch;
    itemByClientId: Map<string, UploadQueueItem>;
    uploadItems: UploadQueueItem[];
    onQueueUpdated?: (uploadItems: UploadQueueItem[]) => void;
}) {
    const form = new FormData();

    for (const clientId of batch.files) {
        const item = itemByClientId.get(clientId);

        if (!item) {
            throw new Error(`Upload item not found for client_id: ${clientId}`);
        }

        item.status = 'uploading';
        onQueueUpdated?.(uploadItems);

        form.append('files[]', item.file);
        form.append('client_ids[]', item.client_id);
    }

    const { data } = await axios.post(
        `/api/uploads/${uploadId}/batches/${batch.batch_id}`,
        form,
    );

    if (!data.ok) {
        throw new Error(data.message ?? 'Batch upload failed.');
    }

    for (const uploadedFile of data.files) {
        const item = itemByClientId.get(uploadedFile.client_id);

        if (item) {
            item.name = uploadedFile.name;
            item.status = 'done';
            item.progress = 100;
            onQueueUpdated?.(uploadItems);
        }
    }
}
