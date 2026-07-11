import type { UploadQueueItem } from './types';

export function createUploadQueue(files: FileList | File[]): UploadQueueItem[] {
    return Array.from(files).map((file) => ({
        client_id: crypto.randomUUID(),
        file,
        name: file.name,
        size: file.size,
        relative_path: file.webkitRelativePath || '',
        content_type: file.type || null,
        last_modified: file.lastModified || null,
        status: 'queued',
        progress: 0,
    }));
}
