export type File = {
    id: number;
    name: string;
    path: string | null;
    parent_id: number | null;
    is_folder: boolean;
    mime: string | null;
    size: string;
    owner: string;
    type: string;
    created_at: string | null;
    updated_at: string | null;
    created_by: number;
    updated_by: number;
    deleted_at: string | null;
    details: {
        owner: string | null;
        created_at: string | null;
        updated_at: string | null;
        created_by: string | null;
        updated_by: string | null;
    };
};
