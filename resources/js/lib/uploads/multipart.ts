import axios from 'axios';
import type {
    InitiatedMultipartUpload,
    UploadPlanMultipartFile,
    UploadQueueItem,
} from './types';

type UploadMultipartFilesOptions = {
    uploadId: string;
    files: UploadPlanMultipartFile[];
    uploadItems: UploadQueueItem[];
    parentId: number | null;
};

export async function uploadMultipartFiles({
    uploadId,
    files,
    uploadItems,
    parentId,
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

        await uploadMultipartFile({
            uploadId,
            plannedFile,
            uploadItem,
            parentId,
        });
    }
}

async function uploadMultipartFile({
    uploadId,
    plannedFile,
    uploadItem,
    parentId,
}: {
    uploadId: string;
    plannedFile: UploadPlanMultipartFile;
    uploadItem: UploadQueueItem;
    parentId: number | null;
}) {
    const upload = await initiateMultipartUpload({
        uploadId,
        plannedFile,
        parentId,
    });

    console.log('multipart upload initiated', {
        upload,
        file: uploadItem.file,
    });
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
