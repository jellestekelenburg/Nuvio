export type FolderPickerItem = {
    id: number;
    name: string;
    parent_id: number | null;
    is_root: boolean;
    has_children: boolean;
};

export type FolderBrowserLinks = {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
};

export type FolderBrowserMeta = {
    current_page: number;
    from: number | null;
    last_page: number;
    path: string;
    per_page: number;
    to: number | null;
    total: number;
};

export type FolderBrowserResponse = {
    data: FolderPickerItem[];
    current: FolderPickerItem;
    ancestors: FolderPickerItem[];
    links: FolderBrowserLinks;
    meta: FolderBrowserMeta;
};
