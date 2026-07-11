import axios from 'axios';
import type { UploadPlanResponse, UploadQueueItem } from './types';

export async function planUpload(
    parentId: number | null,
    uploadItems: UploadQueueItem[],
): Promise<UploadPlanResponse> {
    const { data } = await axios.post('/api/uploads/plan', {
        parent_id: parentId,
        files: uploadItems.map(
            ({
                client_id,
                name,
                size,
                relative_path,
                content_type,
                last_modified,
            }) => ({
                client_id,
                name,
                size,
                relative_path,
                content_type,
                last_modified,
            }),
        ),
    });

    return data;
}
