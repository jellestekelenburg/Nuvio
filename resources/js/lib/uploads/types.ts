export type UploadStatus =
    | 'queued'
    | 'planning'
    | 'uploading'
    | 'done'
    | 'failed';

export type UploadQueueItem = {
    client_id: string;
    file: File;
    name: string;
    size: number;
    relative_path: string;
    content_type: string | null;
    last_modified: number | null;
    status: UploadStatus;
    progress: number;
    error?: string;
};

export type UploadPlanBatch = {
    batch_id: string;
    files: string[];
};

export type UploadPlanFile = {
    client_id: string;
    original_name: string;
    name: string;
    size: number;
    content_type: string | null;
    last_modified: number | null;
    relative_path: string | null;
};

export type UploadPlanMultipartFile = UploadPlanFile & {
    upload_file_id: string;
    part_size: number;
    part_count: number;
};

export type UploadPlanSuccess = {
    ok: true;
    version: 2;
    upload_id: string;
    parent_id: number;
    threshold_bytes: number;
    default_part_size: number;
    max_concurrency: number;
    signing_window: number;
    files: UploadPlanFile[];
    small_file_batches: UploadPlanBatch[];
    multipart_files: UploadPlanMultipartFile[];
    errors: [];
};

export type UploadPlanFailure = {
    ok: false;
    code?: string;
    message?: string;
    errors?: Array<{
        code?: string;
        message?: string;
    }>;
};

export type UploadPlanResponse = UploadPlanSuccess | UploadPlanFailure;

export type InitiatedMultipartUpload = {
    ok: true;
    upload_id: string;
    upload_file_id: string;
    status: 'initiated' | 'uploading';
    part_size: number;
    part_count: number;
    max_concurrency: number;
    signing_window: number;
};

export type SignedMultipartPart = {
    part_number: number;
    url: string;
    start: number;
    end: number;
};

export type SignedMultipartPartsResponse = {
    ok: true;
    upload_id: string;
    upload_file_id: string;
    parts: SignedMultipartPart[];
    expires_in: number;
    expires_at: string;
};

export type CompletedMultipartPart = {
    part_number: number;
    etag: string;
};
